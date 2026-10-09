<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Whether a person's inbox is shown in tabs. See
 * App\Entity\Embeddable\CategorySorting::$tabs.
 *
 * One boolean, true on every existing row: the tabs were always there, so
 * nobody's inbox changes shape by upgrading. Mail keeps its category whether
 * or not the tabs are shown, so there is nothing to backfill either way.
 */
final class Version20261010090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.category_tabs for an inbox without category tabs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD category_tabs BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP category_tabs');
    }
}
