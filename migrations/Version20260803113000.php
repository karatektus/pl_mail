<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The last five entities that tracked their own timestamps take the shared
 * trait, which means the five tables that only had created_at gain updated_at.
 *
 * These rows are written once and mostly never touched again — a change-log
 * entry, a log line, an uploaded blob, a push subscription, an event
 * suppression. The column will often just mirror created_at, and that is fine:
 * one rule for every entity is worth more than the bytes an exception saves,
 * and nothing has to decide which kind of entity it is looking at.
 *
 * Added nullable, backfilled from created_at, then made NOT NULL. Adding a NOT
 * NULL column with no default fails outright on a table that already has rows,
 * and log_entry and jmap_change_log always do — jmap_change_log is the busiest
 * table in the schema, because every mail mutation writes one.
 */
final class Version20260803113000 extends AbstractMigration
{
    private const array TABLES = [
        'event_suppression',
        'jmap_push_subscription',
        'uploaded_blob',
        'log_entry',
        'jmap_change_log',
    ];

    public function getDescription(): string
    {
        return 'Give the remaining five timestamped tables an updated_at';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(sprintf('ALTER TABLE %s ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE', $table));
            $this->addSql(sprintf('UPDATE %s SET updated_at = created_at', $table));
            $this->addSql(sprintf('ALTER TABLE %s ALTER COLUMN updated_at SET NOT NULL', $table));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(sprintf('ALTER TABLE %s DROP updated_at', $table));
        }
    }
}
