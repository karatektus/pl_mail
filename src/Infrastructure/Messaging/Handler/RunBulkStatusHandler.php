<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Job\JobState;
use App\Entity\Job\BackgroundJob;
use App\Entity\Mail\MessageThread;
use App\Infrastructure\Messaging\Message\RunBulkStatusMessage;
use App\Repository\Job\BackgroundJobRepository;
use App\Service\Job\JobNotifier;
use App\Service\Mail\ListViewResolver;
use App\Service\Mail\ThreadStatusUpdater;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Apply a bulk status change to a whole view, outside the request.
 *
 * WHAT THIS REPLACES
 *
 * BulkStatusController did all of it inline: the view resolved to every thread,
 * every thread's messages hydrated, an ownership check per thread, then one
 * write per account. On a mailbox with five thousand unread that is
 * `Maximum execution time of 30 seconds exceeded` — the user gets a broken page
 * and no way to know how much of it happened.
 *
 * WHY IT CHUNKS
 *
 * Not for speed. A chunk is a unit of PROGRESS: the job's counter is flushed
 * after each one, so an indicator can show movement, and a worker killed
 * mid-run leaves the work it already did done and recorded rather than
 * half-applied and unaccounted for. Every chunk is also a fresh
 * EntityManager clear, which is what keeps a run over thousands of threads from
 * growing until it is killed for memory instead of time.
 *
 * WHY OWNERSHIP IS STILL CHECKED
 *
 * The view was resolved for this job's own user, so every thread in it is
 * theirs by construction. Checked anyway, per chunk, because that construction
 * is a query written somewhere else and this is the last place before a write.
 */
#[AsMessageHandler]
final readonly class RunBulkStatusHandler
{
    /**
     * Threads per chunk.
     *
     * Small enough that a flush is quick and progress moves visibly; large
     * enough that a five-thousand-thread job is fifty flushes rather than five
     * thousand. ThreadStatusUpdater resolves labels per account and is happiest
     * with a batch rather than a single row.
     */
    private const int CHUNK = 100;

    public function __construct(
        private BackgroundJobRepository $jobs,
        private ListViewResolver        $views,
        private ThreadStatusUpdater     $status,
        private JobNotifier             $notifier,
        private EntityManagerInterface  $em,
        private LoggerInterface         $logger,
    ) {
    }

    public function __invoke(RunBulkStatusMessage $message): void
    {
        $job = $this->jobs->find($message->jobId);

        if (null === $job) {
            // The user was deleted, or the job pruned. Nothing to do and
            // nothing wrong.
            return;
        }

        if (false === $job->isActive()) {
            // Redelivered after finishing. Doing it again would be a second
            // archive of mail the user has since moved back.
            return;
        }

        try {
            $this->run($job);
        } catch (Throwable $e) {
            $this->logger->error('RunBulkStatusHandler: bulk action failed', [
                'jobId' => $job->id,
                'kind'  => $job->kind->value,
                'error' => $e->getMessage(),
            ]);

            // Recorded on the job, not only logged: the person who started this
            // is watching an indicator, and a job that simply stops moving is
            // indistinguishable from a worker that died.
            $job->finish(JobState::Failed, $e->getMessage());
            $this->em->flush();
            $this->notifier->changed($job);

            throw $e;
        }
    }

    private function run(BackgroundJob $job): void
    {
        $threads = $this->views->threadsIn(
            $job->usr,
            (string) $job->view['scope'],
            (string) $job->view['value'],
            true === $job->view['unreadOnly'],
        );

        $job->state = JobState::Running;
        $job->total = count($threads);
        $this->em->flush();
        $this->notifier->changed($job);

        $action = $job->kind->action();
        $read   = $job->kind->readFlag();

        foreach (array_chunk($threads, self::CHUNK) as $chunk) {
            $this->apply($chunk, $action, $read, (int) $job->usr->id);

            // Re-read: the chunk above cleared the EntityManager, so the job in
            // hand is detached. Not re-reading here is how a progress counter
            // ends up written against a stale copy and silently lost.
            $fresh = $this->jobs->find($job->id);

            if (null === $fresh) {
                return;
            }

            $fresh->processed += count($chunk);
            $this->em->flush();
            $this->notifier->changed($fresh);

            $job = $fresh;
        }

        $job->finish(JobState::Done);
        $this->em->flush();
        $this->notifier->changed($job);
    }

    /**
     * One chunk, grouped by account.
     *
     * Grouped for the reason BulkStatusController gives: ThreadStatusUpdater
     * resolves the destination label and its folder from the first message's
     * account, which is right for one conversation and wrong for a selection
     * spanning two mailboxes — everything would be filed into the first
     * account's Archive.
     *
     * @param list<MessageThread> $threads
     */
    private function apply(array $threads, string $action, bool $read, int $userId): void
    {
        $byAccount = [];

        foreach ($threads as $thread) {
            // The last check before a write. The resolver selected these for
            // this user, but that is a query in another class.
            if ((int) ($thread->account?->usr->id ?? 0) !== $userId) {
                continue;
            }

            foreach ($thread->messages as $message) {
                $byAccount[(int) $message->account?->id][] = $message;
            }
        }

        foreach ($byAccount as $messages) {
            match ($action) {
                'archive' => $this->status->archive($messages),
                'trash'   => $this->status->trash($messages),
                'restore' => $this->status->restore($messages),
                'read'    => $this->status->markRead($messages, $read),
                default   => throw new \LogicException(sprintf('Unknown bulk action "%s".', $action)),
            };
        }

        // The whole point of chunking: without this a run over thousands of
        // threads holds every entity it has ever touched and is killed for
        // memory rather than finishing.
        $this->em->clear();
    }
}
