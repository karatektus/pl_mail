<?php

declare(strict_types=1);

namespace App\Service\Task\Sync;

use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\DTO\Task\RemoteTaskList;
use App\Domain\DTO\Task\TaskChangeSet;
use App\Domain\Exception\CalendarResyncRequiredException;
use App\Domain\Exception\CalendarSyncException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Interface\TaskSyncDriverInterface;
use App\Entity\Task\TaskList;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Bringing one task list and its remote back into agreement.
 *
 * The sequence is CalendarSyncService's, and its docblock is where the reasoning
 * lives: push before pull, so the pull asks a remote that has already been told;
 * one dead token costs one full read; a failure is recorded where a person can see
 * it and backed off from.
 */
final readonly class TaskSyncService
{
    /** A remote that rejects every token forever must not loop inside a handler. */
    private const int MAX_RESYNCS = 2;

    public function __construct(
        private TaskSyncDriverRegistry $drivers,
        private TaskPusher             $pusher,
        private TaskPuller             $puller,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {}

    /**
     * @return list<RemoteTaskList>
     *
     * @throws CalendarSyncException
     */
    public function discover(CalendarSource $source): array
    {
        return $this->drivers->for($source)->discover($source);
    }

    /**
     * @return int how many tasks this run created, changed or removed
     *
     * @throws CalendarSyncException
     */
    public function sync(TaskList $list): int
    {
        // Switched off: nothing goes out and nothing is read. A change made here
        // meanwhile stays pending and leaves when it is on again.
        if (null !== $list->account && false === $list->account->tasksEnabled) {
            return 0;
        }

        try {
            $touched = $this->run($list, $this->drivers->forList($list));
        } catch (Throwable $e) {
            $this->recordFailure($list, $e);

            throw $e;
        }

        $list->recordSyncSuccess();
        $this->em->flush();

        return $touched;
    }

    private function run(TaskList $list, TaskSyncDriverInterface $driver): int
    {
        $touched = 0;

        if (false === $list->isReadOnly) {
            $touched += $this->pusher->push($list, $driver);

            // Before the pull reads the table: a create comes back in this same
            // run's pull, and with its id unwritten it would be inserted twice.
            $this->em->flush();
        }

        $token   = $list->syncToken;
        $resyncs = 0;

        while (true) {
            $changes = $this->pullOnce($driver, $list, $token);

            if (false === $changes->requiresFullResync) {
                return $touched + $this->puller->apply($list, $changes, null === $token);
            }

            if (self::MAX_RESYNCS <= $resyncs) {
                throw new CalendarSyncPermanentException('The server keeps asking for a full resync and never accepts the result.');
            }

            ++$resyncs;

            $list->syncToken = null;
            $token           = null;

            $this->logger->info('TaskSync: sync token expired, reading the list from scratch', ['listId' => $list->id]);
        }
    }

    private function pullOnce(TaskSyncDriverInterface $driver, TaskList $list, ?string $token): TaskChangeSet
    {
        try {
            return $driver->pull($list, $token);
        } catch (CalendarResyncRequiredException) {
            return TaskChangeSet::resyncRequired();
        }
    }

    /**
     * The manager may already be closed — a failed flush is one way here — and
     * then nothing can be written through it, so that case is logged instead.
     */
    private function recordFailure(TaskList $list, Throwable $e): void
    {
        $list->recordSyncFailure($e->getMessage());

        if (false === $this->em->isOpen()) {
            $this->logger->error('TaskSync: sync failed and the error could not be recorded', [
                'listId' => $list->id,
                'error'  => $e->getMessage(),
            ]);

            return;
        }

        try {
            $this->em->flush();
        } catch (Throwable $inner) {
            $this->logger->error('TaskSync: could not record the sync failure', [
                'listId' => $list->id,
                'error'  => $inner->getMessage(),
            ]);
        }
    }
}
