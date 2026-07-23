<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260723104544 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contact ADD is_correspondent BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE message ADD category VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD headers JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE message_thread RENAME COLUMN tab TO category');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contact DROP is_correspondent');
        $this->addSql('ALTER TABLE message DROP category');
        $this->addSql('ALTER TABLE message DROP headers');
        $this->addSql('ALTER TABLE message_thread RENAME COLUMN category TO tab');
    }
}
