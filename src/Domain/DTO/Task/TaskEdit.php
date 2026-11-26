<?php

declare(strict_types=1);

namespace App\Domain\DTO\Task;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Everything the task dialog sends besides the task's own fields.
 *
 * As posted, because which of it applies depends on the task: a step and a
 * finished entry are offered no steps and no files, and TaskEditorSave is what
 * knows that. Only the controller has the request, and only it reads it.
 */
final readonly class TaskEdit
{
    /**
     * @param array<array-key, mixed> $stepTitles   new titles for the task's own steps, by step id
     * @param array<array-key, mixed> $newSteps     titles of steps to add, empty rows included
     * @param array<array-key, mixed> $newStepsDone '1' at the position of each one added ticked
     */
    public function __construct(
        public TaskDraft     $draft,
        public array         $stepTitles = [],
        public array         $newSteps = [],
        public array         $newStepsDone = [],
        public ?UploadedFile $file = null,
    ) {}
}
