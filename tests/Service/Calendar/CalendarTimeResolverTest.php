<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar;

use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarRepository;
use App\Service\Calendar\CalendarTimeResolver;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Getting a calendar's zone wrong does not look like an error — it moves every
 * event by the local UTC offset and leaves a page that reads as if it worked.
 * These pin the three places that decision is made.
 */
final class CalendarTimeResolverTest extends TestCase
{
    public function testTheCalendarsOwnZoneIsWhatItIsReadIn(): void
    {
        $resolver = $this->resolver('Europe/Berlin');

        self::assertSame('Europe/Berlin', $resolver->zoneFor(new User())->getName());
    }

    /**
     * A calendar stored with a zone PHP does not recognise — hand-edited, or
     * imported from a client with its own vocabulary — must not take the page
     * down with it.
     */
    public function testAnUnknownZoneNameFallsBackToUtcRatherThanThrowing(): void
    {
        self::assertSame('UTC', $this->resolver('Mars/Olympus_Mons')->zoneFor(new User())->getName());
        self::assertSame('UTC', $this->resolver(null)->safeZone('')->getName());
    }

    /**
     * An event carries its own zone because a meeting booked in Berlin stays a
     * Berlin meeting wherever it is later read.
     */
    public function testAnEventIsReadInItsOwnZoneWhenItHasOne(): void
    {
        $resolver = $this->resolver('Europe/Berlin');

        $event = new CalendarEvent();
        $event->timeZone = 'America/New_York';

        self::assertSame('America/New_York', $resolver->eventZone($event, new User())->getName());
    }

    /** Rows written before the column existed, and imports that omitted it. */
    public function testAnEventWithNoZoneFallsBackToTheCalendars(): void
    {
        $resolver = $this->resolver('Europe/Berlin');

        self::assertSame('Europe/Berlin', $resolver->eventZone(new CalendarEvent(), new User())->getName());
    }

    /**
     * The date in a route segment is a day, not an instant: it has to start at
     * local midnight, or a week view anchored on it renders the wrong week for
     * anyone east of UTC.
     */
    public function testARouteDateBecomesLocalMidnight(): void
    {
        $parsed = $this->resolver(null)->parseDate('2026-03-14', new DateTimeZone('Europe/Berlin'));

        self::assertNotNull($parsed);
        self::assertSame('2026-03-14 00:00:00 Europe/Berlin', $parsed->format('Y-m-d H:i:s e'));
    }

    /**
     * A form field is wall time, so the same digits are different instants in
     * different zones. This fails if the zone is ever dropped on the way in.
     */
    public function testAFormValueIsReadAsWallTimeInTheGivenZone(): void
    {
        $resolver = $this->resolver(null);

        $berlin = $resolver->parseDateTime('2026-03-14T09:00', new DateTimeZone('Europe/Berlin'));
        $utc    = $resolver->parseDateTime('2026-03-14T09:00', new DateTimeZone('UTC'));

        self::assertNotNull($berlin);
        self::assertNotNull($utc);
        self::assertNotSame($berlin->getTimestamp(), $utc->getTimestamp());
        self::assertSame('09:00', $berlin->format('H:i'));
    }

    /**
     * Everything parsed here arrives from a request, so unusable input is an
     * answer of "nothing" the caller can refuse — never an exception.
     */
    public function testUnusableInputIsNullSoTheCallerCanRefuseIt(): void
    {
        $resolver = $this->resolver(null);
        $zone     = new DateTimeZone('UTC');

        self::assertNull($resolver->parseDateTime('', $zone));
        self::assertNull($resolver->parseDateTime('not a date', $zone));
        self::assertNull($resolver->parseDate('14/03/2026', $zone));
    }

    /**
     * @param string|null $calendarZone null leaves the property unset, which is
     *                                  how a calendar seeded before the column
     *                                  existed reaches the resolver
     */
    private function resolver(?string $calendarZone): CalendarTimeResolver
    {
        $calendar = new Calendar();

        if (null !== $calendarZone) {
            $calendar->timeZone = $calendarZone;
        }

        $calendars = $this->createStub(CalendarRepository::class);
        $calendars->method('findDefaultForUser')->willReturn($calendar);

        return new CalendarTimeResolver($calendars);
    }
}
