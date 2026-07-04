<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260703192500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE account ADD is_primary BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE account ADD email VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE account RENAME COLUMN label TO name');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE account ADD label VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE account DROP name');
        $this->addSql('ALTER TABLE account DROP is_primary');
        $this->addSql('ALTER TABLE account DROP email');
    }
}
