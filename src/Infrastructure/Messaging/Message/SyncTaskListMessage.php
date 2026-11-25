<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * A two-way sync of one task list. Dispatched by `app:tasks:sync` and by a new
 * subscription; an id, so the handler resolves the row itself, and a list gone by
 * then is nothing to do.
 */
final readonly class SyncTaskListMessage
{
    public function __construct(
        public int $taskListId,
    ) {}
}
