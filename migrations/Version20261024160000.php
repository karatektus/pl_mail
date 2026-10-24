<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A logo of the user's own, uploaded as an SVG (#48): the hash it is stored
 * under, and whether it is the icon worn. Nobody has one yet.
 */
final class Version20261024160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let a user upload their own logo: its stored hash, and whether it is worn.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD appearance_logo_custom VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD appearance_logo_custom_active BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP appearance_logo_custom_active');
        $this->addSql('ALTER TABLE "user" DROP appearance_logo_custom');
    }
}
