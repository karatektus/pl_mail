<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Widen the calendar columns whose values the remote chooses, because a
 * 262-character iCalUId failed every pull of an Outlook calendar (#443).
 *
 * calendar_event.uid overflowed, and calendar_change_log.event_uid copies it.
 * remote_etag and the three calendar columns did not fail, but the provider
 * decides their length too.
 *
 * Measured on Postgres 18: varchar(n) to text keeps the table's and the index's
 * filenode, so nothing is rewritten and this is safe on a large table.
 *
 * Not reversible without loss: down() refuses when a longer value is stored.
 */
final class Version20261030120000 extends AbstractMigration
{
    private const array COLUMNS = [
        ['calendar_event', 'uid'],
        ['calendar_event', 'remote_etag'],
        ['calendar_change_log', 'event_uid'],
        ['calendar', 'remote_id'],
        ['calendar', 'push_channel_id'],
        ['calendar', 'push_resource_id'],
    ];

    public function getDescription(): string
    {
        return 'Widen remote-chosen calendar identifiers to TEXT: a 262-character iCalUId failed every pull of its calendar';
    }

    public function up(Schema $schema): void
    {
        foreach (self::COLUMNS as [$table, $column]) {
            $this->addSql(sprintf('ALTER TABLE %s ALTER COLUMN %s TYPE TEXT', $table, $column));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::COLUMNS as [$table, $column]) {
            $this->addSql(sprintf(
                'DO $$ BEGIN IF EXISTS (SELECT 1 FROM %1$s WHERE length(%2$s) > 255) THEN '
                . "RAISE EXCEPTION 'cannot narrow %1\$s.%2\$s: rows longer than 255 exist'; END IF; END \$\$",
                $table,
                $column,
            ));
            $this->addSql(sprintf('ALTER TABLE %s ALTER COLUMN %s TYPE VARCHAR(255)', $table, $column));
        }
    }
}
