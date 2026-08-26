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
    ) {}
}
