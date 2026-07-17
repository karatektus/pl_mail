<?php

declare(strict_types=1);

namespace App\Message;

/**
 * A chunk of Gmail message IDs to fetch, build, and persist. Dispatched by
 * GmailApiSyncer so the work parallelises across workers.
 */
readonly class SyncGmailMessageBatchMessage
{
    /**
     * @param list<string> $gmailIds
     */
    public function __construct(
        public int   $mailboxId,
        public array $gmailIds,
    ) {}
}
