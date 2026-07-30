<?php

declare(strict_types=1);

namespace App\Service\Gmail;

use App\Domain\Interface\AccountSyncerInterface;
use App\Entity\Mail\Account;

/**
 * Gmail sync entry point. Label-based architecture: syncs the label list
 * first so every labelId on incoming messages resolves, then plans message
 * work directly on the account — no Mailbox involvement.
 */
final readonly class GmailAccountSyncer implements AccountSyncerInterface
{
    public function __construct(
        private GmailApiSyncer   $gmailApiSyncer,
        private GmailLabelSyncer $labelSyncer,
    ) {}

    public function supports(Account $account): bool
    {
        return $account->isGmail();
    }

    public function sync(Account $account): array
    {
        $this->labelSyncer->sync($account);

        if (null === $account->getGmailHistoryId()) {
            $this->gmailApiSyncer->initialSync($account);

            return [];
        }

        // New mail first, then any backlog the cap still leaves uncovered.
        // The backfill is not skipped just because a historyId exists: that
        // only says where incremental sync resumes, and treating it as "the
        // mailbox is fully synced" is what used to strand accounts on
        // whatever the first run happened to fetch.
        $this->gmailApiSyncer->syncIncremental($account);
        $this->gmailApiSyncer->backfill($account);

        return [];
    }
}
