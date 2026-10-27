<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

use App\Domain\Enum\Mail\SyncTrigger;

/**
 * A batch of JMAP Email ids to fetch and store, as SyncGraphMessageBatchMessage
 * is for Graph ids.
 *
 * Ids, never entities, so the payload survives the queue. Only the live sync
 * dispatches these: an import page fetches its own ids inline, on the import
 * queue it already runs on.
 */
final readonly class SyncJmapMessageBatchMessage
{
    /**
     * @param list<string> $emailIds
     */
    public function __construct(
        public int          $accountId,
        public array        $emailIds,
        // What started the sync that planned this batch, carried along so the
        // messages it stores can say so. See SyncGraphMessageBatchMessage.
        public ?SyncTrigger $trigger = null,
    ) {
    }
}
