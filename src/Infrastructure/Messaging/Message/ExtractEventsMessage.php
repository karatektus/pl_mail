<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Look for events in a batch of messages that have just been ingested.
 *
 * Ids rather than entities, and a batch rather than one each: extraction can
 * cost a Graph fetch or a parse, neither of which belongs inside a sync run
 * holding an IMAP connection. Per batch rather than per message for the same
 * reason the rule engine works that way — one job per arriving batch is a
 * queue that keeps up, one per message is not.
 */
final readonly class ExtractEventsMessage
{
    /**
     * @param list<int> $messageIds
     */
    public function __construct(
        public array $messageIds,
    ) {}
}
