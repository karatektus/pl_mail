<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

use App\Domain\Enum\Mail\SyncTrigger;

/**
 * A chunk of Gmail message IDs to fetch, build, and persist. Dispatched by
 * GmailApiSyncer so the work parallelises across workers.
 *
 * Account-based since the label refactor: Gmail messages have no Mailbox.
 */
readonly class SyncGmailMessageBatchMessage
{
    /**
     * @param list<string> $gmailIds
     */
    public function __construct(
        public int   $accountId,
        public array $gmailIds,
        // What started the sync that planned this batch, carried along so the
        // messages it stores can say so. `??` when reading: see SyncAccountMessage.
        public ?SyncTrigger $trigger = null,
    ) {}
}
