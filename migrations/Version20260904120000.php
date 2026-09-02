<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remember which Gmail account can speak for a Gmailified message.
 *
 * WHAT GMAILIFY DOES TO THE DATA MODEL
 * ────────────────────────────────────
 * Google can fetch another mailbox into Gmail. Connect BOTH to plMail — the
 * Gmail account and the mailbox it fetches from — and every message arrives
 * twice. SyncGmailMessageBatchHandler already handles that, and handles it the
 * right way round: it merges rather than skipping, so the IMAP row keeps
 * ownership of its location and flags and simply gains what the Gmail copy
 * knows — the gmailId, the label ids, the translated labels.
 *
 * The result is a row that belongs to the IMAP account and carries a Gmail
 * identity. That is a correct and useful shape, and it broke every push to
 * Gmail there is.
 *
 * WHY THE COLUMN IS NEEDED
 * ────────────────────────
 * LabelChangePropagator asks whether it should tell Gmail about a change by
 * looking at the account the ROW belongs to. For a merged row that is the IMAP
 * account, which is not a Gmail account, so the answer was always no — and the
 * message's perfectly good gmailId went unused. Archive, trash, restore, star,
 * mark-read and every label change were applied locally and on IMAP and never
 * reached Gmail at all. Reported as a mail moved out of spam that came back:
 * Hetzner obeyed, Gmail never heard.
 *
 * The account that can act is the CARRIER — the Gmail account whose sync
 * recognised the message and stamped its id on. Nothing recorded which one that
 * was, and it cannot be re-derived in general: a user may have two Gmail
 * accounts, and a gmailId is only meaningful to the one that issued it.
 *
 * THE BACKFILL IS THE UNAMBIGUOUS HALF, ON PURPOSE
 * ────────────────────────────────────────────────
 * Existing merged rows have no carrier recorded. Where the owner has exactly
 * one Google account, there is only one account the id could have come from and
 * the answer is not a guess. Where they have two or more it is, so those rows
 * are left null and picked up the next time the Gmail sync sees the message —
 * which is the same path that stamped them in the first place.
 *
 * ON DELETE SET NULL rather than CASCADE: disconnecting the Gmail account must
 * not delete the IMAP mailbox's mail. It means the row stops being pushed to
 * Gmail, which is exactly right — there is no longer an account that could.
 */
final class Version20260904120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record which Gmail account carries a Gmailified message, so changes to it reach Gmail';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD gmail_carrier_account_id INT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE message ADD CONSTRAINT fk_message_gmail_carrier_account '
            . 'FOREIGN KEY (gmail_carrier_account_id) REFERENCES account (id) ON DELETE SET NULL',
        );
        // Serves the propagator's grouping, which reads it per message on a
        // write path, and the backfill's own WHERE.
        $this->addSql('CREATE INDEX idx_message_gmail_carrier_account ON message (gmail_carrier_account_id)');

        // The unambiguous rows: a Gmail identity on a row whose own account is
        // not a Google one, owned by somebody with exactly one Google account.
        $this->addSql(<<<'SQL'
        UPDATE message m
        SET gmail_carrier_account_id = sole.id
        FROM account own
        JOIN LATERAL (
            SELECT a.id
            FROM account a
            WHERE a.usr_id = own.usr_id
              AND a.auth_type = 'oauth2'
              AND a.oauth_provider = 'google'
            LIMIT 2
        ) sole ON TRUE
        WHERE m.account_id = own.id
          AND m.gmail_id IS NOT NULL
          AND (own.auth_type <> 'oauth2' OR own.oauth_provider IS DISTINCT FROM 'google')
          AND (
              SELECT count(*) FROM account a2
              WHERE a2.usr_id = own.usr_id
                AND a2.auth_type = 'oauth2'
                AND a2.oauth_provider = 'google'
          ) = 1
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Dropping this puts Gmailified rows back to being pushed nowhere,
        // which is the behaviour this migration exists to end. Nothing else is
        // lost: the gmailId the column points a carrier at stays on the row.
        $this->addSql('ALTER TABLE message DROP CONSTRAINT fk_message_gmail_carrier_account');
        $this->addSql('DROP INDEX idx_message_gmail_carrier_account');
        $this->addSql('ALTER TABLE message DROP gmail_carrier_account_id');
    }
}
