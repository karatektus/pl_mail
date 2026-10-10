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

        if (null === $account->gmailHistoryId) {
            // Not initialSync(): that lists the whole mailbox before it
            // returns. The listing belongs to MailImporter, a page at a time
            // on the import queue — see GmailApiSyncer::beginImport().
            $this->gmailApiSyncer->beginImport($account);

            return [];
        }

        // New mail first, then any backlog the cap still leaves uncovered.
        // The backfill is not skipped just because a historyId exists: that
        // only says where incremental sync resumes, and treating it as "the
        // mailbox is fully synced" is what used to strand accounts on
        // whatever the first run happened to fetch.
        //
        // The backlog itself is no longer walked here. SyncAccountMessageHandler
        // asks MailImporter to see that it is under way, which is the same
        // "every sync resumes it" with the listing off this queue.
        $this->gmailApiSyncer->syncIncremental($account);

        return [];
    }
}
