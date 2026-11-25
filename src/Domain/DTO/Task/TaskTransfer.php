<?php

declare(strict_types=1);

namespace App\Domain\DTO\Task;

use App\Entity\Task\TaskList;

/** What TaskListTransfer did: where the tasks went, and how many the pane will show there. */
final readonly class TaskTransfer
{
    public function __construct(
        public TaskList $to,
        public int      $moved,
    ) {}
}
