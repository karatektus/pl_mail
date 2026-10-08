<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

use App\Domain\Enum\Mail\SyncTrigger;

/**
 * General/scheduled/push-driven sync of an entire account.
 * The handler resolves the right AccountSyncerInterface for the provider.
 *
 * $trigger says which of those it was, so the mail it brings in can be marked
 * with how it got here. See SyncTrigger.
 */
readonly class SyncAccountMessage
{
    public function __construct(
        public int $accountId,
        // Null on a job queued before this existed. Read it with `??`: such a
        // job is unserialised without the property being set at all.
        public ?SyncTrigger $trigger = null,
    ) {}
}
