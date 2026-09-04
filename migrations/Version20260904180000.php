<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A category report can be marked done, like every other report.
 *
 * The two report tables were built a year apart and only one of them had a way
 * for a row to leave the list. That was survivable while they were two panels;
 * it is not once they are one, because a worklist you cannot cross anything off
 * is not a worklist. Everything already in the table is unhandled, which is
 * both the correct backfill and what a NULL means here.
 */
final class Version20260904180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Category reports can be marked handled, like insight reports.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category_report ADD handled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category_report DROP handled_at');
    }
}
