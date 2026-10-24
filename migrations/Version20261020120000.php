<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remember a send that finally failed, and how long one has been retried (#125).
 *
 * Four nullable columns on `message`, all null for every row that exists
 * today: no send has been marked failed before, so there is nothing to
 * backfill. The partial index serves the sidebar's warning, which counts the
 * few marked drafts of one user on every counts refresh and would otherwise
 * read the user's whole mail to find none.
 */
final class Version20261020120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add message.send_failed_at, send_failure, send_retrying_since and send_unconfirmed_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD send_failed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD send_failure VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD send_retrying_since TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD send_unconfirmed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_message_send_failed ON message (account_id) WHERE send_failed_at IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_message_send_failed');
        $this->addSql('ALTER TABLE message DROP send_failed_at');
        $this->addSql('ALTER TABLE message DROP send_failure');
        $this->addSql('ALTER TABLE message DROP send_retrying_since');
        $this->addSql('ALTER TABLE message DROP send_unconfirmed_at');
    }
}
