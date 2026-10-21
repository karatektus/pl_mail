<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Record which protocol an account's incoming mail arrives over (#NNN).
 *
 * One column, defaulted to `imap` in the database. Every row that exists
 * today is IMAP, Gmail or Microsoft: Gmail and Microsoft never open this
 * connection, and every other account is a plain IMAP one. So the default is
 * not a guess to be corrected later — it is the fact, and it means no
 * backfill and no window in which a running instance reads a null it cannot
 * interpret.
 *
 * Nothing is added for the POP3 delete mode or the UIDL ledger; both live in
 * the existing `account.settings` jsonb bag, which is where per-account
 * options have gone since it existed. See Account::SETTING_POP3_DELETE_MODE.
 */
final class Version20261021120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add account.incoming_protocol, defaulting to imap for every existing account';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE account ADD incoming_protocol VARCHAR(10) DEFAULT 'imap' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP incoming_protocol');
    }
}
