<?php

declare(strict_types=1);

namespace App\Message;

/**
 * General/scheduled/push-driven sync of an entire account.
 * The handler resolves the right AccountSyncerInterface for the provider.
 */
readonly class SyncAccountMessage
{
    public function __construct(
        public int $accountId,
    ) {}
}
