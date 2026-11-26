<?php

declare(strict_types=1);

namespace App\Domain\DTO\Task;

/**
 * What the editor sends for one task, before anything has decided what it means.
 *
 * Strings as typed, because turning a date and a time into the right column
 * needs the user's zone and is TaskWriter's to do. Carries only the fields there
 * is a control for: start date and priority have none in the first version, so
 * they cannot appear here and an edit cannot blank them.
 *
 * A null $recurrence or $reminder means "this form has no say", which is what
 * quick add is: not the same as empty, which clears them.
 */
final readonly class TaskDraft
{
    public function __construct(
        public string  $title,
        public ?string $notes = null,
        public string  $dueDate = '',
        public string  $dueTime = '',
        public ?string $recurrence = null,
        public ?string $reminder = null,
    ) {}
}
