<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The administrator's switch for serving users' profile pictures as a
 * Libravatar server (#642). Off for every install, existing ones included:
 * it makes the server answer strangers, which nobody should find switched on
 * after an upgrade. The users' own opt-in lives in their settings bag and
 * needs no column.
 */
final class Version20261116180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the switch for serving users\' pictures as a Libravatar server';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sender_picture_settings ADD libravatar_enabled BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sender_picture_settings DROP libravatar_enabled');
    }
}
