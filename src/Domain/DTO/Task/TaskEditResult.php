<?php

declare(strict_types=1);

namespace App\Domain\DTO\Task;

use App\Entity\Task\Task;
use App\Entity\Task\TaskList;

/** What TaskEditorSave did: the task as saved, and the list it left if it moved. */
final readonly class TaskEditResult
{
    public function __construct(
        public Task      $task,
        public ?TaskList $movedFrom = null,
    ) {}
}
