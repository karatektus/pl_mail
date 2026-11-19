<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pinned conversations (#654): when a conversation was pinned, and when its
 * pin runs out. Both null for every existing conversation, which is unpinned.
 *
 * The index is partial, like the starred and snoozed ones beside it: the only
 * questions asked of it are "how many pins has this account" and "which pins
 * have ended", and both are about a handful of rows.
 */
final class Version20261117120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let a conversation be pinned to the top of its lists';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message_thread ADD pinned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE message_thread ADD pinned_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_message_thread_account_pinned ON message_thread (account_id) WHERE (pinned_at IS NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_message_thread_account_pinned');
        $this->addSql('ALTER TABLE message_thread DROP pinned_until');
        $this->addSql('ALTER TABLE message_thread DROP pinned_at');
    }
}
