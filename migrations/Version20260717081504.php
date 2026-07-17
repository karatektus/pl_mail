<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260717081504 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE account ADD gmail_history_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE account ADD gmail_watch_expiry TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE account ADD gmail_watch_resource_name VARCHAR(512) DEFAULT NULL');
        $this->addSql('ALTER TABLE message DROP search_vector');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE account DROP gmail_history_id');
        $this->addSql('ALTER TABLE account DROP gmail_watch_expiry');
        $this->addSql('ALTER TABLE account DROP gmail_watch_resource_name');
        $this->addSql('ALTER TABLE message ADD search_vector TEXT DEFAULT NULL');
    }
}
