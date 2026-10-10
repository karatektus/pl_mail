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
 *
 * $requestedAt is when the sync was asked for, which is what lets a pile of
 * them collapse into one. The quarter-hour poll, a push and somebody pressing
 * Sync each queue one of these, and nothing used to notice that they were all
 * asking for the same thing: behind one slow sync they stacked up and each ran
 * in turn (#42). A request is redundant exactly when a sync of the account
 * BEGAN after the request was made and went through — that sync has already
 * seen whatever the request was about. See Account::isSyncedSince().
 */
readonly class SyncAccountMessage
{
    /**
     * Unix time. Absent on a job queued before this existed — read it with
     * `??`, like $trigger — and such a job is simply never redundant.
     */
    public ?int $requestedAt;

    public function __construct(
        public int $accountId,
        // Null on a job queued before this existed. Read it with `??`: such a
        // job is unserialised without the property being set at all.
        public ?SyncTrigger $trigger = null,
        ?int $requestedAt = null,
    ) {
        // Stamped here rather than by every caller: there are five of them,
        // and the one that forgot would be the one whose requests never
        // collapse.
        $this->requestedAt = $requestedAt ?? time();
    }
}
