<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BackgroundJob and CategoryReport take TimestampableTrait instead of each
 * declaring a createdAt of their own, so both tables gain updated_at.
 *
 * Both rows are edited after they are written: a job moves through its states,
 * a report is marked handled. Added nullable, backfilled with the latest moment
 * the row already records (a job's last progress or finish, a report's
 * handling, otherwise its creation), then made NOT NULL, because adding a NOT NULL column with no default
 * fails on a table that already has rows.
 */
final class Version20261110120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Give background_job and category_report an updated_at from the shared timestamp trait';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE background_job ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('UPDATE background_job SET updated_at = GREATEST(created_at, last_progress_at, COALESCE(finished_at, created_at))');
        $this->addSql('ALTER TABLE background_job ALTER updated_at SET NOT NULL');

        $this->addSql('ALTER TABLE category_report ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('UPDATE category_report SET updated_at = COALESCE(handled_at, created_at)');
        $this->addSql('ALTER TABLE category_report ALTER updated_at SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE background_job DROP updated_at');
        $this->addSql('ALTER TABLE category_report DROP updated_at');
    }
}
