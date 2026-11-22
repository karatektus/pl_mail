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
 */
final readonly class TaskPane
{
    /**
     * @param array<string, list<Task>> $sections
     * @param list<TaskList>            $lists
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
    ) {}

    public function isEmpty(): bool
    {
        return [] === $this->sections;
    }
}
