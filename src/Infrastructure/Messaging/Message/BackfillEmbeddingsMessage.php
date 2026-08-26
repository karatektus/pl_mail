<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Walk one user's mailbox, embedding everything that has not been embedded.
 *
 * SELF-RE-DISPATCHING, WITH A CURSOR
 * ──────────────────────────────────
 * One message per chunk rather than one job for the whole mailbox. A hundred
 * thousand messages at a round trip each is hours of work, and a single job
 * holding that would be killed by any worker restart with nothing to show for
 * it — and would then start again from the beginning.
 *
 * `afterMessageId` is where the last chunk stopped. The walk is by ascending id
 * because that is the one ordering nothing can change underneath it: mail
 * arriving during a backfill gets a higher id and is picked up by the pass that
 * is still coming, and mail deleted during one simply is not there.
 */
final readonly class BackfillEmbeddingsMessage
{
    public function __construct(
        public int  $userId,
        public ?int $afterMessageId = null,
    ) {}
}
