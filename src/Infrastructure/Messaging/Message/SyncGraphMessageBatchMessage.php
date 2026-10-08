<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

use App\Domain\Enum\Mail\SyncTrigger;

/**
 * Fetch and import one chunk of Graph message ids.
 *
 * Chunks are capped at GraphApiClient::BATCH_LIMIT (20) by the planner —
 * Graph's $batch ceiling, a fifth of Gmail's.
 */
readonly class SyncGraphMessageBatchMessage
{
    /**
     * @param list<string> $graphIds
     */
    public function __construct(
        public int   $accountId,
        public array $graphIds,
        // What started the sync that planned this batch, carried along so the
        // messages it stores can say so. `??` when reading: see SyncAccountMessage.
        public ?SyncTrigger $trigger = null,
    ) {}
}
