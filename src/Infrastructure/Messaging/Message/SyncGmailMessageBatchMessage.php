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
        // Fetch only the ids that are not stored yet. Set for a batch a
        // listing planned, where an id already here is one an earlier batch
        // brought in; left off for a sync's batch, which names stored ids on
        // purpose because their labels changed. `??` when reading, as above,
        // which is also why it is nullable: a job queued before this existed
        // has no such property at all.
        public ?bool $onlyMissing = false,
    ) {}
}
