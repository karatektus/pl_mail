<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * That calendar_event.remote_id can hold a real CalDAV href.
 *
 * The failure this guards against was found by connecting a live Baïkal
 * calendar, not by the suite: 6 of 765 events had hrefs of 258 characters and
 * the single oversized row failed the entire pull with
 * `SQLSTATE[22001]: value too long for type character varying(255)`. Doctrine
 * reported it as a generic query exception, so the message said nothing about
 * which column or which resource.
 *
 * Checked against the live catalogue rather than the migration's own SQL, for
 * the same reason the other migration tests are: a migration that was written
 * and never run leaves the entity mapping valid and every in-memory test
 * passing, and the first write on a deployed instance is what finds out.
 */
final class CalendarEventRemoteIdMigrationTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testRemoteIdIsUnbounded(): void
    {
        $row = $this->connection->fetchAssociative(
            "SELECT data_type, character_maximum_length
               FROM information_schema.columns
              WHERE table_name = 'calendar_event' AND column_name = 'remote_id'",
        );

        self::assertNotFalse($row, 'calendar_event.remote_id is missing');

        self::assertSame(
            'text',
            $row['data_type'],
            'remote_id must not be length-bounded: the CalDAV server decides how long its own hrefs are',
        );

        self::assertNull(
            $row['character_maximum_length'],
            'a bounded remote_id is the bug this migration removed — Baikal emitted a 258-character href',
        );
    }

    /**
     * The exact length that failed, written and read back.
     *
     * The catalogue check above proves the column type; this proves the row
     * actually stores, because a varchar(255) in front of a TEXT column would
     * pass the first test and still truncate.
     */
    public function testA258CharacterHrefRoundTrips(): void
    {
        // The real href Baïkal produced for a Google-exported event: copied
        // verbatim rather than synthesised, because the point is that a real
        // server's naming is long and the fixture should not be shorter than
        // the thing that broke it.
        $href = 'https://cal.thearvans.com/dav.php/calendars/mike@thearvans.com/main/'
            . 'gcal-_60q30c1g60o30e1i60o4ac1g60rj8gpl88rj2c1h84s34h9g60s30c1g60o30c1g'
            . '6so32dq66kpk6ghi74r48e1g64o30c1g60o30c1g60o30c1g60o32c1g60o30c1g6sqj0c1g'
            . '70pk4hhl6gs34hhk6ssj6g9o8cr30chk74s4achn8cp0.ics';

        self::assertGreaterThan(255, strlen($href), 'precondition: the fixture is longer than the old limit');

        // Built here rather than borrowed from whatever rows happen to exist:
        // a fixture that skips when the database is empty asserts nothing, and
        // the test stack's database is empty. Both parents are created inside
        // the transaction and rolled back in tearDown.
        $userId = (int) $this->connection->fetchOne(
            'INSERT INTO "user" (email, name_first, name_last, password, roles, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, now(), now()) RETURNING id',
            ['remote-id-' . bin2hex(random_bytes(4)) . '@plmail.test', 'Probe', 'Fixture', 'x', '[]'],
        );

        $calendarId = (int) $this->connection->fetchOne(
            'INSERT INTO calendar (name, usr_id, created_at, updated_at)
             VALUES (?, ?, now(), now()) RETURNING id',
            ['Probe calendar', $userId],
        );

        $this->connection->executeStatement(
            'INSERT INTO calendar_event (uid, remote_id, starts_at, ends_at, calendar_id, usr_id, created_at, updated_at)
             VALUES (?, ?, now(), now(), ?, ?, now(), now())',
            ['probe-' . bin2hex(random_bytes(4)), $href, $calendarId, $userId],
        );

        $stored = $this->connection->fetchOne(
            'SELECT remote_id FROM calendar_event WHERE remote_id = ?',
            [$href],
        );

        self::assertSame($href, $stored, 'a href longer than 255 characters must survive storage intact');
    }
}
