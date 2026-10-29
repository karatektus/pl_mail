<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * message.server_email_id and message.rfc822_size: what the sync recognises a
 * copy of an email by (#415).
 *
 * Both start empty and are filled for mail stored from here on. An existing
 * row therefore never matches a new copy — that row's duplicates are the merge
 * job's, which can afford to look closer than a sync can.
 */
final class Version20261029160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add message.server_email_id and message.rfc822_size';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD server_email_id VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD rfc822_size INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_message_server_email_id_account ON message (server_email_id, account_id) WHERE server_email_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_message_server_email_id_account');
        $this->addSql('ALTER TABLE message DROP server_email_id');
        $this->addSql('ALTER TABLE message DROP rfc822_size');
    }
}
