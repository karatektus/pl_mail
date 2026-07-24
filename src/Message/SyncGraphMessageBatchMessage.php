<?php

declare(strict_types=1);

namespace App\Message;

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
    ) {}
}
