<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260724104759 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE account ADD graph_delta_links JSON DEFAULT \'{}\' NOT NULL');
        $this->addSql('ALTER TABLE account ADD graph_immutable_ids BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE label ADD graph_folder_id VARCHAR(512) DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD graph_id VARCHAR(512) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE account DROP graph_delta_links');
        $this->addSql('ALTER TABLE account DROP graph_immutable_ids');
        $this->addSql('ALTER TABLE label DROP graph_folder_id');
        $this->addSql('ALTER TABLE message DROP graph_id');
    }
}
