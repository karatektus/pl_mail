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
        /**
         * Whose mail this is.
         *
         * NULLABLE AND LAST, which is about upgrades rather than taste:
         * envelopes serialised by the previous build are already sitting on the
         * transport, and a required property would deserialise uninitialised
         * and fatal on the first read.
         *
         * Null therefore means "an old envelope, from before anybody could say
         * how much indexing they wanted". The handler refuses it rather than
         * embedding it, and the ids come back round on the next nightly
         * app:ai:index-new-mail with the id present — so the cost of the
         * refusal is one deployment window's worth of batches arriving a few
         * hours later than they would have.
         */
        public ?int $userId = null,
    ) {}
}
