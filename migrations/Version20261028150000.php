<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A subscribed feed's calendar is identified by its address, hashed, rather
 * than by the constant `feed` (#424).
 *
 * Every feed carried the same remote id, and CalendarDiscoverer compares remote
 * ids across a user's sources to stop one remote being mirrored twice. So the
 * second feed anybody subscribed to read as "already mirrored through" the
 * first and was never subscribed: its connection showed in settings, its
 * events never did. IcsUrlCalendarDriver::remoteIdOf() now answers the SHA-256
 * of the address, and the calendars already mirroring a feed are rewritten to
 * the same value here — otherwise the next discovery would not recognise them
 * as the calendar of their own subscription.
 *
 * The hash is computed in SQL and has to equal PHP's hash('sha256', …) over the
 * same bytes, which is why the driver hashes the address as stored rather than
 * as normalised for fetching: base_url is the one value both sides can see.
 * sha256() is PostgreSQL 11 and later; every image this has shipped with is
 * newer.
 *
 * Feeds that were added after a first one and never got a calendar need
 * nothing here. Opening "Find calendars" on them again now offers the calendar
 * as it should have the first time.
 */
final class Version20261028150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Identify a subscribed feed\'s calendar by the SHA-256 of its address instead of the constant "feed"';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE calendar
               SET remote_id = encode(sha256(convert_to(integration.base_url, 'UTF8')), 'hex')
              FROM integration
             WHERE calendar.integration_id = integration.id
               AND integration.provider = 'ics'
               AND integration.base_url IS NOT NULL
               AND calendar.remote_id = 'feed'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE calendar
               SET remote_id = 'feed'
              FROM integration
             WHERE calendar.integration_id = integration.id
               AND integration.provider = 'ics'
               AND calendar.remote_id IS NOT NULL
            SQL);
    }
}
