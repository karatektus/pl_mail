<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260728092413 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store provider conversation ids for threading, index the threading lookups, and widen normalized_subject to TEXT.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD provider_thread_key VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_message_message_id ON message (message_id)');
        $this->addSql('CREATE INDEX idx_message_provider_thread_key_account ON message (provider_thread_key, account_id)');
        $this->addSql('ALTER TABLE message_thread ADD provider_thread_key VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE message_thread ALTER normalized_subject TYPE TEXT');
        $this->addSql('CREATE INDEX idx_message_thread_account_normalized_subject ON message_thread (account_id, normalized_subject)');
        $this->addSql('CREATE UNIQUE INDEX uniq_message_thread_provider_key_account ON message_thread (provider_thread_key, account_id)');
    }

    public function down(Schema $schema): void
    {
        // Narrowing normalized_subject back to VARCHAR(255) will fail if any row
        // has since exceeded it — that is the bug this migration removes.
        $this->addSql('DROP INDEX idx_message_message_id');
        $this->addSql('DROP INDEX idx_message_provider_thread_key_account');
        $this->addSql('ALTER TABLE message DROP provider_thread_key');
        $this->addSql('DROP INDEX idx_message_thread_account_normalized_subject');
        $this->addSql('DROP INDEX uniq_message_thread_provider_key_account');
        $this->addSql('ALTER TABLE message_thread DROP provider_thread_key');
        $this->addSql('ALTER TABLE message_thread ALTER normalized_subject TYPE VARCHAR(255)');
    }
}
