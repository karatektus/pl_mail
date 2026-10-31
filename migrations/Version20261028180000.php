<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-account sync interval, and IDLE off for Junk (#202).
 *
 * `sync_interval_minutes` defaults to 15, the interval every account was
 * polled at until now, so nothing changes on upgrade. `last_polled_at` starts
 * empty, which makes every account due on the first tick after the upgrade:
 * one poll each, as the old quarter-hourly cron would have run anyway.
 *
 * Junk folders lose IDLE. They had it because MailboxSyncer gave it to every
 * folder the server marks \Junk, and there was no way to switch it off, so
 * every row that has it now has it by that default and none by anybody's
 * choice. The folders dialog can switch it back on. The IMAP listener notices
 * on its next reconcile and lets those connections go.
 */
final class Version20261028180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add account.sync_interval_minutes and account.last_polled_at; switch IDLE off for Junk folders';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD sync_interval_minutes INT DEFAULT 15 NOT NULL');
        $this->addSql('ALTER TABLE account ADD last_polled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("UPDATE mailbox SET is_idle_enabled = false WHERE special_use = '\\Junk'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP sync_interval_minutes');
        $this->addSql('ALTER TABLE account DROP last_polled_at');
    }
}
