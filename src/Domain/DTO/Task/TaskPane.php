<?php

declare(strict_types=1);

namespace App\Domain\DTO\Task;

use App\Entity\Task\Task;
use App\Entity\Task\TaskList;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Everything the pane draws, already sorted into its groups.
 *
 * `sections` keeps only groups with something in them, so the template has no
 * empty heading to decide about, and is keyed by the group's translation suffix.
 *
 * A list's finished tasks are not among them: they can run to thousands, and the
 * group is folded until somebody opens it. `completedLater` says how many there
 * are, and the group reads them a page at a time once open (TaskPaneReader::
 * completedPage()). My Day's are today's only, and come with the rest.
 */
final readonly class TaskPane
{
    /**
     * @param array<string, list<Task>> $sections
     * @param list<TaskList>            $lists
     * @param list<int>                 $faded lists outside the workspace, drawn faint ("+ Others")
     */
    public function __construct(
        public TaskPaneQuery     $query,
        public array             $sections,
        public array             $lists,
        public ?TaskList         $list,
        public TaskList          $addTo,
        public DateTimeImmutable $now,
        public DateTimeZone      $zone,
        public bool              $canSubscribe = false,
        public int               $completedLater = 0,
        // workspace|overlay|all in a workspace narrowed to some groups (#783),
        // null elsewhere: the pane's switch is drawn only where there is one.
        public ?string           $scope = null,
        public array             $faded = [],
    ) {}

    public function isEmpty(): bool
    {
        return [] === $this->sections && 0 === $this->completedLater;
    }
}
