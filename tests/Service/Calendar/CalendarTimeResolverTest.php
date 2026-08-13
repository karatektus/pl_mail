<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar;

use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarRepository;
use App\Service\Calendar\CalendarTimeResolver;
use App\Service\User\UserTimezoneResolver;
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
     * **No default calendar falls back to the USER, never to the process.**
     *
     * The flag is per-user and nothing re-asserts it, so an account reaches this
     * state by ordinary means: a default calendar deleted, or one provisioned
     * before the flag existed. This method used to answer
     * `date_default_timezone_get()` there, which frankenphp/conf.d/10-app.ini
     * pins to UTC — so every such account had its whole calendar drawn on a
     * clock two hours off the one it was labelled with. The current-time line
     * sat at the UTC minute over a gutter running 00:00–23:00 by hour index, and
     * a new event was proposed at the next full UTC hour, which for a Berlin
     * user in summer is a slot already an hour past.
     *
     * Asserted as "not UTC" as well as "the user's", because UTC is exactly the
     * wrong answer that looks like a right one.
     */
    public function testAnAccountWithNoDefaultCalendarIsReadInTheUsersOwnZone(): void
    {
        $zone = $this->resolverFor(null, 'Europe/Berlin')->zoneFor(new User());

        self::assertSame('Europe/Berlin', $zone->getName());
        self::assertNotSame('UTC', $zone->getName());
    }

    /** And it follows the install's configured default rather than a constant. */
    public function testTheFallbackFollowsTheInstallsConfiguredZone(): void
    {
        self::assertSame(
            'America/New_York',
            $this->resolverFor(null, 'America/New_York')->zoneFor(new User())->getName(),
        );
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
     * The exception to the line above, and the bug it caused: an all-day event
     * also has no zone, and it does not have one *on purpose*. It is floating —
     * a wall date at midnight, the same day everywhere — which is how
     * RecurrenceMaterialiser expands it. Falling back to the calendar's zone
     * here read midnight UTC as an instant and rendered it two hours later, so
     * every all-day invitation a Berlin user received opened in the editor
     * reading "02:00 – 02:00". West of UTC it moves onto the day before.
     */
    public function testAnAllDayEventIsFloatingAndReadsInUtc(): void
    {
        $resolver = $this->resolver('Europe/Berlin');

        $event           = new CalendarEvent();
        $event->isAllDay = true;

        self::assertSame('UTC', $resolver->eventZone($event, new User())->getName());
    }

    /**
     * And it wins over a zone the row happens to carry. Some sources stamp one
     * on an all-day event anyway; honouring it would move the event by that
     * zone's offset for no gain, since a floating date has no instant to
     * translate.
     */
    public function testAnAllDayEventIgnoresAZoneItWasStoredWith(): void
    {
        $event           = new CalendarEvent();
        $event->isAllDay = true;
        $event->timeZone = 'America/New_York';

        self::assertSame('UTC', $this->resolver('Europe/Berlin')->eventZone($event, new User())->getName());
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

        return $this->resolverFor($calendar);
    }

    /**
     * @param Calendar|null $calendar what findDefaultForUser() answers — null
     *                                for an account that has no default one
     */
    private function resolverFor(?Calendar $calendar, string $installDefault = 'Europe/Berlin'): CalendarTimeResolver
    {
        $calendars = $this->createStub(CalendarRepository::class);
        $calendars->method('findDefaultForUser')->willReturn($calendar);

        return new CalendarTimeResolver($calendars, new UserTimezoneResolver($installDefault));
    }
}
