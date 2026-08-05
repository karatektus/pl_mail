<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Ics;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\EventSource;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventRepository;
use App\Service\Calendar\Ics\IcsExporter;
use App\Service\Calendar\Ics\IcsImporter;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Importing an .ics adds what is not there and never adds it twice.
 *
 * The subject is duplication, because that is the only way an import goes wrong
 * in a way nobody can undo. The three cases it has to get right are all cases a
 * person meets by accident:
 *
 *   **The same file, imported twice.** Somebody presses the button again
 *   because nothing obvious happened the first time. The UID is the identity and
 *   the database enforces it per calendar, so the second import is an update —
 *   and writing a second row would not be a duplicate but a constraint
 *   violation, which arrives as a 500 on a form.
 *
 *   **A file exported from plMail, imported back into plMail.** The round trip
 *   is what makes export worth having: exporting a calendar to move it and
 *   re-importing it must produce the calendar, not a second copy of it. This is
 *   also the test that would catch the export dropping the UID.
 *
 *   **A meeting that already arrived as an invitation.** The organiser's UID is
 *   on the mail account's calendar already, so importing the organiser's .ics
 *   onto a different calendar is the third copy of one meeting. It is refused
 *   and counted, because two rows that disagree are drawn as two chips —
 *   EventClusterer merges copies only while they agree.
 *
 * Against a real container and a real database, in a transaction that is never
 * committed. Every collaborator is final so none can be doubled, and the claim
 * worth pinning is the one that emerges from them together: what is in the table
 * afterwards.
 */
final class IcsImporterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private IcsImporter $importer;
    private IcsExporter $exporter;
    private CalendarEventRepository $events;
    private User $user;
    private Calendar $calendar;
    private Calendar $other;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->importer   = $container->get(IcsImporter::class);
        $this->exporter   = $container->get(IcsExporter::class);
        $this->events     = $container->get(CalendarEventRepository::class);

        // Never committed, so the suite leaves nothing behind and can be run
        // repeatedly against the same database.
        $this->connection->beginTransaction();
        $this->seed();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAFileImportsItsEvents(): void
    {
        $result = $this->importer->import($this->calendar, $this->user, $this->twoEvents());
        $this->em->flush();

        self::assertSame(2, $result->imported);
        self::assertSame(0, $result->updated);
        self::assertCount(2, $this->onTheCalendar());

        $standup = $this->events->findOneByUid($this->calendar, 'meeting-1');

        self::assertNotNull($standup);
        self::assertSame('Standup', $standup->title);
        self::assertSame('Room 3', $standup->location);
        self::assertSame(EventSource::Ics, $standup->source);
    }

    public function testImportingTheSameFileTwiceUpdatesRatherThanDuplicating(): void
    {
        $this->importer->import($this->calendar, $this->user, $this->twoEvents());
        $this->em->flush();

        $second = $this->importer->import($this->calendar, $this->user, $this->twoEvents());
        $this->em->flush();

        self::assertSame(0, $second->imported);
        self::assertSame(2, $second->updated);
        self::assertCount(2, $this->onTheCalendar(), 'a second import must not add a second copy');
    }

    /**
     * The round trip, which is the whole reason export and import are one
     * feature. It also holds the UID: an export that dropped it would re-import
     * as a brand new event every time.
     */
    public function testAnExportedEventReimportsAsTheSameEventRatherThanASecondCopy(): void
    {
        $this->importer->import($this->calendar, $this->user, $this->twoEvents());
        $this->em->flush();

        $exported = '';

        foreach ($this->exporter->document($this->calendar, $this->onTheCalendar()) as $chunk) {
            $exported .= $chunk;
        }

        $result = $this->importer->import($this->calendar, $this->user, $exported);
        $this->em->flush();

        self::assertSame(0, $result->imported);
        self::assertSame(2, $result->updated);
        self::assertCount(2, $this->onTheCalendar());
    }

    /**
     * The meeting is already this user's, under the organiser's own UID, on
     * another list. Importing it again would draw it twice the moment the two
     * rows stopped agreeing.
     */
    public function testAnEventAlreadyOnAnotherCalendarIsLeftThereRatherThanCopied(): void
    {
        $this->existingEventOn($this->other, 'meeting-1');

        $result = $this->importer->import($this->calendar, $this->user, $this->twoEvents());
        $this->em->flush();

        self::assertSame(1, $result->imported, 'only the holiday is new');
        self::assertSame(1, $result->alreadyElsewhere);
        self::assertNull(
            $this->events->findOneByUid($this->calendar, 'meeting-1'),
            'the meeting stays where the invitation put it',
        );
    }

    /**
     * A VEVENT with no UID gets one derived from its own content, so a file that
     * has any can be imported twice without multiplying. sabre's own splitter
     * mints a random id here, which is the bug this names.
     */
    public function testAUidlessEventImportsOnceHoweverOftenTheFileIsImported(): void
    {
        $this->importer->import($this->calendar, $this->user, $this->uidlessEvent());
        $this->em->flush();

        $second = $this->importer->import($this->calendar, $this->user, $this->uidlessEvent());
        $this->em->flush();

        self::assertSame(0, $second->imported);
        self::assertCount(1, $this->onTheCalendar());
    }

    /**
     * A mirror of a published file cannot accept anything: rows written there
     * could never leave, and the engine's promise is that a driver is never
     * asked to push to one.
     */
    public function testAReadOnlyCalendarRefusesTheImportRatherThanAcceptingRowsItCannotSend(): void
    {
        $this->calendar->isReadOnly = true;

        $this->expectException(CalendarSyncPermanentException::class);
        $this->expectExceptionMessageMatches('/read-only/');

        $this->importer->import($this->calendar, $this->user, $this->twoEvents());
    }

    /**
     * One unusable component costs one event, never the file. A calendar
     * exported by hand routinely has one entry with something missing, and a
     * file that refused to import over it would be a file the user cannot use
     * at all.
     */
    public function testAnEventWithNoStartIsSkippedRatherThanFailingTheWholeFile(): void
    {
        $result = $this->importer->import($this->calendar, $this->user, $this->oneGoodOneBroken());
        $this->em->flush();

        self::assertSame(1, $result->imported);
        self::assertSame(1, $result->skipped);
        self::assertCount(1, $this->onTheCalendar());
    }

    /**
     * Two components under one UID with neither carrying a RECURRENCE-ID is a
     * file nobody should write and several exporters do. It must not become a
     * constraint violation on (calendar_id, uid).
     */
    public function testADuplicateUidInOneFileIsOneEventRatherThanA500(): void
    {
        $result = $this->importer->import($this->calendar, $this->user, $this->duplicateUids());
        $this->em->flush();

        self::assertSame(1, $result->imported);
        self::assertCount(1, $this->onTheCalendar());
    }

    /**
     * An all-day event is a DATE, not a DATETIME at midnight. Getting it wrong
     * shifts every all-day event by a day in some zones — which is how a
     * birthday arrives on the wrong day.
     */
    public function testAnAllDayEventArrivesAsAWholeDayRatherThanMidnightSomewhere(): void
    {
        $this->importer->import($this->calendar, $this->user, $this->twoEvents());
        $this->em->flush();

        $holiday = $this->events->findOneByUid($this->calendar, 'holiday-1');

        self::assertNotNull($holiday);
        self::assertTrue($holiday->isAllDay);
        self::assertNull($holiday->timeZone, 'an all-day event is floating; a zone on it is what shifts it');
        self::assertSame('2026-05-01 00:00:00', $holiday->startsAt?->format('Y-m-d H:i:s'));
    }

    /** A recurring meeting must recur, not appear once — see RecurrenceRuleConverter. */
    public function testARecurringMeetingKeepsItsRuleRatherThanArrivingOnce(): void
    {
        $this->importer->import($this->calendar, $this->user, $this->weeklySeries());
        $this->em->flush();

        $series = $this->events->findOneByUid($this->calendar, 'series-1');

        self::assertNotNull($series);
        self::assertTrue($series->isRecurring);
        self::assertSame('weekly', $series->jscalendar['recurrenceRules'][0]['frequency'] ?? null);
        self::assertGreaterThan(1, count($series->occurrences));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /** @return list<CalendarEvent> */
    private function onTheCalendar(): array
    {
        return $this->events->findBy(['calendar' => $this->calendar], ['uid' => 'ASC']);
    }

    private function existingEventOn(Calendar $calendar, string $uid): CalendarEvent
    {
        $utc = new DateTimeZone('UTC');

        $event             = new CalendarEvent();
        $event->calendar   = $calendar;
        $event->usr        = $this->user;
        $event->uid        = $uid;
        $event->title      = 'Standup (from the invitation)';
        $event->startsAt   = new DateTimeImmutable('2026-08-10 08:00', $utc);
        $event->endsAt     = new DateTimeImmutable('2026-08-10 09:00', $utc);
        $event->jscalendar = ['@type' => 'Event', 'uid' => $uid];

        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'ics-import-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Import';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $calendar           = new Calendar();
        $calendar->usr      = $user;
        $calendar->name     = 'Import target';
        $calendar->role     = CalendarRole::Custom;
        $calendar->timeZone = 'Europe/Berlin';
        $this->em->persist($calendar);

        $other           = new Calendar();
        $other->usr      = $user;
        $other->name     = 'Mail account';
        $other->role     = CalendarRole::Custom;
        $other->timeZone = 'Europe/Berlin';
        $this->em->persist($other);

        $this->em->flush();

        $this->user     = $user;
        $this->calendar = $calendar;
        $this->other    = $other;
    }

    // ── Documents ─────────────────────────────────────────────────────────────

    private function twoEvents(): string
    {
        return <<<'ICS'
            BEGIN:VCALENDAR
            VERSION:2.0
            PRODID:-//Test//EN
            BEGIN:VEVENT
            UID:meeting-1
            DTSTAMP:20260101T000000Z
            DTSTART;TZID=Europe/Berlin:20260810T100000
            DTEND;TZID=Europe/Berlin:20260810T110000
            SUMMARY:Standup
            LOCATION:Room 3
            END:VEVENT
            BEGIN:VEVENT
            UID:holiday-1
            DTSTAMP:20260101T000000Z
            DTSTART;VALUE=DATE:20260501
            DTEND;VALUE=DATE:20260502
            SUMMARY:Labour Day
            END:VEVENT
            END:VCALENDAR
            ICS;
    }

    private function uidlessEvent(): string
    {
        return <<<'ICS'
            BEGIN:VCALENDAR
            VERSION:2.0
            PRODID:-//Test//EN
            BEGIN:VEVENT
            DTSTAMP:20260101T000000Z
            DTSTART;VALUE=DATE:20261003
            DTEND;VALUE=DATE:20261004
            SUMMARY:Unity Day
            END:VEVENT
            END:VCALENDAR
            ICS;
    }

    private function oneGoodOneBroken(): string
    {
        return <<<'ICS'
            BEGIN:VCALENDAR
            VERSION:2.0
            PRODID:-//Test//EN
            BEGIN:VEVENT
            UID:meeting-1
            DTSTAMP:20260101T000000Z
            DTSTART:20260810T080000Z
            DTEND:20260810T090000Z
            SUMMARY:Standup
            END:VEVENT
            BEGIN:VEVENT
            UID:nowhere-1
            DTSTAMP:20260101T000000Z
            SUMMARY:An event with no start at all
            END:VEVENT
            END:VCALENDAR
            ICS;
    }

    private function duplicateUids(): string
    {
        return <<<'ICS'
            BEGIN:VCALENDAR
            VERSION:2.0
            PRODID:-//Test//EN
            BEGIN:VEVENT
            UID:meeting-1
            DTSTAMP:20260101T000000Z
            DTSTART:20260810T080000Z
            DTEND:20260810T090000Z
            SUMMARY:Standup
            END:VEVENT
            BEGIN:VEVENT
            UID:meeting-1
            DTSTAMP:20260101T000000Z
            DTSTART:20260811T080000Z
            DTEND:20260811T090000Z
            SUMMARY:Standup again, same UID
            END:VEVENT
            END:VCALENDAR
            ICS;
    }

    private function weeklySeries(): string
    {
        return <<<'ICS'
            BEGIN:VCALENDAR
            VERSION:2.0
            PRODID:-//Test//EN
            BEGIN:VEVENT
            UID:series-1
            DTSTAMP:20260101T000000Z
            DTSTART;TZID=Europe/Berlin:20260810T100000
            DTEND;TZID=Europe/Berlin:20260810T110000
            RRULE:FREQ=WEEKLY;COUNT=6
            SUMMARY:Weekly
            END:VEVENT
            END:VCALENDAR
            ICS;
    }
}
