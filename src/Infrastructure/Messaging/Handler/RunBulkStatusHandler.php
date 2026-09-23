<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Job\JobState;
use App\Entity\Job\BackgroundJob;
use App\Entity\Mail\MessageThread;
use App\Infrastructure\Messaging\Message\RunBulkStatusMessage;
use App\Repository\Job\BackgroundJobRepository;
use App\Repository\Mail\MessageThreadRepository;
use App\Service\Job\JobNotifier;
use App\Service\Mail\ListViewResolver;
use App\Service\Mail\ThreadSnoozeService;
use App\Service\Mail\ThreadStatusUpdater;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
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

    /**
     * How many times a chunk is re-applied after a lock collision.
     *
     * Three, because a deadlock is not a queue: PostgreSQL shoots one of the
     * two transactions the instant it sees the cycle, so a retry is competing
     * with whatever else is writing right now rather than waiting out a
     * backlog. Two attempts clear nearly all of them and the third is for the
     * unlucky case where the same pair collides twice. Beyond that the
     * collision is structural and should be read in the log, not hidden by a
     * longer loop.
     */
    private const int LOCK_ATTEMPTS = 3;

    /**
     * Microseconds before the retry, multiplied by the attempt.
     *
     * Small and not zero. Both transactions rolled back at the same instant,
     * so an immediate retry is the same race run again; a few tens of
     * milliseconds is enough for the survivor to commit and get out of the way,
     * and is invisible next to the flush itself.
     */
    private const int LOCK_BACKOFF_US = 40_000;

    public function __construct(
        private BackgroundJobRepository  $jobs,
        private MessageThreadRepository  $threads,
        private ListViewResolver        $views,
        private ThreadStatusUpdater     $status,
        private ThreadSnoozeService     $snooze,
        private JobNotifier             $notifier,
        private EntityManagerInterface  $em,
        private ManagerRegistry         $registry,
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
        } catch (RetryableException $e) {
            // THREE IN-PROCESS ATTEMPTS WERE NOT ENOUGH — see applyWithRetry()
            // for what those are and why a deadlock deserves them. Reaching
            // here means the collisions kept coming, which is bad luck rather
            // than a broken job: nothing is corrupt, the chunk that lost rolled
            // back whole, and the chunks before it are committed.
            //
            // So the job is left ACTIVE and the exception rethrown, which hands
            // it back to the transport. That is not merely "give up more
            // politely": redelivery is the only path that gets a fresh
            // EntityManager — Doctrine closes this one on a failed flush and
            // nothing after that point can write — and the `bulk` queue's retry
            // ladder is tuned for exactly this arrival, 2s out to a minute over
            // five attempts. Re-running re-resolves the view, so what it
            // repeats is only the work still left to do.
            //
            // Marking it Failed here instead, which is what the generic branch
            // below would do, ends the story: the redelivery finds a job that
            // is no longer active and returns without doing anything.
            $this->logger->warning('RunBulkStatusHandler: lock collisions outlasted the retries, handing the job back to the queue', [
                'jobId' => $message->jobId,
                'kind'  => $job->kind->value,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } catch (Throwable $e) {
            $this->logger->error('RunBulkStatusHandler: bulk action failed', [
                'jobId'     => $message->jobId,
                'kind'      => $job->kind->value,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            // Recorded on the job, not only logged: the person who started this
            // is watching an indicator, and a job that simply stops moving is
            // indistinguishable from a worker that died.
            //
            // RE-READ FIRST. run() clears the EntityManager once per chunk, so
            // the $job in hand is detached by the time anything throws — and
            // finish() on a detached entity flushes nothing at all. The failure
            // was logged and the indicator span forever, which is precisely the
            // outcome this block exists to prevent.
            //
            // NOT THROUGH A MANAGER DOCTRINE HAS SHUT, which is every failure
            // that came out of a flush: UnitOfWork::commit() closes it in a
            // `finally` before rethrowing. Writing through it then throws
            // EntityManagerClosed from inside this catch block — which replaces
            // the real error with one about Doctrine AND still leaves the
            // indicator spinning, so the block fails at the only job it has,
            // for the failures most likely to reach it.
            //
            // Reset rather than give up: the registry re-initialises the ghost
            // in place, so the repositories holding this manager are working
            // again on the next line. If even that will not write, say so and
            // leave the row to app:jobs:reap, which is what the staleness
            // window is for.
            if (false === $this->em->isOpen()) {
                $this->registry->resetManager();
            }

            if (false === $this->em->isOpen()) {
                $this->logger->error('RunBulkStatusHandler: entity manager closed, the job could not be marked failed', [
                    'jobId' => $message->jobId,
                ]);

                throw $e;
            }

            $failed = $this->jobs->find($message->jobId);

            if (null !== $failed) {
                $failed->finish(JobState::Failed, $e->getMessage());
                $this->em->flush();
                $this->notifier->changed($failed);
            }

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

        // begin() stamps lastProgressAt along with the total, and that stamp is
        // the point: resolving a whole view into threads is itself minutes of
        // work on a large mailbox, so without it a job would arrive here
        // already looking older than the staleness window and app:jobs:reap
        // would fail it for the time it spent working out what it was.
        $job->begin(count($threads));
        $this->em->flush();
        $this->notifier->changed($job);

        $action = $job->kind->action();
        $read   = $job->kind->readFlag();
        // Only a snooze job carries one; null there means wake. See
        // BulkStatusController::startJob().
        $until  = isset($job->view['until']) ? new DateTimeImmutable((string) $job->view['until']) : null;
        $userId = (int) $job->usr->id;
        $jobId  = (int) $job->id;

        // IDS, NOT ENTITIES, and this is the whole correctness argument for the
        // loop below. Every chunk ends in an EntityManager clear, which
        // detaches every object this list is holding — so from the second chunk
        // onward the old code was handing ThreadStatusUpdater detached threads,
        // and flushing one makes Doctrine read its MessageThread as a brand new
        // entity nobody persisted:
        //
        //     Multiple non-persisted new entities were found through the given
        //     association graph … App\Entity\Mail\Message#thread
        //
        // A bulk action over one chunk therefore worked and the same action over
        // two did not, which is the shape that survives a hurried test.
        // Scalars survive a clear; managed objects do not.
        $ids = array_map(static fn (MessageThread $thread): int => (int) $thread->id, $threads);

        unset($threads);

        $processed = 0;

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $this->applyWithRetry($chunk, $action, $read, $userId, $until);

            $processed += count($chunk);

            // Re-read for the same reason: the chunk above cleared the
            // EntityManager, so the job in hand is detached. Not re-reading here
            // is how a progress counter ends up written against a stale copy and
            // silently lost.
            $fresh = $this->jobs->find($jobId);

            if (null === $fresh) {
                return;
            }

            // Assigned rather than incremented, because $processed is counted
            // here and the row is re-read each time: += against a fresh read
            // would be adding this run's total to itself.
            //
            // Through advance() rather than by writing the counter, so the
            // progress stamp moves with it. That stamp is the ONLY evidence
            // this job is alive: app:jobs:reap reads it, and a chunk that
            // updated the number without it would be a job quietly reaped while
            // it was still working.
            $fresh->advance($processed);
            $this->em->flush();
            $this->notifier->changed($fresh);
        }

        $done = $this->jobs->find($jobId);

        if (null === $done) {
            return;
        }

        $done->finish(JobState::Done);
        $this->em->flush();
        $this->notifier->changed($done);
    }

    /**
     * One chunk, re-applied if the database shot it for taking locks out of turn.
     *
     * WHAT THIS IS FOR
     *
     *     SQLSTATE[40P01]: Deadlock detected … while updating tuple (496,11)
     *     in relation "message"
     *
     * Two transactions held a lock the other wanted. This run was updating the
     * messages of one thread while something else — another bulk action, a sync
     * writing remote flags onto mail this one is marking read — updated the
     * same rows in the opposite order, and PostgreSQL broke the cycle by
     * killing one of them. That is the database working, not failing: nothing
     * is corrupt, the killed transaction rolled back whole, and the statement
     * that lost will very likely win a moment later.
     *
     * Which is why it must not travel up as a failed job. It used to, and the
     * person who pressed "mark all read" got an error and a dead indicator for
     * a collision that would have cleared on its own.
     *
     * RESETTING IS NOT OPTIONAL. Doctrine closes the EntityManager on any
     * failed flush, so without this the retry would throw EntityManagerClosed
     * before it reached the database. The reset re-initialises the manager in
     * place — the repositories injected above keep working — and it is safe
     * here precisely because the transaction rolled back: there is no pending
     * work to lose, and apply() clears the manager at the end of every chunk
     * anyway.
     *
     * RetryableException rather than DeadlockException: DBAL's own marker for
     * "the same statement may simply work next time", which also covers a lock
     * wait that timed out. Anything else is a real failure and goes straight
     * up, unretried.
     *
     * @param list<int> $chunk
     */
    private function applyWithRetry(array $chunk, string $action, bool $read, int $userId, ?DateTimeImmutable $until = null): void
    {
        for ($attempt = 1; ; ++$attempt) {
            try {
                $this->apply($chunk, $action, $read, $userId, $until);

                return;
            } catch (RetryableException $e) {
                if ($attempt >= self::LOCK_ATTEMPTS) {
                    throw $e;
                }

                $this->logger->warning('RunBulkStatusHandler: lock collision, retrying the chunk', [
                    'attempt' => $attempt,
                    'threads' => count($chunk),
                    'error'   => $e->getMessage(),
                ]);

                if (false === $this->em->isOpen()) {
                    $this->registry->resetManager();
                }

                usleep(self::LOCK_BACKOFF_US * $attempt);
            }
        }
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
     * Takes IDS and loads the threads itself, because the caller's list does
     * not survive the clear at the foot of this method. See run().
     *
     * @param list<int> $threadIds
     */
    private function apply(array $threadIds, string $action, bool $read, int $userId, ?DateTimeImmutable $until = null): void
    {
        $byAccount = [];

        foreach ($this->threads->findBy(['id' => $threadIds]) as $thread) {
            // The last check before a write. The resolver selected these for
            // this user, but that is a query in another class.
            if ((int) ($thread->account?->usr->id ?? 0) !== $userId) {
                continue;
            }

            // Per conversation, through the service every other snooze uses;
            // it resolves the thread's own account and flushes as it goes.
            if ('snooze' === $action) {
                if (null === $until) {
                    $this->snooze->wake($thread);
                } else {
                    $this->snooze->snooze($thread, $until);
                }

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
