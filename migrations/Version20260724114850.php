<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260724114850 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE account ADD push_enabled BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE account ADD graph_subscription_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE account ADD graph_subscription_client_state VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE account ADD graph_subscription_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE account ALTER graph_delta_links DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE account DROP push_enabled');
        $this->addSql('ALTER TABLE account DROP graph_subscription_id');
        $this->addSql('ALTER TABLE account DROP graph_subscription_client_state');
        $this->addSql('ALTER TABLE account DROP graph_subscription_expires_at');
        $this->addSql('ALTER TABLE account ALTER graph_delta_links SET DEFAULT \'{}\'');
    }
}
