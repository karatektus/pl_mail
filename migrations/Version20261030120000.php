<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Let the calendar identifiers a remote chooses be any length (#443).
 *
 * A 262-character iCalUId failed every pull of an Outlook calendar, because
 * calendar_event.uid was varchar(255). calendar_change_log.event_uid copies it,
 * and event_source_link.dedup_key contains it, at varchar(128). The other columns
 * did not fail, but the provider decides their length too. On Postgres 18 varchar
 * to text keeps the table's and the index's filenode, so nothing is rewritten.
 *
 * TEXT is not enough where a column is in a btree: Postgres refuses an index row
 * over 2704 bytes, so a random 2800-character UID still fails its insert, and an
 * event must not be lost to that. So these indexes stop holding the value: hash
 * indexes for the equality lookups, md5() for the uniqueness a hash index cannot
 * enforce. Nothing range-scans or sorts on these columns. remote_id loses
 * calendar_id from its index because a hash index takes one column; a remote id
 * names one event, so the calendar is a cheap filter on what it finds.
 *
 * calendar.remote_id, push_channel_id and push_resource_id stay in btrees: a
 * provider's id for a calendar or a channel, not something a sender can choose.
 *
 * Not reversible without loss: down() refuses when a longer value is stored.
 */
final class Version20261030120000 extends AbstractMigration
{
    /** Each column and the length it had. */
    private const array COLUMNS = [
        ['calendar_event', 'uid', 255],
        ['calendar_event', 'remote_etag', 255],
        ['calendar_change_log', 'event_uid', 255],
        ['event_source_link', 'dedup_key', 128],
        ['calendar', 'remote_id', 255],
        ['calendar', 'push_channel_id', 255],
        ['calendar', 'push_resource_id', 255],
    ];

    public function getDescription(): string
    {
        return 'Widen remote-chosen calendar identifiers to TEXT and index them by hash, so no length can fail a pull';
    }

    public function up(Schema $schema): void
    {
        foreach (self::COLUMNS as [$table, $column]) {
            $this->addSql(sprintf('ALTER TABLE %s ALTER COLUMN %s TYPE TEXT', $table, $column));
        }

        $this->addSql('DROP INDEX idx_calendar_event_uid');
        $this->addSql('DROP INDEX idx_calendar_event_calendar_remote');
        $this->addSql('DROP INDEX uniq_calendar_event_calendar_uid');
        $this->addSql('DROP INDEX idx_event_source_link_dedup');
        $this->addSql('CREATE INDEX idx_calendar_event_uid ON calendar_event USING hash (uid)');
        $this->addSql('CREATE INDEX idx_calendar_event_remote ON calendar_event USING hash (remote_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_calendar_event_calendar_uid ON calendar_event (calendar_id, md5(uid))');
        $this->addSql('CREATE INDEX idx_event_source_link_dedup ON event_source_link USING hash (dedup_key)');
    }

    public function down(Schema $schema): void
    {
        foreach (self::COLUMNS as [$table, $column, $length]) {
            $this->addSql(sprintf(
                'DO $$ BEGIN IF EXISTS (SELECT 1 FROM %1$s WHERE length(%2$s) > %3$d) THEN '
                . "RAISE EXCEPTION 'cannot narrow %1\$s.%2\$s: rows longer than %3\$d exist'; END IF; END \$\$",
                $table,
                $column,
                $length,
            ));
        }

        $this->addSql('DROP INDEX idx_calendar_event_uid');
        $this->addSql('DROP INDEX idx_calendar_event_remote');
        $this->addSql('DROP INDEX uniq_calendar_event_calendar_uid');
        $this->addSql('DROP INDEX idx_event_source_link_dedup');

        foreach (self::COLUMNS as [$table, $column, $length]) {
            $this->addSql(sprintf('ALTER TABLE %s ALTER COLUMN %s TYPE VARCHAR(%d)', $table, $column, $length));
        }

        $this->addSql('CREATE INDEX idx_calendar_event_uid ON calendar_event (uid)');
        $this->addSql('CREATE INDEX idx_calendar_event_calendar_remote ON calendar_event (calendar_id, remote_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_calendar_event_calendar_uid ON calendar_event (calendar_id, uid)');
        $this->addSql('CREATE INDEX idx_event_source_link_dedup ON event_source_link (dedup_key)');
    }
}
