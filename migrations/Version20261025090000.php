<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Widen calendar_event.remote_id, because real CalDAV servers produce ids that
 * do not fit in 255 characters.
 *
 * The column holds the absolute href of a mirrored event. For a plain event
 * that is short — `…/main/Caldav-1-4` — but servers name resources after their
 * own encoding of the event, and Google's is long: an event exported from
 * Google Calendar and imported into Baïkal landed at 258 characters. Connecting
 * that calendar failed the whole pull with
 * `SQLSTATE[22001]: value too long for type character varying(255)`, which
 * Doctrine reported as a generic "An exception occurred while executing a
 * query" with no hint that the problem was one resource out of 765.
 *
 * TEXT rather than a larger varchar: the href is opaque, the server sets its
 * length, and picking another number would only move the cliff. The column is in
 * idx_calendar_event_calendar_remote, which the type change rebuilds; a btree
 * takes text up to about 2.7 kB, far past any href a server writes.
 *
 * NOT REVERSIBLE WITHOUT LOSS, and the down() says so rather than pretending:
 * narrowing back to 255 fails on any row already longer than that, which would
 * be an error at migration time rather than a surprise later.
 */
final class Version20261025090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen calendar_event.remote_id to TEXT: CalDAV servers emit hrefs longer than 255 (Baikal + Google exports reach 258), which failed the entire pull for the calendar';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar_event ALTER COLUMN remote_id TYPE TEXT');
    }

    public function down(Schema $schema): void
    {
        // Refuses rather than truncating: a shortened href points at a resource
        // that does not exist, and a silently broken mirror is worse than a
        // failed migration.
        $this->addSql(
            'DO $$ BEGIN '
            . "IF EXISTS (SELECT 1 FROM calendar_event WHERE length(remote_id) > 255) THEN "
            . "RAISE EXCEPTION 'cannot narrow remote_id: rows longer than 255 exist'; "
            . 'END IF; END $$',
        );
        $this->addSql('ALTER TABLE calendar_event ALTER COLUMN remote_id TYPE VARCHAR(255)');
    }
}
