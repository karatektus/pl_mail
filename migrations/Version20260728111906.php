<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the Layout appearance axis and makes `flat` the default look.
 *
 * The glass knobs move with it: the default appearance has to match
 * Layout::Flat's preset, or a fresh account renders in a state no layout would
 * produce. Rows still holding the previous defaults are moved across too —
 * they predate the setting, so they are not a choice anyone made.
 */
final class Version20260728111906 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add appearance_layout, default the appearance to the flat layout';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD appearance_layout VARCHAR(16) DEFAULT \'flat\' NOT NULL');

        $this->addSql('ALTER TABLE "user" ALTER appearance_pane_alpha SET DEFAULT 1');
        $this->addSql('ALTER TABLE "user" ALTER appearance_pane_blur SET DEFAULT 0');
        $this->addSql('ALTER TABLE "user" ALTER appearance_radius SET DEFAULT 0.75');

        $this->addSql('UPDATE "user" SET appearance_pane_alpha = 1 WHERE appearance_pane_alpha = 0.7');
        $this->addSql('UPDATE "user" SET appearance_pane_blur = 0 WHERE appearance_pane_blur = 24');
        $this->addSql('UPDATE "user" SET appearance_radius = 0.75 WHERE appearance_radius = 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP appearance_layout');

        $this->addSql('ALTER TABLE "user" ALTER appearance_pane_alpha SET DEFAULT 0.7');
        $this->addSql('ALTER TABLE "user" ALTER appearance_pane_blur SET DEFAULT 24');
        $this->addSql('ALTER TABLE "user" ALTER appearance_radius SET DEFAULT 1');
    }
}
