<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One local calendar per remote calendar, per source — and a repair for the
 * installs that already have two.
 *
 * WHAT GOES WRONG WITHOUT THIS
 * ────────────────────────────
 * Two Calendar rows carrying the same remote_id under one account are two
 * mirrors of one calendar at the provider, and everything downstream treats
 * them as separate places. The sweep syncs both, so every event the provider
 * holds is pulled twice and drawn twice. The editor's calendar list offers both
 * — see EventCopyResolver — so "put this on my work calendar too" sends one
 * meeting to one Google calendar TWICE, as two events with two provider ids and
 * two iCalUIDs, and nothing here can ever merge those again: they are separate
 * objects to Google, to every other client reading it, and to EventClusterer,
 * which identifies a meeting by its UID.
 *
 * CalendarDiscoverer already refuses to subscribe a remote it is mirroring,
 * which is why this state is rare rather than routine. Rare is not never: two
 * submits of the subscribe form race each other through that check, and
 * ConfigBackupUserRestorer writes remote_id straight from a document without
 * asking what is already there. An application-level check that nothing
 * enforces is a convention, and this is the constraint that makes it a fact.
 *
 * WHY (account_id, remote_id) AND (integration_id, remote_id), NOT
 * (usr_id, remote_id)
 * ───────────────────────────────────────────────────────────────
 * The obvious key is the wrong one and would break a working install on the
 * first boot after the upgrade. IcsUrlCalendarDriver::REMOTE_ID is the CONSTANT
 * `feed` — a subscribed .ics address is one calendar, so the driver has nothing
 * to distinguish and deliberately does not invent an id — so every feed a user
 * subscribes to carries the same remote_id, and a user with two of them is
 * perfectly ordinary. What separates them is the Integration each one hangs
 * off, which is exactly what the second index keys on.
 *
 * No WHERE clause, and none is needed: PostgreSQL treats NULLs as distinct in a
 * unique index, so the ordinary state of both columns — a local calendar with
 * no remote at all, a per-account calendar with no integration — is
 * unconstrained however many rows are in it. This is the same property
 * uniq_calendar_push_channel_id already relies on, and it is stated there too.
 *
 * WHAT HAPPENS TO AN INSTALL THAT ALREADY HAS DUPLICATES
 * ──────────────────────────────────────────────────────
 * The index would refuse to be created and the migration would fail, which on
 * this deployment means the container does not come up — starting the new image
 * IS running the migrations. So the duplicates are repaired first, in the same
 * transaction, and the repair is deliberately the least destructive one
 * available: NOTHING IS DELETED.
 *
 * The oldest row per (source, remote) keeps the subscription. Every later one is
 * DETACHED from the remote — remote_id and the sync token cleared, the role
 * dropped to `custom`, read-only lifted, the push registration forgotten — which
 * turns it into an ordinary local calendar. Its events stay exactly where they
 * are and under it, including anything that only ever existed here: an event
 * extracted from mail into a mirrored calendar has no copy at the provider, and
 * deleting the row to tidy the schema would destroy it. The user is left with a
 * calendar they can rename, keep or delete, and re-subscribing to the remote is
 * one tick on the settings screen.
 *
 * The push columns are cleared rather than left because they are what an
 * unauthenticated webhook resolves a notification by. A detached row holding a
 * live channel id would go on being the row Google's notifications resolve to
 * for the rest of the registration's week — pointing at a calendar that no
 * longer syncs. Nothing is sent to the provider to stop the channel; it expires
 * on its own, and a migration is not a place to make network calls.
 *
 * `role = 'custom'` rather than leaving it `remote`: Calendar::isSynced() reads
 * the role AND the id together, so a `remote` row with a null id is a state the
 * subscribe flow uses for a calendar it has not finished binding — which is not
 * what this is. Custom is also what makes the row deletable
 * (CalendarRole::isDeletable), and being able to delete it is the whole point of
 * keeping it.
 */
final class Version20260902110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'One calendar per remote calendar per source: detach any duplicate mirrors, then constrain them.';
    }

    public function up(Schema $schema): void
    {
        $this->detachDuplicates('account_id');
        $this->detachDuplicates('integration_id');

        $this->addSql('CREATE UNIQUE INDEX uniq_calendar_account_remote ON calendar (account_id, remote_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_calendar_integration_remote ON calendar (integration_id, remote_id)');
    }

    public function down(Schema $schema): void
    {
        // The index goes; the detached calendars stay detached. There is no
        // honest way back — which row was the duplicate is not recorded
        // anywhere, and re-pointing a calendar at a remote by guessing would be
        // worse than the state this leaves behind.
        $this->addSql('DROP INDEX uniq_calendar_account_remote');
        $this->addSql('DROP INDEX uniq_calendar_integration_remote');
    }

    /**
     * Every mirror of one remote but the oldest, turned back into a plain local
     * calendar.
     *
     * `id > MIN(id)` rather than a window function so the statement reads the
     * same on any PostgreSQL this has ever run on, and because the rule is
     * genuinely "the first one wins": it is the row the events, the sync token
     * and the push channel already belong to.
     */
    private function detachDuplicates(string $source): void
    {
        $this->addSql(sprintf(<<<'SQL'
            UPDATE calendar
               SET remote_id = NULL,
                   sync_token = NULL,
                   role = 'custom',
                   is_read_only = false,
                   push_channel_id = NULL,
                   push_resource_id = NULL,
                   push_secret = NULL,
                   push_expires_at = NULL,
                   last_sync_error = NULL,
                   sync_backoff_until = NULL,
                   sync_failure_count = 0
             WHERE %1$s IS NOT NULL
               AND remote_id IS NOT NULL
               AND id > (
                   SELECT MIN(oldest.id)
                     FROM calendar AS oldest
                    WHERE oldest.%1$s = calendar.%1$s
                      AND oldest.remote_id = calendar.remote_id
               )
            SQL, $source));
    }
}
