<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Two-way calendar sync needs to know two things the schema could not answer:
 * whether a local event owes the remote a write, and how a calendar's last
 * conversation with its remote went.
 *
 * calendar_event.sync_state is the first. The alternative was to keep a shadow
 * copy of the last-pushed object and diff against it, which doubles the storage
 * for every event and still answers wrongly whenever the comparison and the
 * serialiser disagree about key order or a null. Recording the intent at the
 * moment of the edit is cheaper and exact. It is added NOT NULL with a default
 * of 'clean' rather than nullable-then-backfilled, and that is safe on a table
 * with rows precisely because it has a default — the nullable → populate → NOT
 * NULL dance is for columns that have none. 'clean' is also the correct value
 * for every row that exists today: nothing has ever been pushed anywhere.
 *
 * synced_at is kept beside it rather than derived from updated_at, which the
 * timestampable trait bumps on the sync's own writes and so can never answer
 * "how stale is this against the remote?".
 *
 * calendar.last_synced_at and last_sync_error mirror integration.last_checked_at
 * and last_error deliberately, down to the semantics: the error is cleared on
 * the next success rather than accumulating, because a stale error beside a
 * working calendar teaches people to ignore the field. last_synced_at is also
 * what the fifteen-minute sweep orders by, so it is written even on a run that
 * found nothing to do.
 *
 * Both indexes serve the sweep. calendar_id leads each of them because every
 * query the engine makes is already scoped to one calendar; leading with
 * sync_state would put the whole table's clean rows in front of the answer, and
 * leading with remote_id would not serve the "which rows on this calendar did a
 * full read not mention?" query at all.
 */
final class Version20260804090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let an event say it owes the remote a write, and a calendar say how its last sync went';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar ADD last_synced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE calendar ADD last_sync_error TEXT DEFAULT NULL');
        $this->addSql("ALTER TABLE calendar_event ADD sync_state VARCHAR(20) DEFAULT 'clean' NOT NULL");
        $this->addSql('ALTER TABLE calendar_event ADD synced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_calendar_event_calendar_remote ON calendar_event (calendar_id, remote_id)');
        $this->addSql('CREATE INDEX idx_calendar_event_calendar_sync_state ON calendar_event (calendar_id, sync_state)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_calendar_event_calendar_sync_state');
        $this->addSql('DROP INDEX idx_calendar_event_calendar_remote');
        $this->addSql('ALTER TABLE calendar_event DROP synced_at');
        $this->addSql('ALTER TABLE calendar_event DROP sync_state');
        $this->addSql('ALTER TABLE calendar DROP last_sync_error');
        $this->addSql('ALTER TABLE calendar DROP last_synced_at');
    }
}
