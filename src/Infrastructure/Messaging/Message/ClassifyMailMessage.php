<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Ask a language model what a batch of freshly ingested messages are.
 *
 * A batch of ids, like every other post-ingest job here — but for a sharper
 * reason than the others. Each of these costs a round trip to another machine
 * and, on a cold model, several seconds of it. One job per arriving message
 * would put a queue of them behind every sync; one job per batch lets the
 * handler stop early when the feature is off, which is the case on almost every
 * installation.
 */
final readonly class ClassifyMailMessage
{
    /**
     * @param list<int> $messageIds
     */
    public function __construct(
        public array $messageIds,
        /**
         * Ask again about mail that has already been asked about.
         *
         * The handler skips anything carrying an `aiCategorisedAt` stamp, which
         * is right for ingest — a message is worth one question — and wrong for
         * the one case where the stamp is the problem: the ANSWER has gone
         * stale because what we send the model changed. That happened the day
         * the bulk-header line was added, and every verdict stored before it
         * was reached without evidence the model now gets.
         *
         * Defaulted and LAST, which is about upgrades rather than taste:
         * envelopes serialised by the previous build are already on the
         * transport, and they must still deserialise.
         */
        public bool $force = false,
    ) {}
}
