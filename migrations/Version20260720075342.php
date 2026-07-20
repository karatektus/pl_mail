<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260720075342 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE account ADD settings JSONB DEFAULT \'{}\' NOT NULL');
        $this->addSql('ALTER TABLE account DROP gmail_sync_gmailify_enabled');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE account ADD gmail_sync_gmailify_enabled BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE account DROP settings');
    }
}
