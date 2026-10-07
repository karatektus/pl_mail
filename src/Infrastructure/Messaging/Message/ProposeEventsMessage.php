<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Look for a date in prose in a batch of freshly ingested messages.
 *
 * Ids and a batch, the shape of every other post-ingest job. See
 * ProposeEventsStep for why this is a job now and was not before.
 */
final readonly class ProposeEventsMessage
{
    /**
     * @param list<int> $messageIds
     */
    public function __construct(
        public array $messageIds,
    ) {}
}
