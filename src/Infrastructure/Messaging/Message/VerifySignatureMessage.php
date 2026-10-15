<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Check the signature on one signed message. See App\Service\Crypto\SignatureVerifier.
 *
 * Its own job, off the ingest worker: checking can mean fetching the raw
 * source from Gmail or Microsoft first, and a sync is not the place to wait on
 * that. Routed with the other per-message enrichment — `enrich` in
 * messenger.yaml — for the same reason those are.
 */
final readonly class VerifySignatureMessage
{
    public function __construct(
        public int $messageId,
    ) {
    }
}
