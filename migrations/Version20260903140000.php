<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Let the change log record the collections too, not only what is in them.
 *
 * calendar_change_log arrived recording events, and Calendar/get was left
 * returning the constant string "fixed" with a note explaining that renaming or
 * recolouring a calendar moved nothing. That note argued the gap was small — a
 * user has a handful of calendars and Calendar/get returns all of them by
 * default — which is true and is still not a reason for a method to lie about
 * its state. A client that trusts the token skips a refetch it needed, and the
 * sidebar keeps the old name until something unrelated forces a reload.
 *
 * WHY NULLABLE RATHER THAN A SECOND TABLE
 * ───────────────────────────────────────
 * A collection's own change has no event, so event_id and event_uid become
 * nullable and their absence *is* what marks the row as being about the
 * calendar itself. One discriminator, not two: an explicit target column
 * alongside a nullable id would be a second source of truth that could disagree
 * with the first, and the disagreement would be silent.
 *
 * The alternative was a sibling table, and it was rejected for what it costs
 * everywhere else: a second sequence, a second repository, and a second copy of
 * the token arithmetic in CalendarChangeReader — which is the part with the
 * subtle guards in it, and therefore the last part worth having two of.
 *
 * WHAT EACH READER NOW FILTERS
 * ────────────────────────────
 * Nothing reads the table whole. CalDAV's sync-collection and JMAP's
 * CalendarEvent/changes both want event rows and say `event_id IS NOT NULL`;
 * Calendar/changes wants the others. A reader that forgot the filter would
 * report a renamed calendar as a changed event, and the client would fetch an
 * href built from a null UID.
 *
 * NO BACKFILL, AND NO INDEX CHANGE
 * ────────────────────────────────
 * Existing rows are all event rows and already say so by having an event_id.
 * The two indexes lead with calendar_id and user_id and carry the sequence, so
 * they serve the new filtered reads exactly as well as the old unfiltered ones
 * — the nullable column is a residual predicate on rows already narrowed to one
 * collection or one user.
 */
final class Version20260903140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow calendar_change_log rows that are about a calendar rather than an event';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar_change_log ALTER COLUMN event_id DROP NOT NULL');
        $this->addSql('ALTER TABLE calendar_change_log ALTER COLUMN event_uid DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Collection rows cannot satisfy NOT NULL, so they go. A client holding
        // a Calendar/changes token from before the rollback fails ctype_digit
        // in CalendarChangeReader and is told to resync, which is the correct
        // degradation and the one CalendarState already described.
        $this->addSql('DELETE FROM calendar_change_log WHERE event_id IS NULL');
        $this->addSql('ALTER TABLE calendar_change_log ALTER COLUMN event_id SET NOT NULL');
        $this->addSql('ALTER TABLE calendar_change_log ALTER COLUMN event_uid SET NOT NULL');
    }
}
