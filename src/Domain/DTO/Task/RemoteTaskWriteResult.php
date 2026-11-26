<?php

declare(strict_types=1);

namespace App\Domain\DTO\Task;

/**
 * What a remote assigned to a task it just took.
 *
 * Unlike a calendar's write result this carries the payload that went out: it is
 * the copy the next edit builds on, and a driver may write nothing to the entity.
 */
final readonly class RemoteTaskWriteResult
{
    public function __construct(
        public string  $remoteId,
        public ?string $etag = null,
        public ?string $payload = null,
    ) {}
}
