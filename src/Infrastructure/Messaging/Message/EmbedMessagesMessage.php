<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Turn a batch of messages into vectors.
 *
 * Ids, and a batch, for the reason every post-ingest job here uses both — but
 * more sharply: each of these is a round trip to another machine, and the model
 * is frequently cold. One job per message would put a queue of them behind
 * every sync.
 */
final readonly class EmbedMessagesMessage
{
    /**
     * @param list<int> $messageIds
     */
    public function __construct(
        public array $messageIds,
    ) {}
}
