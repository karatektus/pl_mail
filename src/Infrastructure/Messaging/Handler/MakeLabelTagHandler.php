<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Job\JobState;
use App\Domain\Exception\LabelDeleteRefusedException;
use App\Entity\Mail\Message;
use App\Infrastructure\Messaging\Message\MakeLabelTagMessage;
use App\Repository\Job\BackgroundJobRepository;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Job\JobNotifier;
use App\Service\Label\LabelNotifier;
use App\Service\Label\RemoteLabelRemover;
use App\Service\Mail\ThreadStatusUpdater;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * A label stops being a folder on one account and becomes a tag there (#221):
 * the mail moves out, the folder goes, and the mail gets the label back.
 *
 * ## Why the label comes off and goes back on
 *
 * While the folder exists, a message wearing the label says it is in that
 * folder. On Outlook the next push would believe it and move the mail straight
 * back. So the move takes the label off, the ids are kept on the job
 * (`view.movedIds`), and the label returns once the binding has no folder left,
 * when attaching it means a tag and moves nothing.
 *
 * ## If the folder never empties
 *
 * As in DeleteLabelHandler, nothing is deleted to make progress: the job fails
 * saying so and the folder stays. The moved mail is safe where the person sent
 * it, without the label, and the failure says that too.
 */
#[AsMessageHandler]
final readonly class MakeLabelTagHandler
{
    private const int CHUNK = 100;
    private const int REMOTE_DELAY_MS = 15_000;
    private const int MAX_REMOTE_ATTEMPTS = 40;

    public function __construct(
        private BackgroundJobRepository $jobs,
        private LabelRepository         $labels,
        private AccountRepository       $accounts,
        private MessageRepository       $messages,
        private RemoteLabelRemover      $remover,
        private ThreadStatusUpdater     $status,
        private LabelNotifier           $labelNotifier,
        private JobNotifier             $notifier,
        private MessageBusInterface     $bus,
        private UrlGeneratorInterface   $urls,
        private TranslatorInterface     $translator,
        private EntityManagerInterface  $em,
        private LoggerInterface         $logger,
    ) {
    }

    public function __invoke(MakeLabelTagMessage $message): void
    {
        $job = $this->jobs->find($message->jobId);

        if (null === $job || false === $job->isActive()) {
            return;
        }

        try {
            if (MakeLabelTagMessage::PHASE_MOVE === $message->phase) {
                $moved = $this->move($message->jobId);

                $this->bus->dispatch(
                    new MakeLabelTagMessage($message->jobId, MakeLabelTagMessage::PHASE_REMOTE),
                    0 === $moved ? [] : [new DelayStamp(self::REMOTE_DELAY_MS)],
                );

                return;
            }

            $this->removeFolder($message);
        } catch (Throwable $e) {
            $this->logger->error('MakeLabelTagHandler: turning a folder into a label failed', [
                'jobId'     => $message->jobId,
                'phase'     => $message->phase,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            $this->fail($message->jobId, $e->getMessage());
        }
    }

    /**
     * @return int how many messages were moved
     */
    private function move(int $jobId): int
    {
        $job = $this->jobs->find($jobId);

        if (null === $job) {
            return 0;
        }

        $labelId       = (int) ($job->view['labelId'] ?? 0);
        $accountId     = (int) ($job->view['accountId'] ?? 0);
        $destinationId = (int) ($job->view['destinationId'] ?? 0);
        $account       = $this->accounts->find($accountId);
        $destination   = $this->labels->find($destinationId);

        if (null === $account || null === $destination) {
            throw new \RuntimeException($this->translator->trans('label.delete.failed_destination_gone', [], null, $job->usr->locale));
        }

        $job->begin($this->messages->countToFileIntoFolder($accountId, $labelId));
        $this->em->flush();
        $this->notifier->changed($job);

        if (null !== $destination->role && false === $this->remover->prepareDestination($account, $destination->role)) {
            throw new \RuntimeException($this->translator->trans('label.delete.failed_destination_create', [
                '%name%' => $this->translator->trans('sidebar.nav.' . $destination->role->value, [], null, $job->usr->locale),
            ], null, $job->usr->locale));
        }

        /** @var list<int> $moved */
        $moved = [];

        while (true) {
            $ids = $this->messages->findIdsToFileIntoFolder($accountId, $labelId, 0, self::CHUNK);

            if ([] === $ids) {
                return count($moved);
            }

            $destination = $this->labels->find($destinationId);
            $label       = $this->labels->find($labelId);

            if (null === $destination || null === $label) {
                throw new \RuntimeException('The label or the destination went away during the move.');
            }

            /** @var list<Message> $chunk */
            $chunk = $this->messages->findBy(['id' => $ids]);

            $this->status->move($chunk, $destination);

            // A move to a system place takes off the folders it knows of; this
            // label is coming off whatever the move decided, and the loop reads
            // "still wearing it" as "still to do".
            foreach ($chunk as $message) {
                $message->removeLabel($label);
            }

            $this->em->flush();

            $moved = [...$moved, ...$ids];

            $this->em->clear();

            $fresh = $this->jobs->find($jobId);

            if (null === $fresh) {
                return count($moved);
            }

            $fresh->view = [...$fresh->view, 'movedIds' => $moved];
            $fresh->advance(count($moved));
            $this->em->flush();
            $this->notifier->changed($fresh);
        }
    }

    private function removeFolder(MakeLabelTagMessage $message): void
    {
        $job = $this->jobs->find($message->jobId);

        if (null === $job) {
            return;
        }

        $label   = $this->labels->find((int) ($job->view['labelId'] ?? 0));
        $account = $this->accounts->find((int) ($job->view['accountId'] ?? 0));
        $binding = null === $account ? null : $label?->bindingFor($account);

        if (null === $label || null === $binding) {
            $this->fail($message->jobId, $this->translator->trans('label.delete.failed_destination_gone', [], null, $job->usr->locale));

            return;
        }

        $name  = $label->fullName;
        $error = null;

        try {
            $empty = $this->remover->remove($binding);
        } catch (LabelDeleteRefusedException $e) {
            $this->fail($message->jobId, $this->translator->trans(
                'label.delete.failed_server',
                ['%name%' => $e->folder, '%error%' => $e->getMessage()],
                null,
                $job->usr->locale,
            ));

            return;
        } catch (Throwable $e) {
            // A server out of reach is waited out, like a folder not empty yet.
            $empty = false;
            $error = $e->getMessage();
        }

        if (false === $empty) {
            $this->waitOrGiveUp($message, $name, $error);

            return;
        }

        $this->labelBack($message->jobId);
    }

    private function waitOrGiveUp(MakeLabelTagMessage $message, string $folder, ?string $error): void
    {
        $job = $this->jobs->find($message->jobId);

        if (null === $job) {
            return;
        }

        if ($message->attempt + 1 >= self::MAX_REMOTE_ATTEMPTS) {
            $this->fail($message->jobId, $this->translator->trans(
                null === $error ? 'label.delete.failed_not_empty' : 'label.delete.failed_server',
                ['%name%' => $folder, '%error%' => (string) $error],
                null,
                $job->usr->locale,
            ));

            return;
        }

        // A stamp for the reaper, and the folder for the indicator.
        $job->advance($job->processed);
        $job->view = [...$job->view, 'waitingOn' => $folder];
        $this->em->flush();
        $this->notifier->changed($job);

        $this->bus->dispatch(
            new MakeLabelTagMessage($message->jobId, MakeLabelTagMessage::PHASE_REMOTE, $message->attempt + 1),
            [new DelayStamp(self::REMOTE_DELAY_MS)],
        );
    }

    /**
     * The folder is gone, so the label is a tag on this account now: attaching
     * it writes the keyword or the category and moves nothing.
     */
    private function labelBack(int $jobId): void
    {
        $job = $this->jobs->find($jobId);

        if (null === $job) {
            return;
        }

        $labelId = (int) ($job->view['labelId'] ?? 0);

        /** @var list<int> $moved */
        $moved = $job->view['movedIds'] ?? [];

        foreach (array_chunk($moved, self::CHUNK) as $ids) {
            $label = $this->labels->find($labelId);

            if (null === $label) {
                break;
            }

            /** @var list<Message> $chunk */
            $chunk = $this->messages->findBy(['id' => $ids]);

            if ([] !== $chunk) {
                $this->status->applyLabel($chunk, $label, true);
            }

            $this->em->flush();
            $this->em->clear();
        }

        $job = $this->jobs->find($jobId);

        if (null === $job) {
            return;
        }

        $job->view = array_diff_key($job->view, ['movedIds' => true, 'waitingOn' => true]);

        $job->finish(JobState::Done);
        $this->em->flush();
        $this->notifier->changed($job);

        $this->labelNotifier->publishLabelsStale($job->usr, $this->urls->generate('app_label_lists'));
    }

    private function fail(int $jobId, string $reason): void
    {
        if (false === $this->em->isOpen()) {
            return;
        }

        $job = $this->jobs->find($jobId);

        if (null === $job || false === $job->isActive()) {
            return;
        }

        if ([] !== ($job->view['movedIds'] ?? [])) {
            $reason .= ' ' . $this->translator->trans('label.form.places.make_label_failed_moved', [], null, $job->usr->locale);
        }

        $job->finish(JobState::Failed, $reason);
        $this->em->flush();
        $this->notifier->changed($job);
    }
}
