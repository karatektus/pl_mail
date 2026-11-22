<?php

declare(strict_types=1);

namespace App\Domain\DTO\Task;

use App\Domain\Enum\Task\TaskStatus;
use DateTimeImmutable;

/**
 * One task as a remote holds it, or the news that it is gone.
 *
 * Every date is in the shape Task keeps it: a date with no time stays a date, an
 * instant is UTC. $payload is the provider's own copy, which comes back to its
 * driver byte for byte on the next write and is read by nothing else.
 *
 * A step is named by its parent's uid rather than its row: the parent may arrive
 * later in the same window, and a row does not exist yet to point at.
 */
final readonly class RemoteTask
{
    /**
     * @param list<array{url: string, filename: string}> $links files elsewhere
     */
    public function __construct(
        public string             $remoteId,
        public ?string            $etag = null,
        public bool               $isDeleted = false,
        public string             $uid = '',
        public string             $title = '',
        public ?string            $notes = null,
        public TaskStatus         $status = TaskStatus::NeedsAction,
        public ?DateTimeImmutable $completedAt = null,
        public ?DateTimeImmutable $dueDate = null,
        public ?DateTimeImmutable $dueAt = null,
        public ?DateTimeImmutable $startDate = null,
        public ?DateTimeImmutable $startAt = null,
        public ?string            $timeZone = null,
        public int                $priority = 0,
        public ?DateTimeImmutable $reminderAt = null,
        public ?string            $recurrenceRule = null,
        public ?string            $parentUid = null,
        public int                $position = 0,
        public ?string            $payload = null,
        public array              $links = [],
    ) {}

    public static function deleted(string $remoteId): self
    {
        return new self($remoteId, isDeleted: true);
    }
}
