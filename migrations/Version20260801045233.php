<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Paper becomes what a new account starts on.
 *
 * Column defaults only. Nothing is backfilled on purpose: every existing user
 * has already been shown a theme and either kept it or changed it, and
 * rewriting that would be this migration deciding it knows better. The two
 * values move together because the accent belongs to the palette — the old
 * default blue on Paper's cream reads as a hyperlink rather than as the app's
 * own colour.
 */
final class Version20260801045233 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Default new accounts to the Paper theme and its accent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ALTER appearance_theme SET DEFAULT \'paper\'');
        $this->addSql('ALTER TABLE "user" ALTER appearance_accent SET DEFAULT \'#7d6b4f\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ALTER appearance_theme SET DEFAULT \'system\'');
        $this->addSql('ALTER TABLE "user" ALTER appearance_accent SET DEFAULT \'#2563eb\'');
    }
}
