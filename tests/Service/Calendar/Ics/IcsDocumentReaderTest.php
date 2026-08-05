<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Ics;

use App\Domain\Exception\CalendarSyncPermanentException;
use App\Service\Calendar\Ics\IcsDocumentReader;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Property\ICalendar\DateTime as ICalDateTime;
use Sabre\VObject\Reader;

/**
 * Splitting one iCalendar file into the per-meeting resources the rest of the
 * calendar knows how to read.
 *
 * The claim: **a resource cut out of a document still means what it meant
 * inside it.** A feed and an uploaded file are one VCALENDAR holding everything;
 * every other reader here — CalDavEventConverter, and through it the whole
 * engine — expects CalDAV's shape, where a resource is one meeting. The cut is
 * where the meaning can be lost, and it can be lost in three ways, each of which
 * has a test below.
 *
 * The first is the one that costs data silently. A `TZID=` is a reference into
 * the file it was written in, so a VEVENT lifted out without the VTIMEZONE it
 * names loses its zone and falls back to the process default — every event in a
 * Google-exported feed lands two hours out, in the direction nobody notices
 * until a meeting is missed. That is what testEveryResourceCarriesTheZones…
 * exists to hold, and deleting the clone loop in IcsDocumentReader::resource()
 * is exactly the "simplification" it guards.
 *
 * A plain TestCase and no container. Nothing here touches Doctrine, the class
 * takes no collaborators at all, and the subject is the bytes in and the bytes
 * out — a booted kernel would add three seconds to a test whose whole input is
 * a heredoc.
 */
final class IcsDocumentReaderTest extends TestCase
{
    private IcsDocumentReader $reader;

    protected function setUp(): void
    {
        $this->reader = new IcsDocumentReader();
    }

    /**
     * The regression this file exists for.
     *
     * `TZID:My Zone` names nothing PHP or sabre knows; the only thing that can
     * resolve it is the X-LIC-LOCATION inside the document's own VTIMEZONE. So
     * a resource that did not carry the zone table would read 10:00 Berlin as
     * 10:00 UTC. Asserted on the *instant*, not on the text, because the text
     * looks right either way — which is precisely why this was worth a test.
     */
    public function testEveryResourceCarriesTheDocumentsTimeZonesRatherThanTheEventAlone(): void
    {
        $resources = $this->resourcesOf($this->documentWithACustomZone());

        self::assertArrayHasKey('zoned-1', $resources);

        self::assertSame(
            '2026-08-10T08:00:00+00:00',
            $this->startOf($resources['zoned-1']),
            'a VEVENT cut out of its document must keep the zone its TZID names',
        );
    }

    /**
     * A series and the instances somebody moved are one meeting, and the rest of
     * the calendar is built on that: emitted separately, a moved instance
     * becomes an event of its own, drawn beside a series that still shows it at
     * its original time.
     */
    public function testComponentsSharingAUidComeOutAsOneResourceRatherThanSeveral(): void
    {
        $resources = $this->resourcesOf($this->documentWithASeries());

        self::assertCount(1, $resources, 'a master and its override are one resource');
        self::assertSame(2, substr_count($resources['series-1'], 'BEGIN:VEVENT'));
        self::assertStringContainsString('RECURRENCE-ID', $resources['series-1']);
    }

    /**
     * A UID-less VEVENT violates RFC 5545 and arrives anyway. It must be given
     * an identity derived from its own content, so importing the same file twice
     * — or re-polling a feed that has one — matches rather than multiplies.
     *
     * sabre's own splitter mints sha1(microtime()) here, which is the bug this
     * names: with a random id, every sweep of such a feed adds a fresh copy of
     * every event in it, forever, while nothing at the far end changes.
     */
    public function testAUidlessEventKeepsTheSameIdentityOnASecondReadRatherThanANewOne(): void
    {
        $first  = array_keys($this->resourcesOf($this->documentWithoutAUid()));
        $second = array_keys($this->resourcesOf($this->documentWithoutAUid()));

        self::assertCount(1, $first);
        self::assertStringEndsWith(IcsDocumentReader::SYNTHETIC_UID_SUFFIX, $first[0]);
        self::assertSame($first, $second, 'the same event must import under the same identity twice');
    }

    /**
     * Two events with nothing in common but a missing UID must not collapse into
     * one. The identity is derived from what the event says about itself, so
     * "derived" has to mean derived from *this* event.
     */
    public function testTwoUidlessEventsGetDifferentIdentitiesRatherThanCollidingOnOne(): void
    {
        self::assertCount(2, $this->resourcesOf($this->documentWithTwoUidlessEvents()));
    }

    /**
     * plMail has no model for a task or a journal entry, and importing a task
     * list as a wall of zero-length events is worse than not importing it.
     */
    public function testTasksAndJournalsAreDroppedRatherThanImportedAsEvents(): void
    {
        $resources = $this->resourcesOf($this->documentWithATask());

        self::assertSame(['meeting-1'], array_keys($resources));
    }

    /**
     * The single most likely thing a user pastes into the feed form is a link to
     * a web page, because that is what a "Subscribe" button copies about half
     * the time. It has to arrive as a sentence they can act on rather than as a
     * 500.
     */
    public function testAWebPageIsRefusedWithASentenceRatherThanA500(): void
    {
        $this->expectException(CalendarSyncPermanentException::class);
        $this->expectExceptionMessageMatches('/not a calendar file/');

        $this->reader->read('<!doctype html><html><body>Subscribe to my calendar</body></html>');
    }

    /** An empty body is its own failure: "there is nothing here", not "this is not a calendar". */
    public function testAnEmptyDocumentIsRefusedRatherThanReadAsAnEmptyCalendar(): void
    {
        $this->expectException(CalendarSyncPermanentException::class);

        $this->reader->read("   \n  ");
    }

    /** The name and zone a publisher states, which is all a feed says about itself. */
    public function testTheCalendarsOwnNameAndZoneAreRead(): void
    {
        $document = $this->reader->read($this->documentWithASeries());

        self::assertSame('Team calendar', $this->reader->nameOf($document));
        self::assertSame('Europe/Berlin', $this->reader->timeZoneOf($document));
    }

    /**
     * A zone name PHP cannot resolve is null rather than a guess: the caller
     * falls back to the user's own zone, which is a better answer than a wrong
     * one — and this value only decides how the calendar is displayed, never
     * where an event lands.
     */
    public function testAnUnrecognisableCalendarZoneIsNullRatherThanAGuess(): void
    {
        $document = $this->reader->read($this->documentWithAWindowsCalendarZone());

        self::assertNull($this->reader->timeZoneOf($document));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array<string,string> */
    private function resourcesOf(string $ics): array
    {
        $resources = [];

        foreach ($this->reader->resources($this->reader->read($ics)) as $uid => $resource) {
            $resources[$uid] = $resource;
        }

        return $resources;
    }

    /**
     * The first event's start, as a UTC instant.
     *
     * Read as an instant rather than as text on purpose: the text is right
     * either way. `DTSTART;TZID=My Zone:20260810T100000` is the same eleven
     * characters whether the zone resolved or not, and only the instant says
     * which happened.
     */
    private function startOf(string $resource): string
    {
        $document = Reader::read($resource, Reader::OPTION_FORGIVING);

        self::assertInstanceOf(VCalendar::class, $document);

        $vevent = $document->VEVENT[0] ?? null;

        self::assertInstanceOf(VEvent::class, $vevent);

        $start = $vevent->DTSTART ?? null;

        self::assertInstanceOf(ICalDateTime::class, $start);

        return $start->getDateTime()->setTimezone(new DateTimeZone('UTC'))->format('c');
    }

    // ── Documents ─────────────────────────────────────────────────────────────

    /**
     * A zone whose TZID is a private label, resolvable only through the
     * VTIMEZONE's X-LIC-LOCATION. Exactly what Google's exports contain.
     */
    private function documentWithACustomZone(): string
    {
        return <<<'ICS'
            BEGIN:VCALENDAR
            VERSION:2.0
            PRODID:-//Test//EN
            BEGIN:VTIMEZONE
            TZID:My Zone
            X-LIC-LOCATION:Europe/Berlin
            BEGIN:STANDARD
            DTSTART:16011028T030000
            TZOFFSETFROM:+0200
            TZOFFSETTO:+0100
            END:STANDARD
            END:VTIMEZONE
            BEGIN:VEVENT
            UID:zoned-1
            DTSTAMP:20260101T000000Z
            DTSTART;TZID=My Zone:20260810T100000
            DTEND;TZID=My Zone:20260810T110000
            SUMMARY:Standup
            END:VEVENT
            END:VCALENDAR
            ICS;
    }

    private function documentWithASeries(): string
    {
        return <<<'ICS'
            BEGIN:VCALENDAR
            VERSION:2.0
            PRODID:-//Test//EN
            X-WR-CALNAME:Team calendar
            X-WR-TIMEZONE:Europe/Berlin
            BEGIN:VEVENT
            UID:series-1
            DTSTAMP:20260101T000000Z
            DTSTART:20260810T080000Z
            DTEND:20260810T090000Z
            RRULE:FREQ=WEEKLY
            SUMMARY:Weekly
            END:VEVENT
            BEGIN:VEVENT
            UID:series-1
            RECURRENCE-ID:20260817T080000Z
            DTSTAMP:20260101T000000Z
            DTSTART:20260817T120000Z
            DTEND:20260817T130000Z
            SUMMARY:Weekly (moved)
            END:VEVENT
            END:VCALENDAR
            ICS;
    }

    private function documentWithoutAUid(): string
    {
        return <<<'ICS'
            BEGIN:VCALENDAR
            VERSION:2.0
            PRODID:-//Test//EN
            BEGIN:VEVENT
            DTSTAMP:20260101T000000Z
            DTSTART;VALUE=DATE:20260501
            DTEND;VALUE=DATE:20260502
            SUMMARY:Labour Day
            END:VEVENT
            END:VCALENDAR
            ICS;
    }

    private function documentWithTwoUidlessEvents(): string
    {
        return <<<'ICS'
            BEGIN:VCALENDAR
            VERSION:2.0
            PRODID:-//Test//EN
            BEGIN:VEVENT
            DTSTAMP:20260101T000000Z
            DTSTART;VALUE=DATE:20260501
            DTEND;VALUE=DATE:20260502
            SUMMARY:Labour Day
            END:VEVENT
            BEGIN:VEVENT
            DTSTAMP:20260101T000000Z
            DTSTART;VALUE=DATE:20261003
            DTEND;VALUE=DATE:20261004
            SUMMARY:Unity Day
            END:VEVENT
            END:VCALENDAR
            ICS;
    }

    private function documentWithATask(): string
    {
        return <<<'ICS'
            BEGIN:VCALENDAR
            VERSION:2.0
            PRODID:-//Test//EN
            BEGIN:VTODO
            UID:task-1
            DTSTAMP:20260101T000000Z
            SUMMARY:Buy milk
            END:VTODO
            BEGIN:VJOURNAL
            UID:journal-1
            DTSTAMP:20260101T000000Z
            SUMMARY:Notes
            END:VJOURNAL
            BEGIN:VEVENT
            UID:meeting-1
            DTSTAMP:20260101T000000Z
            DTSTART:20260810T080000Z
            DTEND:20260810T090000Z
            SUMMARY:Standup
            END:VEVENT
            END:VCALENDAR
            ICS;
    }

    private function documentWithAWindowsCalendarZone(): string
    {
        return <<<'ICS'
            BEGIN:VCALENDAR
            VERSION:2.0
            PRODID:-//Test//EN
            X-WR-TIMEZONE:W. Europe Standard Time
            BEGIN:VEVENT
            UID:meeting-1
            DTSTAMP:20260101T000000Z
            DTSTART:20260810T080000Z
            DTEND:20260810T090000Z
            SUMMARY:Standup
            END:VEVENT
            END:VCALENDAR
            ICS;
    }
}
