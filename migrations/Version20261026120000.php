<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * How old mail may be and still be sorted by the assistant as bulk work — an
 * import, or the catch-up after one (#208). See
 * AiSettings::$categoriseBacklogDays.
 *
 * One NOT NULL column with a default, in one statement, and that is safe here
 * where it would not be for a new column without one: PostgreSQL fills every
 * existing row with the default as the column is added. ai_settings is a
 * single row anyway, and it gets 30 — the value the maintainer asked for —
 * which means an installation that already sorts by assistant stops asking
 * about mail older than a month at the next catch-up. The changelog says so,
 * and says that 0 puts it back.
 */
final class Version20261026120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Limit the assistant\'s bulk sorting by age: ai_settings.categorise_backlog_days';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_settings ADD categorise_backlog_days INT DEFAULT 30 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_settings DROP categorise_backlog_days');
    }
}
