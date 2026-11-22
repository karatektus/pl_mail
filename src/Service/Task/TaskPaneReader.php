<?php

declare(strict_types=1);

namespace App\Service\Task;

use App\Domain\DTO\Task\TaskPane;
use App\Domain\DTO\Task\TaskPaneQuery;
use App\Domain\Enum\Task\TaskView;
use App\Entity\Task\Task;
use App\Entity\Task\TaskList;
use App\Entity\User\User;
use App\Repository\Task\TaskListRepository;
use App\Repository\Task\TaskRepository;
use App\Service\Calendar\CalendarTimeResolver;
use App\Service\Task\Sync\TaskSyncSources;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Works out what the pane shows, in the reader's own day.
 *
 * "Today" is the one question here with a wrong answer available: the container
 * runs on UTC, so asking PHP for today would move the boundary of My Day to
 * wherever Greenwich's midnight falls. It is always the user's zone, resolved
 * once and handed to the repository in both the forms its columns need.
 */
final readonly class TaskPaneReader
{
    public function __construct(
        private TaskRepository       $tasks,
        private TaskListRepository   $lists,
        private TaskListProvisioner  $provisioner,
        private CalendarTimeResolver $time,
        private TaskSyncSources      $sources,
    ) {}

    public function read(User $user, TaskPaneQuery $query, ?DateTimeImmutable $now = null): TaskPane
    {
        $now     = $now ?? new DateTimeImmutable();
        $zone    = $this->time->zoneFor($user);
        $lists   = $this->lists->findForUser($user);
        $list    = null === $query->listId ? null : $this->lists->findOwned($user, $query->listId);

        // Writable, or where new tasks land: quick add has to land somewhere it can.
        $addTo = null !== $list && false === $list->isReadOnly ? $list : $this->provisioner->landingFor($user);

        $sections = TaskView::MyDay === $query->view
            ? $this->myDaySections($user, $now, $zone)
            : $this->listSections($user, $list, $now, $zone);

        return new TaskPane(
            $query,
            array_filter($sections, static fn (array $tasks): bool => [] !== $tasks),
            $lists,
            $list,
            $addTo,
            $now,
            $zone,
            [] !== $this->sources->forUser($user),
        );
    }

    /** @return array<string, list<Task>> */
    private function myDaySections(User $user, DateTimeImmutable $now, DateTimeZone $zone): array
    {
        $day   = $now->setTimezone($zone)->setTime(0, 0);
        $today = $day->format('Y-m-d');
        $found = $this->tasks->findForMyDay(
            $user,
            $day->setTimezone(new DateTimeZone('UTC')),
            $day->modify('+1 day')->setTimezone(new DateTimeZone('UTC')),
            $today,
        );

        $sections = ['overdue' => [], 'today' => [], 'completed' => []];

        foreach ($found as $task) {
            $dueDay = $task->dueDay($zone);

            if (false === $task->status->isOpen()) {
                $sections['completed'][] = $task;
            } elseif (null !== $dueDay && $dueDay < $today) {
                // By the day rather than isOverdue(), which also says yes to
                // this morning's: that one is still today's work, in today.
                $sections['overdue'][] = $task;
            } else {
                $sections['today'][] = $task;
            }
        }

        return $sections;
    }

    /**
     * Everything on the list at once, open and finished, each in its group: a group
     * folds away in the pane, which is what a filter was for.
     *
     * @return array<string, list<Task>>
     */
    private function listSections(User $user, ?TaskList $list, DateTimeImmutable $now, DateTimeZone $zone): array
    {
        $today    = $now->setTimezone($zone)->format('Y-m-d');
        $sections = ['overdue' => [], 'today' => [], 'upcoming' => [], 'no_date' => []];

        foreach ($this->tasks->findForList($user, $list, false) as $task) {
            $dueDay = $task->dueDay($zone);

            $sections[match (true) {
                null === $dueDay   => 'no_date',
                $dueDay < $today   => 'overdue',
                $dueDay === $today => 'today',
                default            => 'upcoming',
            }][] = $task;
        }

        $sections['completed'] = $this->tasks->findForList($user, $list, true);

        return $sections;
    }
}
