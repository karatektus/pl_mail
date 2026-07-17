<?php

declare(strict_types=1);

namespace App\Domain\Interface;

use App\Entity\Account;

interface AccountSyncerInterface
{
    public function supports(Account $account): bool;

    /**
     * Syncs the account and returns the IDs of the mailboxes that were touched,
     * so the caller can fire per-mailbox follow-up work (contact harvest,
     * Mercure notifications).
     *
     * @return list<int>
     */
    public function sync(Account $account): array;
}
