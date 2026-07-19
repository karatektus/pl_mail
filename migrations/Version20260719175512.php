<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260719175512 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE message_thread_mailbox DROP CONSTRAINT fk_49b25e0a66ec35cc');
        $this->addSql('ALTER TABLE message_thread_mailbox DROP CONSTRAINT fk_49b25e0a8829462f');
        $this->addSql('DROP TABLE message_thread_mailbox');
        $this->addSql('ALTER TABLE message ALTER mailbox_id DROP NOT NULL');
        $this->addSql('ALTER TABLE message_thread DROP archived_at');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE message_thread_mailbox (message_thread_id INT NOT NULL, mailbox_id INT NOT NULL, PRIMARY KEY (message_thread_id, mailbox_id))');
        $this->addSql('CREATE INDEX idx_49b25e0a8829462f ON message_thread_mailbox (message_thread_id)');
        $this->addSql('CREATE INDEX idx_49b25e0a66ec35cc ON message_thread_mailbox (mailbox_id)');
        $this->addSql('ALTER TABLE message_thread_mailbox ADD CONSTRAINT fk_49b25e0a66ec35cc FOREIGN KEY (mailbox_id) REFERENCES mailbox (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE message_thread_mailbox ADD CONSTRAINT fk_49b25e0a8829462f FOREIGN KEY (message_thread_id) REFERENCES message_thread (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE message ALTER mailbox_id SET NOT NULL');
        $this->addSql('ALTER TABLE message_thread ADD archived_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }
}
