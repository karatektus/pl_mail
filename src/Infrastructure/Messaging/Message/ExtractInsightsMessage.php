<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Read a batch of freshly ingested messages for the facts the radar shows —
 * parcels, flights, tickets, code review.
 *
 * Ids and a batch, the shape of every other post-ingest job. This one used to
 * be no job at all: ExtractInsightsStep ran the extractors inside the sync. See
 * that class for why it stopped.
 */
final readonly class ExtractInsightsMessage
{
    /**
     * @param list<int> $messageIds
     */
    public function __construct(
        public array $messageIds,
    ) {}
}
