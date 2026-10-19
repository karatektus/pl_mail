<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Job\JobState;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Exception\LabelDeleteRefusedException;
use App\Entity\Job\BackgroundJob;
use App\Entity\Label\Label;
use App\Entity\Mail\Message;
use App\Infrastructure\Messaging\Message\DeleteLabelMessage;
use App\Repository\Job\BackgroundJobRepository;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Job\JobNotifier;
use App\Service\Label\LabelDeletion;
use App\Service\Label\LabelNotifier;
use App\Service\Label\RemoteLabelRemover;
use App\Service\Mail\MailChangeRecorder;
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
 * Delete a label that is a folder on a mail server: move its mail to where the
 * person chose, delete the folders on the server, then delete the label here.
 *
 * ## Why the label goes last
 *
 * Issue #40 was a label that came back after the next sync, because deleting
 * it here left its folder on the server and the sync met that folder as a new
 * one. Removing the label first and the folder later would reopen exactly that
 * window: any sync in between finds the folder, finds no label for it, and
 * makes one. So the label stays until every folder it was is gone — while it
 * exists, a sync that meets the folder binds to it instead of minting another.
 *
 * ## Two phases
 *
 * PHASE_MOVE files every message in the label's folders under the destination,
 * through ThreadStatusUpdater::move() — the operation a drop onto a folder
 * uses, so a move here means on each provider exactly what it means there. It
 * is chunked and clears the EntityManager per chunk, for RunBulkStatusHandler's
 * reasons: a folder can hold thousands of messages.
 *
 * Those moves reach the server as jobs on the export queue, so when the move
 * phase ends the folders are only ASKED to be empty. PHASE_REMOTE therefore
 * runs on a delay and asks the server; RemoteLabelRemover deletes a folder
 * only when the server says it is empty. Until then the phase sends itself
 * again, later, up to MAX_REMOTE_ATTEMPTS — and if the folder never empties
 * (mail the sync never imported, a move the server refused) the job fails
 * saying so, and the label and the folder both stay. Nothing here deletes mail
 * to make progress.
 *
 * While it waits, the job says which folder it is waiting for (`view.waitingOn`,
 * read by the jobs indicator): a bar standing full for minutes with nothing
 * beside it reads as a job that hung.
 *
 * A server that refuses the delete itself is not waited for. That is
 * LabelDeleteRefusedException, the server's answer rather than a passing
 * outage, and the job ends on it at once with the server's own words — it
 * used to sit through all forty attempts first.
 */
#[AsMessageHandler]
final readonly class DeleteLabelHandler
{
    /** Messages per chunk of the move — RunBulkStatusHandler's size. */
    private const int CHUNK = 100;

    /**
     * How long the server half waits before asking again.
     *
     * Long enough for the export queue to work through a few hundred moves,
     * short enough that an empty folder goes in about the time it takes to
     * notice it is gone.
     */
    private const int REMOTE_DELAY_MS = 15_000;

    /**
     * How many times the server half asks before it gives up — forty times
     * fifteen seconds, ten minutes. Each attempt stamps the job's progress, so
     * the reaper's fifteen-minute window never sees a job that is waiting as one
     * that died.
     */
    private const int MAX_REMOTE_ATTEMPTS = 40;

    public function __construct(
        private BackgroundJobRepository $jobs,
        private LabelRepository         $labels,
        private MessageRepository       $messages,
        private LabelDeletion           $deletion,
        private RemoteLabelRemover      $remover,
        private ThreadStatusUpdater     $status,
        private MailChangeRecorder      $changes,
        private LabelNotifier           $labelNotifier,
        private JobNotifier             $notifier,
        private MessageBusInterface     $bus,
        private UrlGeneratorInterface   $urls,
        private TranslatorInterface     $translator,
        private EntityManagerInterface  $em,
        private LoggerInterface         $logger,
    ) {
    }

    public function __invoke(DeleteLabelMessage $message): void
    {
        $job = $this->jobs->find($message->jobId);

        // Pruned, or redelivered after it finished: a second run would move
        // mail the person may have filed somewhere else since.
        if (null === $job || false === $job->isActive()) {
            return;
        }

        $label = $this->labels->find((int) ($job->view['labelId'] ?? 0));

        // Gone already — deleted by a second press, or with its user. What the
        // job was for has happened.
        if (null === $label || $label->usr?->id !== $job->usr->id) {
            $this->finish($message->jobId, JobState::Done);

            return;
        }

        try {
            if (DeleteLabelMessage::PHASE_MOVE === $message->phase) {
                $moved = $this->move($job, $label);

                if (0 < $moved) {
                    $this->markWaiting($message->jobId, $label->fullName);
                }

                // Straight on when nothing moved — empty folders have no queued
                // moves to wait for, and a person deleting one should not watch
                // a spinner for fifteen seconds for nothing.
                $this->bus->dispatch(
                    new DeleteLabelMessage($message->jobId, DeleteLabelMessage::PHASE_REMOTE),
                    0 === $moved ? [] : [new DelayStamp(self::REMOTE_DELAY_MS)],
                );

                return;
            }

            $this->removeRemotely($message);
        } catch (Throwable $e) {
            $this->logger->error('DeleteLabelHandler: deleting a label failed', [
                'jobId'     => $message->jobId,
                'phase'     => $message->phase,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            // Recorded on the job, as RunBulkStatusHandler does: the person who
            // pressed Delete is watching the indicator. Not rethrown — the
            // label is still here and so is its folder, and a redelivery would
            // run the moves again over mail the person can now see and may
            // already be filing somewhere else. Pressing Delete again is the
            // retry, and it asks where the mail should go.
            $this->finish($message->jobId, JobState::Failed, $e->getMessage());
        }
    }

    /**
     * File every message in the label's folders under the destination.
     *
     * @return int how many messages were moved
     */
    private function move(BackgroundJob $job, Label $label): int
    {
        $jobId         = (int) $job->id;
        $userId        = (int) $job->usr->id;
        $locale        = $job->usr->locale;
        $destinationId = isset($job->view['destinationId']) ? (int) $job->view['destinationId'] : null;

        // Pairs of scalars, because the chunk loop clears the EntityManager and
        // the bindings in hand would not survive it.
        $folders = array_map(
            static fn ($binding): array => [(int) $binding->account?->id, (int) $binding->label?->id],
            $this->deletion->folderBindings($label),
        );

        $total = $this->deletion->mailInFolders($label);

        $job->begin($total);
        $this->em->flush();
        $this->notifier->changed($job);

        if (0 < $total && null !== $destinationId) {
            $this->prepareDestinations($label, $destinationId, $locale);
        }

        $processed = 0;

        foreach ($folders as [$accountId, $labelId]) {
            $afterId = 0;

            while (true) {
                $ids = $this->messages->findAllIdsWithLabelForAccount($accountId, $labelId, $afterId, self::CHUNK);

                if ([] === $ids) {
                    break;
                }

                $afterId = (int) end($ids);

                // Mail in a folder the dialog saw empty: it arrived while the
                // job waited, and nobody was asked where it should go.
                if (null === $destinationId) {
                    throw new \RuntimeException($this->say($locale, 'label.delete.failed_new_mail'));
                }

                // Checked here, not only in the controller: the job waited in
                // a queue, and a destination deleted or handed to somebody else
                // since is a move to nowhere. Re-read per chunk, because the
                // clear at the foot of the loop detaches it.
                $destination = $this->labels->find($destinationId);

                if (null === $destination || $destination->usr?->id !== $userId) {
                    throw new \RuntimeException($this->say($locale, 'label.delete.failed_destination_gone'));
                }

                /** @var list<Message> $chunk */
                $chunk = $this->messages->findBy(['id' => $ids]);

                if ([] !== $chunk) {
                    $this->status->move($chunk, $destination);
                }

                $processed += count($ids);

                $this->em->clear();

                $fresh = $this->jobs->find($jobId);

                if (null === $fresh) {
                    return $processed;
                }

                $fresh->advance($processed);
                $this->em->flush();
                $this->notifier->changed($fresh);
            }
        }

        return $processed;
    }

    /**
     * Create, before anything moves, the system folder each IMAP account's
     * mail is about to be filed into — see RemoteLabelRemover::prepareDestination()
     * for what goes wrong without it.
     *
     * Which folder that is depends on the account. Archive and Trash are
     * themselves. A label of the user's own that is a folder on this account
     * needs nothing; one that is not is a tag here, and taking mail out of the
     * folder it was in and into a tag is the archive (LabelChangePropagator),
     * so Archive it is. The Inbox exists on every IMAP server.
     */
    private function prepareDestinations(Label $label, int $destinationId, ?string $locale): void
    {
        $destination = $this->labels->find($destinationId);

        if (null === $destination) {
            return;
        }

        $prepared = [];

        foreach ($this->deletion->folderBindings($label) as $binding) {
            $account = $binding->account;

            if (null === $account || null === $binding->mailbox || isset($prepared[$account->id])) {
                continue;
            }

            $prepared[$account->id] = true;

            $role = match (true) {
                null !== $destination->role                                  => $destination->role,
                null !== $destination->bindingFor($account)?->mailbox        => null,
                default                                                      => LabelRole::Archive,
            };

            if (null === $role) {
                continue;
            }

            if (false === $this->remover->prepareDestination($account, $role)) {
                throw new \RuntimeException($this->say($locale, 'label.delete.failed_destination_create', [
                    '%name%' => $this->translator->trans('sidebar.nav.' . $role->value, [], null, $locale),
                ]));
            }
        }
    }

    /**
     * Delete the folders on the server, children first, and the label here
     * once nothing of it is left there.
     */
    private function removeRemotely(DeleteLabelMessage $message): void
    {
        $job   = $this->jobs->find($message->jobId);
        $label = $this->labels->find((int) ($job?->view['labelId'] ?? 0));

        if (null === $job || null === $label) {
            return;
        }

        /** @var list<string> $waiting full names of the labels still on the server */
        $waiting = [];
        $error   = null;

        // Every binding in the subtree, not only the folders: a nested label
        // that is a Gmail label on another account goes with its parent, and
        // left behind it would come back on the next Gmail sync as surely as
        // the folder did.
        foreach ($this->deletion->subtree($label) as $member) {
            foreach ($member->bindings as $binding) {
                try {
                    if (false === $this->remover->remove($binding)) {
                        $waiting[] = $member->fullName;
                    }
                } catch (LabelDeleteRefusedException $e) {
                    $this->logger->warning('DeleteLabelHandler: the server refused to delete a folder, ending the job', [
                        'jobId'     => $message->jobId,
                        'label'     => $e->folder,
                        'error'     => $e->getMessage(),
                        'exception' => $e,
                    ]);

                    // Ended now, and the label kept: whatever was deleted on
                    // the server before this is gone, and the label is what
                    // shows the person what is left.
                    $this->finish($message->jobId, JobState::Failed, $this->say(
                        $job->usr->locale,
                        'label.delete.failed_server',
                        ['%name%' => $e->folder, '%error%' => $e->getMessage()],
                    ));

                    return;
                } catch (Throwable $e) {
                    // A server that could not be reached, or asked to slow down
                    // — anything short of an answer to the delete. Waited out
                    // like a folder that is not empty yet, since most of these
                    // work the next time, and named in the job's failure if it
                    // never does.
                    $this->logger->warning('DeleteLabelHandler: the server could not be asked about part of a label delete, will ask again', [
                        'jobId'     => $message->jobId,
                        'label'     => $member->fullName,
                        'error'     => $e->getMessage(),
                        'exception' => $e,
                    ]);

                    $waiting[] = $member->fullName;
                    $error   ??= $e->getMessage();
                }
            }
        }

        if ([] !== $waiting) {
            $this->waitOrGiveUp($message, $job, $waiting[0], $error);

            return;
        }

        $user = $job->usr;

        // JMAP clients are told before the rows go: the change recorder reads
        // the bindings, and the cascade below removes them.
        foreach ($this->deletion->subtree($label) as $member) {
            $this->changes->labelDeleted($member);
        }

        // The parent FK cascades: nested labels, their bindings and the
        // message_label / thread_label rows go with it. The messages stay —
        // they are in the destination by now.
        $this->em->remove($label);
        $this->em->flush();

        // Cleared before anything else is written. The cascade above happened
        // in the database, and the nested labels and the folders this run
        // loaded are still managed here, pointing at rows that are gone — so
        // the very next flush, the one that finishes the job, would find them
        // and refuse: "a new entity was found through Label#parent". The
        // labels were deleted and the job stayed queued for ever.
        $this->em->clear();

        $this->finish($message->jobId, JobState::Done);

        // The open tabs re-read their label lists. They cannot be sent the
        // markup, as LabelController sends it: the sidebar is rendered for the
        // signed-in user and a worker has none. See LabelNotifier.
        $this->labelNotifier->publishLabelsStale(
            $user,
            $this->urls->generate('app_label_lists'),
        );
    }

    private function waitOrGiveUp(DeleteLabelMessage $message, BackgroundJob $job, string $folder, ?string $error): void
    {
        if ($message->attempt + 1 >= self::MAX_REMOTE_ATTEMPTS) {
            $this->logger->warning('DeleteLabelHandler: a folder never emptied on the server, giving up', [
                'jobId'  => $message->jobId,
                'folder' => $folder,
            ]);

            $this->finish(
                $message->jobId,
                JobState::Failed,
                $this->say(
                    $job->usr->locale,
                    null === $error ? 'label.delete.failed_not_empty' : 'label.delete.failed_server',
                    ['%name%' => $folder, '%error%' => (string) $error],
                ),
            );

            return;
        }

        // A stamp on the job, or the reaper would read ten minutes of waiting
        // as a worker that died — and the folder it is waiting for, for the
        // person reading the indicator.
        $job->advance($job->processed);
        $this->em->flush();

        $this->markWaiting($message->jobId, $folder);

        $this->bus->dispatch(
            new DeleteLabelMessage($message->jobId, DeleteLabelMessage::PHASE_REMOTE, $message->attempt + 1),
            [new DelayStamp(self::REMOTE_DELAY_MS)],
        );
    }

    /**
     * Record on the job which folder it is waiting for the server to empty,
     * and tell the indicator to redraw.
     *
     * On the job's view rather than a column of its own: it is read by one
     * template for one kind of job, and the view is already the place a job
     * keeps what is particular to its kind (a move's target, a snooze's time).
     */
    private function markWaiting(int $jobId, string $folder): void
    {
        $job = $this->jobs->find($jobId);

        if (null === $job || false === $job->isActive()) {
            return;
        }

        // A new array, not an element written into the old one: Doctrine
        // compares a json column by value, and this is the change it sees.
        $job->view = [...$job->view, 'waitingOn' => $folder];
        $this->em->flush();
        $this->notifier->changed($job);
    }

    /**
     * A failure reason, in the language of the person who will read it — the
     * job's owner, not whoever the worker happens to be.
     *
     * Takes the locale rather than the job: the move phase clears the
     * EntityManager per chunk, and a detached job's user is not something to
     * go reading a column off.
     *
     * @param array<string, string> $parameters
     */
    private function say(?string $locale, string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, null, $locale);
    }

    /**
     * End the job, through a fresh read: the move phase clears the
     * EntityManager, so any job object held from before is detached.
     */
    private function finish(int $jobId, JobState $state, ?string $reason = null): void
    {
        if (false === $this->em->isOpen()) {
            $this->logger->error('DeleteLabelHandler: entity manager closed, the job could not be finished', [
                'jobId' => $jobId,
                'state' => $state->value,
            ]);

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
