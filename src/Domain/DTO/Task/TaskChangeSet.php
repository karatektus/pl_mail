<?php

declare(strict_types=1);

namespace App\Domain\DTO\Task;

/**
 * One complete window of changes at a remote, and the position after it.
 *
 * The same contract as CalendarChangeSet, without the instance bookkeeping a
 * task has no need of.
 */
final readonly class TaskChangeSet
{
    /**
     * @param list<RemoteTask> $tasks
     */
    public function __construct(
        public array   $tasks = [],
        public ?string $nextSyncToken = null,
        public bool    $requiresFullResync = false,
    ) {}

    public static function resyncRequired(): self
    {
        return new self(requiresFullResync: true);
    }
}
