<?php

declare(strict_types=1);

namespace App\Service\JmapClient;

use App\Domain\Interface\AccountSyncerInterface;
use App\Entity\Mail\Account;

/**
 * JMAP sync entry point: mailboxes first, then Emails.
 *
 * The shape of GmailAccountSyncer. The mailbox list is synced first so every
 * mailbox id on an Email resolves to a label. An account with no Email state
 * has never been imported, or lost its state to a resync: its import is
 * planned here and run by MailImporter a page at a time, off this queue. One
 * that has a state reads what changed since.
 *
 * Returns no mailbox ids. The sync handler publishes a folder update for each
 * id it is handed, and a JMAP account has no Mailbox rows to name — its
 * batches publish the account update themselves, as Gmail's and Graph's do.
 */
final readonly class JmapAccountSyncer implements AccountSyncerInterface
{
    public function __construct(
        private JmapMailboxSyncer $mailboxes,
        private JmapEmailSyncer   $emails,
        private JmapImporter      $importer,
    ) {
    }

    public function supports(Account $account): bool
    {
        return true === $account->isJmap();
    }

    public function sync(Account $account): array
    {
        $this->mailboxes->sync($account);

        if (true === $this->emails->syncChanges($account)) {
            return [];
        }

        // No state: never imported, or the server could no longer use the one
        // it had. Either way the import is planned from the top. An import
        // that is merely under way never gets here — it recorded its baseline
        // first, so its changes are being read above all along.
        $this->importer->beginImport($account);

        return [];
    }
}
