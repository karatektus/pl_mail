<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260723120515 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE "user" ADD appearance_theme VARCHAR(16) DEFAULT \'system\' NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD appearance_accent VARCHAR(7) DEFAULT \'#2563eb\' NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD appearance_pane_alpha DOUBLE PRECISION DEFAULT 0.7 NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD appearance_pane_blur SMALLINT DEFAULT 24 NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD appearance_radius DOUBLE PRECISION DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD appearance_density VARCHAR(16) DEFAULT \'comfortable\' NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD appearance_background_kind VARCHAR(16) DEFAULT \'theme\' NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD appearance_background_preset VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD appearance_background_file VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD appearance_background_solid VARCHAR(7) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD appearance_scrim_alpha DOUBLE PRECISION DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE "user" DROP appearance_theme');
        $this->addSql('ALTER TABLE "user" DROP appearance_accent');
        $this->addSql('ALTER TABLE "user" DROP appearance_pane_alpha');
        $this->addSql('ALTER TABLE "user" DROP appearance_pane_blur');
        $this->addSql('ALTER TABLE "user" DROP appearance_radius');
        $this->addSql('ALTER TABLE "user" DROP appearance_density');
        $this->addSql('ALTER TABLE "user" DROP appearance_background_kind');
        $this->addSql('ALTER TABLE "user" DROP appearance_background_preset');
        $this->addSql('ALTER TABLE "user" DROP appearance_background_file');
        $this->addSql('ALTER TABLE "user" DROP appearance_background_solid');
        $this->addSql('ALTER TABLE "user" DROP appearance_scrim_alpha');
    }
}
