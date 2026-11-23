<?php

declare(strict_types=1);

namespace App\Service\Task;

use App\Domain\DTO\Task\TaskTransfer;
use App\Domain\Exception\CalendarSyncException;
use App\Entity\Task\Task;
use App\Entity\Task\TaskList;
use App\Entity\User\User;
use App\Repository\Task\TaskRepository;
use App\Service\Task\Sync\TaskSyncService;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

/**
 * Takes a synced list's tasks into plMail for good, before the server they came
 * from goes away. Microsoft retires the To Do apps on 30 June 2027, with no
 * replacement for personal accounts, and a CalDAV server can be switched off just
 * the same. See issue #75.
 *
 * Every task moves to a local list: open and finished, with its steps, its
 * finished entries, its reminder and its files. Then the synced list is
 * unsubscribed. **Nothing is deleted on the server.** That is where this differs
 * from TaskWriter::move(), which leaves a stand-in behind so the remote copy is
 * deleted: here the remote copy is the way back, and a list of thousands is the
 * wrong place to find out that a delete reached further than meant.
 *
 * Done in SQL rather than through the entities. The To Do account tried during
 * the work had about 6,500 tasks, nearly all finished. Hydrating them all to
 * change four columns is the slow way to run two statements.
 *
 * What only the server understood goes: a local task keeps no remote copy to
 * merge into, which is what TaskList::isManagedLocally() means.
 */
final readonly class TaskListTransfer
{
    public function __construct(
        private TaskSyncService        $sync,
        private TaskRepository         $tasks,
        private EntityManagerInterface $em,
    ) {}

    /**
     * @param TaskList|null $to   a local, writable list of the same user, or null for a new one in the synced list's place
     * @param bool          $pull false to take what is here without asking the server first, which is the only way left once it is gone
     *
     * @throws CalendarSyncException the last pull failed, and nothing has moved
     */
    public function transfer(TaskList $from, ?TaskList $to, bool $pull = true): TaskTransfer
    {
        if (false === $from->isSynced()) {
            throw new InvalidArgumentException('Only a synced list is transferred.');
        }

        if (null !== $to && ($to->usr !== $from->usr || false === $to->isManagedLocally() || true === $to->isReadOnly)) {
            throw new InvalidArgumentException('Tasks are transferred to a local list of the same user that can take them.');
        }

        // Changes made in other apps since the last sync, and the ones made here
        // that are still on their way out. A list whose account has its Tasks
        // switch off is passed over by sync(), and what is here is what moves.
        if (true === $pull) {
            $this->sync->sync($from);
        }

        $user  = $from->usr;
        $moved = $this->tasks->countForList($user, $from, false) + $this->tasks->countForList($user, $from, true);
        $to ??= $this->newListIn($from);

        // For the new list's id.
        $this->em->flush();

        // Where new tasks land follows the tasks rather than falling back to the first list.
        if ($user->tasksAddToListId === $from->id) {
            $user->setSetting(User::SETTING_TASKS_ADD_TO, $to->id);
        }

        $this->em->flush();

        $this->em->wrapInTransaction(function () use ($from, $to): void {
            $this->tasks->moveAllToLocalList($from, $to);

            // The rows changed under the entities the pull loaded. Let go of
            // them, so the flush that ends this does not write their old list back.
            foreach ($this->em->getUnitOfWork()->getIdentityMap()[Task::class] ?? [] as $task) {
                if ($task instanceof Task && $task->taskList === $from) {
                    $this->em->detach($task);
                }
            }

            // Unsubscribed: the list itself, empty by now.
            $this->em->remove($from);
        });

        return new TaskTransfer($to, $moved);
    }

    /** Named and coloured like the synced list, in its place in the order. */
    private function newListIn(TaskList $from): TaskList
    {
        $list            = new TaskList();
        $list->usr       = $from->usr;
        $list->name      = $from->name;
        $list->color     = $from->color;
        $list->sortOrder = $from->sortOrder;

        $this->em->persist($list);

        return $list;
    }
}
