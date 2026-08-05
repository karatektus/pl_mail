<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Ics;

use App\Domain\Enum\Calendar\EventStatus;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Service\Calendar\Ics\IcsExporter;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A calendar written out as one file.
 *
 * The per-event mapping is CalDavEventConverter's and is tested there; what is
 * claimed here is that composing a *document* out of it does not damage it.
 * Three ways it could:
 *
 *   **One VCALENDAR, not one per event.** A file containing several BEGIN:VCALENDAR
 *   blocks is what naive concatenation produces, and most readers import only
 *   the first — so a calendar with four hundred events exports as one.
 *
 *   **The envelope's own properties.** The calendar's name has to survive
 *   escaping: a calendar called "Work, home; both" written by hand would carry
 *   two unescaped delimiters and every parser would read a different value than
 *   the one stored.
 *
 *   **CRLF.** iCalendar's line break is not negotiable (RFC 5545 §3.1). A bare
 *   newline on the two lines this class writes itself produces a file Outlook
 *   refuses and everything else silently accepts, which is the worst way to find
 *   out.
 *
 * A KernelTestCase for one collaborator: the converter is assembled from
 * services of its own, so building it here would mean a test that needs
 * rewriting whenever it grows a dependency — and would then be asserting about
 * a converter that is not the one that ships. Nothing touches the database.
 */
final class IcsExporterTest extends KernelTestCase
{
    private IcsExporter $exporter;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->exporter = self::getContainer()->get(IcsExporter::class);
    }

    public function testAWholeCalendarIsOneDocumentRatherThanOnePerEvent(): void
    {
        $document = $this->export([$this->event('a-1', 'Standup'), $this->event('a-2', 'Retro')]);

        self::assertSame(1, substr_count($document, 'BEGIN:VCALENDAR'));
        self::assertSame(1, substr_count($document, 'END:VCALENDAR'));
        self::assertSame(2, substr_count($document, 'BEGIN:VEVENT'));
        self::assertStringContainsString('UID:a-1', $document);
        self::assertStringContainsString('UID:a-2', $document);
    }

    public function testTheDocumentNamesTheCalendarItCameFrom(): void
    {
        $document = $this->export([$this->event()], 'Work');

        self::assertStringContainsString('X-WR-CALNAME:Work', $document);
        self::assertStringContainsString('X-WR-TIMEZONE:Europe/Berlin', $document);
        self::assertStringContainsString('PRODID:-//plMail//Calendar//EN', $document);
    }

    /**
     * The name goes through the library's escaping rather than being written as
     * a literal line, so a comma or a semicolon in it cannot silently split the
     * property into values it never had.
     */
    public function testACalendarNameCarryingDelimitersIsEscapedRatherThanSplit(): void
    {
        $document = $this->export([$this->event()], 'Work, home; both');

        self::assertStringContainsString('X-WR-CALNAME:Work\\, home\\; both', $document);
    }

    /** Every line, including the two this class writes itself. */
    public function testEveryLineEndsWithCrlfRatherThanABareNewline(): void
    {
        $document = $this->export([$this->event()]);

        self::assertSame(
            substr_count($document, "\n"),
            substr_count($document, "\r\n"),
            'a bare newline is a file Outlook refuses and everything else silently accepts',
        );
    }

    /** An empty calendar is still a calendar, not an empty download. */
    public function testAnEmptyCalendarIsAValidDocumentRatherThanNothing(): void
    {
        $document = $this->export([]);

        self::assertStringStartsWith("BEGIN:VCALENDAR\r\n", $document);
        self::assertStringEndsWith("END:VCALENDAR\r\n", $document);
        self::assertStringNotContainsString('BEGIN:VEVENT', $document);
    }

    /**
     * An all-day event is a DATE. Written as a date-time it is shifted by the
     * reader's offset, which is how a birthday arrives on the wrong day — the
     * same bug ItipReplyBuilder and CalDavEventConverter both name.
     */
    public function testAnAllDayEventGoesOutAsADateRatherThanAMidnightDateTime(): void
    {
        $event           = $this->event('holiday-1', 'Labour Day');
        $event->isAllDay = true;
        $event->timeZone = null;
        $event->startsAt = new DateTimeImmutable('2026-05-01 00:00:00', new DateTimeZone('UTC'));
        $event->endsAt   = new DateTimeImmutable('2026-05-02 00:00:00', new DateTimeZone('UTC'));

        $document = $this->export([$event]);

        self::assertStringContainsString('DTSTART;VALUE=DATE:20260501', $document);
        self::assertStringNotContainsString('DTSTART:20260501T000000', $document);
    }

    /**
     * The download's name is the last-resort ASCII fallback, so it must never
     * reduce to nothing — a file called ".ics" names no calendar at all.
     */
    #[DataProvider('calendarNames')]
    public function testTheFileNameIsAlwaysSomethingRatherThanJustAnExtension(string $name, string $expected): void
    {
        self::assertSame($expected, $this->exporter->fileNameFor($name));
    }

    /** @return iterable<string, array{string, string}> */
    public static function calendarNames(): iterable
    {
        yield 'an ordinary name'          => ['Work', 'work.ics'];
        yield 'spaces become one hyphen'  => ['Team  calendar', 'team-calendar.ics'];
        yield 'nothing ASCII survives'    => ['日本の祝日', 'calendar.ics'];
        yield 'punctuation is dropped'    => ['Work, home; both', 'work-home-both.ics'];
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /** @param list<CalendarEvent> $events */
    private function export(array $events, string $calendarName = 'Work'): string
    {
        $calendar           = new Calendar();
        $calendar->name     = $calendarName;
        $calendar->timeZone = 'Europe/Berlin';

        $document = '';

        foreach ($this->exporter->document($calendar, $events) as $chunk) {
            $document .= $chunk;
        }

        return $document;
    }

    private function event(string $uid = 'a-1', string $title = 'Standup'): CalendarEvent
    {
        $utc = new DateTimeZone('UTC');

        $event             = new CalendarEvent();
        $event->uid        = $uid;
        $event->title      = $title;
        $event->startsAt   = new DateTimeImmutable('2026-08-10 08:00:00', $utc);
        $event->endsAt     = new DateTimeImmutable('2026-08-10 09:00:00', $utc);
        $event->timeZone   = 'Europe/Berlin';
        $event->status     = EventStatus::Confirmed;
        $event->jscalendar = ['@type' => 'Event', 'uid' => $uid, 'title' => $title];

        return $event;
    }
}
