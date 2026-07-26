<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260725135701 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE "user" ADD appearance_ink_color VARCHAR(7) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD appearance_ink_muted VARCHAR(7) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD appearance_ink_faint VARCHAR(7) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD appearance_main_tint VARCHAR(7) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD appearance_main_alpha DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE "user" DROP appearance_ink_color');
        $this->addSql('ALTER TABLE "user" DROP appearance_ink_muted');
        $this->addSql('ALTER TABLE "user" DROP appearance_ink_faint');
        $this->addSql('ALTER TABLE "user" DROP appearance_main_tint');
        $this->addSql('ALTER TABLE "user" DROP appearance_main_alpha');
    }
}
