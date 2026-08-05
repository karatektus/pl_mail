<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A mirrored series gains a record of which occurrence each of the remote's
 * instance resources is, so a cancelled instance can be recognised when the
 * provider names nothing but its id.
 *
 * Microsoft Graph reports an occurrence somebody deleted in Outlook as a
 * `@removed` entry in the calendarView delta carrying that occurrence's id and
 * no other property — not the series it belongs to, not the start it had.
 * plMail stores one row per series and none per occurrence, so that id matched
 * nothing, the deletion did nothing, and the instance the user removed went on
 * being drawn on every calendar view until something else happened to the
 * series. There is no way to resolve the id after the fact: the resource is
 * gone, and asking Graph about it answers 404. It has to have been written down
 * while the occurrence still existed, which is what this column is.
 *
 * jsonb keyed by the provider's instance id, valued with that occurrence's
 * ORIGINAL start as a UTC instant — `{"AAMk…": "2026-08-11T08:00:00Z"}`. Keyed
 * that way round because of the one query that cannot be answered in PHP:
 * "whose instance is this id?", asked from a tombstone that names nothing else
 * and therefore asked against the whole table. A jsonb key is what an index can
 * answer that with, hence the GIN below and `jsonb_exists()` in
 * CalendarEventRepository. The lookup a push wants — the id for an original
 * start — runs over one series' own map, which is already in memory.
 *
 * A column rather than a table of its own, which is the trade worth arguing
 * with. A row per instance would index better and would not rewrite a series'
 * whole map to record one id. Against that: every read of this is a read of one
 * event's map by something already holding the event, every write happens in the
 * same unit of work as the series, and an entry has no life of its own — so the
 * table buys a join, a cascade and a repository for data that is strictly one
 * event's. The map is kept small at the other end instead: CalendarPuller drops
 * entries older than the horizon occurrences are materialised to, because an id
 * for an instance no view can draw answers no question.
 *
 * NOT NULL with a default and no backfill needed — `'{}'::jsonb` is what every
 * existing row means, and the default fills them in place. The empty map is also
 * the permanent state of most of the table: a locally made event, a one-off, and
 * every CalDAV series, whose instances live inside the master's own .ics and have
 * no id to record.
 *
 * Reversible, and losing only what the next full read writes again.
 */
final class Version20260805100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remember which occurrence each of a remote series\' instance ids is';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE calendar_event ADD remote_instances JSONB DEFAULT '{}' NOT NULL");

        // GIN rather than btree, and not only for speed: a btree entry holds the
        // whole value, and a weekly series' map is far past the 2704-byte index
        // row limit, so the index would refuse the very rows it exists for. GIN
        // indexes each key separately, which is exactly what jsonb_exists asks.
        $this->addSql('CREATE INDEX idx_calendar_event_remote_instances ON calendar_event USING gin (remote_instances)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_calendar_event_remote_instances');
        $this->addSql('ALTER TABLE calendar_event DROP remote_instances');
    }
}
