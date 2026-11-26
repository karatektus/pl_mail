<?php

declare(strict_types=1);

namespace App\Service\Task\Sync;

use App\Domain\Enum\Calendar\SyncState;
use App\Domain\Exception\CalendarResyncRequiredException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Interface\TaskSyncDriverInterface;
use App\Entity\Task\Task;
use App\Entity\Task\TaskList;
use App\Repository\Task\TaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Log\LoggerInterface;

/**
 * Sending a list's locally changed tasks to its remote.
 *
 * CalendarPusher's rules, whose docblocks say why each is what it is: only a
 * permanent refusal of one row is swallowed, a 412 is about that row and leaves it
 * pending for the pull to settle, and anything else stops the run.
 *
 * Does not flush — it joins the caller's unit of work.
 */
final readonly class TaskPusher
{
    public function __construct(
        private TaskRepository         $tasks,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {}

    /** @return int how many tasks the remote accepted */
    public function push(TaskList $list, TaskSyncDriverInterface $driver): int
    {
        if (true === $list->isReadOnly) {
            throw new LogicException(sprintf('Task list #%d is read-only and must never be pushed to.', (int) $list->id));
        }

        $pushed = 0;

        foreach ($this->tasks->findPendingSync($list) as $task) {
            try {
                $this->pushOne($list, $driver, $task);
                ++$pushed;
            } catch (CalendarSyncPermanentException $e) {
                $this->abandon($task, $e->getMessage());
            } catch (CalendarResyncRequiredException $e) {
                if (412 !== $e->getStatus()) {
                    throw $e;
                }

                $this->logger->info('TaskSync: the remote changed a task since it was read, pulling before pushing again', [
                    'listId' => $list->id,
                    'taskId' => $task->id,
                ]);
            }
        }

        return $pushed;
    }

    private function pushOne(TaskList $list, TaskSyncDriverInterface $driver, Task $task): void
    {
        if (SyncState::PendingDelete === $task->syncState) {
            $driver->delete($list, $task);
            $this->em->remove($task);

            return;
        }

        $result = $driver->push($list, $task);

        // All three even on an update: a provider that re-keys on edit would
        // otherwise be a stranger to the next pull, and the payload is what the
        // next edit is built on.
        $task->remoteId      = $result->remoteId;
        $task->remoteEtag    = $result->etag;
        $task->remotePayload = $result->payload ?? $task->remotePayload;
        $task->syncState     = SyncState::Clean;
    }

    /**
     * Left Clean rather than pending, as CalendarPusher::abandon() leaves an
     * event: a queue that retries the impossible eventually retries nothing else.
     * The fields go in the log because this is when the edit stops being one that
     * will ever leave.
     */
    private function abandon(Task $task, string $reason): void
    {
        $this->logger->error('TaskSync: the remote refused a task permanently, giving up on it', [
            'listId'    => $task->taskList->id,
            'taskId'    => $task->id,
            'uid'       => $task->uid,
            'syncState' => $task->syncState->value,
            'title'     => $task->title,
            'reason'    => $reason,
        ]);

        $task->syncState = SyncState::Clean;
    }
}
