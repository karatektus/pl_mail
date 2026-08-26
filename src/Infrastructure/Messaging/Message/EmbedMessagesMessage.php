<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Turn a batch of messages into vectors.
 *
 * Ids, and a batch, for the reason every job here that works over mail uses
 * both — but more sharply: each of these is a round trip to another machine,
 * and the model is frequently cold. One job per message would put a queue of
 * them behind everything else on the transport.
 *
 * The batch is also the ceiling on how long one delivery holds the host, which
 * is why App\Service\Ai\EmbeddingCatchUp chunks by BackfillPolicy::$batchSize
 * rather than posting a whole night's catch-up in one envelope.
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
