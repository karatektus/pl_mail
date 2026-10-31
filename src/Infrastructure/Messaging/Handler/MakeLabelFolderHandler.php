<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Job\JobState;
use App\Entity\Mail\Message;
use App\Infrastructure\Messaging\Message\MakeLabelFolderMessage;
use App\Repository\Job\BackgroundJobRepository;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Job\JobNotifier;
use App\Service\Label\LabelNotifier;
use App\Service\Mail\GraphApiClient;
use App\Service\Mail\ThreadStatusUpdater;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;

/**
 * File the mail wearing a label into the folder it has just become (#221).
 *
 * A folder is where mail is, so mail wearing the label has to be in it, or the
 * label would claim a place the mail is not. The move is ThreadStatusUpdater's,
 * a drop onto the folder by another name, in chunks as DeleteLabelHandler does.
 *
 * On Outlook the category goes last. The move's own push takes it off each
 * message, because a folder label is no longer pushed as one, and the master
 * category would otherwise stay in Outlook's list with nothing wearing it.
 */
#[AsMessageHandler]
final readonly class MakeLabelFolderHandler
{
    private const int CHUNK = 100;

    public function __construct(
        private BackgroundJobRepository $jobs,
        private LabelRepository         $labels,
        private AccountRepository       $accounts,
        private MessageRepository       $messages,
        private ThreadStatusUpdater     $status,
        private GraphApiClient          $graph,
        private LabelNotifier           $labelNotifier,
        private JobNotifier             $notifier,
        private UrlGeneratorInterface   $urls,
        private EntityManagerInterface  $em,
        private LoggerInterface         $logger,
    ) {
    }

    public function __invoke(MakeLabelFolderMessage $message): void
    {
        $job = $this->jobs->find($message->jobId);

        if (null === $job || false === $job->isActive()) {
            return;
        }

        $labelId   = (int) ($job->view['labelId'] ?? 0);
        $accountId = (int) ($job->view['accountId'] ?? 0);

        try {
            $this->fileMail($message->jobId, $labelId, $accountId);
            $this->removeCategory($labelId, $accountId);
            $this->finish($message->jobId, JobState::Done);
        } catch (Throwable $e) {
            $this->logger->error('MakeLabelFolderHandler: filing mail into the new folder failed', [
                'jobId'     => $message->jobId,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            // Not rethrown, for DeleteLabelHandler's reason: a redelivery would
            // move mail the person may already be filing somewhere else.
            $this->finish($message->jobId, JobState::Failed, $e->getMessage());
        }

        $job = $this->jobs->find($message->jobId);

        if (null !== $job) {
            $this->labelNotifier->publishLabelsStale($job->usr, $this->urls->generate('app_label_lists'));
        }
    }

    private function fileMail(int $jobId, int $labelId, int $accountId): void
    {
        $job = $this->jobs->find($jobId);

        if (null === $job) {
            return;
        }

        $job->begin($this->messages->countToFileIntoFolder($accountId, $labelId));
        $this->em->flush();
        $this->notifier->changed($job);

        $processed = 0;
        $afterId   = 0;

        while (true) {
            $ids = $this->messages->findIdsToFileIntoFolder($accountId, $labelId, $afterId, self::CHUNK);

            if ([] === $ids) {
                return;
            }

            $afterId = (int) end($ids);

            // Re-read per chunk: the clear at the foot of the loop detaches it.
            $label = $this->labels->find($labelId);

            if (null === $label) {
                return;
            }

            /** @var list<Message> $chunk */
            $chunk = $this->messages->findBy(['id' => $ids]);

            if ([] !== $chunk) {
                $this->status->move($chunk, $label);
            }

            $processed += count($ids);

            $this->em->clear();

            $fresh = $this->jobs->find($jobId);

            if (null === $fresh) {
                return;
            }

            $fresh->advance($processed);
            $this->em->flush();
            $this->notifier->changed($fresh);
        }
    }

    /**
     * Best effort: the mail is filed by now, and a category left in Outlook's
     * list is untidy rather than wrong.
     */
    private function removeCategory(int $labelId, int $accountId): void
    {
        $label   = $this->labels->find($labelId);
        $account = $this->accounts->find($accountId);
        $binding = null === $account ? null : $label?->bindingFor($account);

        if (null === $account || null === $binding?->graphCategoryId) {
            return;
        }

        try {
            $this->graph->deleteMasterCategory($account, $binding->graphCategoryId);

            $binding->graphCategoryId = null;
            $this->em->flush();
        } catch (Throwable $e) {
            $this->logger->warning('MakeLabelFolderHandler: could not remove the Outlook category', [
                'accountId' => $accountId,
                'labelId'   => $labelId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    private function finish(int $jobId, JobState $state, ?string $reason = null): void
    {
        if (false === $this->em->isOpen()) {
            return;
        }

        $job = $this->jobs->find($jobId);

        if (null === $job || false === $job->isActive()) {
            return;
        }

        $job->finish($state, $reason);
        $this->em->flush();
        $this->notifier->changed($job);
    }
}
