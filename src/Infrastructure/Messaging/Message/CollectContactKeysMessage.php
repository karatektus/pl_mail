<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Look for the sender's key in one message that just arrived. See
 * App\Service\Crypto\ContactKeyCollector.
 *
 * Its own job, off the ingest worker, for VerifySignatureMessage's reason:
 * reading attached keys can mean fetching the raw source from Gmail or
 * Microsoft, and every key is read by gpg. Routed beside it, on `enrich`.
 */
final readonly class CollectContactKeysMessage
{
    public function __construct(
        public int $messageId,
    ) {
    }
}
