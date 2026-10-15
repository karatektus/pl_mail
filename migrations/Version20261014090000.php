<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Have every Gmail account list its mailbox once more, under the new rules.
 *
 * Two things changed about what a Gmail import takes in (#90): a message in
 * the mailbox now belongs to that account unless another connected account
 * claims it — sent mail from "send mail as" addresses, mail forwarded in from
 * an address not connected here, Gmail drafts — and the listing now includes
 * Spam and Trash, which it never asked for.
 *
 * Neither reaches an account whose import has finished: it does not list its
 * mailbox again. So:
 *
 *  - the record of declined messages is cleared, except chats, which are still
 *    declined — the rest are fetched again and stored;
 *  - the backfill markers are removed from every Gmail account, which makes
 *    its next sync start one listing. It fetches only what is not stored, so a
 *    mailbox that lost nothing costs its list pages and the messages above.
 *
 * Until that listing has finished the account shows as importing, which is
 * what it is.
 */
final class Version20261014090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Re-list every Gmail mailbox once, so declined mail and Spam and Trash are taken in';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM gmail_skipped_message
            WHERE reason = 'unattributable'
              AND NOT (label_ids::jsonb @> '["CHAT"]'::jsonb)
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE account
            SET settings = settings
                - 'sync.backfill_target'
                - 'sync.backfill_ran_at'
                - 'sync.backfill_attempts'
                - 'sync.backfill_page_token'
                - 'sync.backfill_pending'
            WHERE auth_type = 'oauth2' AND oauth_provider = 'google'
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Nothing to put back: what was deleted is a record the next listing
        // rebuilds, and an import that has run again is no less finished.
    }
}
