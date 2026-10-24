<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarRepository;
use App\Service\User\UserTimezoneResolver;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Which wall clock a calendar is read in, and how the digits a browser posts
 * turn back into instants in it.
 *
 * The two halves belong together because neither is meaningful alone: a date
 * typed into the editor is "09:00" and nothing else until a zone is chosen for
 * it, and choosing the wrong one moves the event by the offset without ever
 * looking like an error.
 *
 * Two clocks, kept apart on purpose (#343). A page is DRAWN on the reader's
 * own clock — UserTimezoneResolver's answer, the one the header clock and every
 * `|date` use — so an event shows at the hour it actually happens for them,
 * whatever zone it or its calendar is in. A calendar's own zone only decides
 * how a time with no zone attached is WRITTEN: a new event's, and the fallback
 * for an event stored without one. That zone is null, "follow plMail", unless
 * somebody picked one, which makes it the user's zone for every calendar
 * nobody set.
 *
 * What is never right is falling back past both to the process's zone, which
 * frankenphp/conf.d/10-app.ini pins to UTC.
 *
 * Every parse is total: an unusable zone or an unparseable date returns a
 * fallback or null rather than throwing, because all of it arrives from a
 * request and none of it is a server error.
 */
final readonly class CalendarTimeResolver
{
    public function __construct(
        private CalendarRepository   $calendars,
        private UserTimezoneResolver $timezones,
    ) {
    }

    /**
     * The clock a user's calendar pages are drawn on: their own.
     *
     * It used to be the default calendar's zone when that calendar was local.
     * Every local calendar's zone was a copy of the user's, taken the day the
     * calendar was made, and nothing ever updated it — so changing the zone in
     * settings moved the header clock and every timestamp in the app but left
     * the calendar grid, its now-line and "today" on the old one (#343). A
     * calendar's zone is about writing times down, not reading them; see the
     * class docblock.
     */
    public function zoneFor(User $user): DateTimeZone
    {
        return $this->timezones->resolve($user);
    }

    /**
     * The zone a calendar writes zone-less times in: its own when one was
     * picked, its owner's when it follows plMail.
     */
    public function calendarZone(Calendar $calendar): DateTimeZone
    {
        return null === $calendar->timeZone
            ? $this->timezones->resolve($calendar->usr)
            : $this->safeZone($calendar->timeZone);
    }

    /**
     * The zone a new event starts in when nothing names one: the default
     * calendar's, which is where it lands unless the editor is told otherwise.
     */
    public function newEventZone(User $user): DateTimeZone
    {
        $calendar = $this->calendars->findDefaultForUser($user);

        return null === $calendar ? $this->zoneFor($user) : $this->calendarZone($calendar);
    }

    /**
     * An event's own zone, falling back to the user's when it was stored
     * without one — every event predating the timeZone column, and every one
     * imported from a source that omitted it.
     *
     * **An all-day event is floating and answers UTC**, never the user's zone.
     * Its columns hold a wall-clock date at midnight with no zone at all — see
     * RecurrenceMaterialiser::zoneOf(), which expands it in UTC for exactly that
     * reason — so converting it into a real zone does not translate it, it moves
     * it. Falling back to the user's zone here is what put "02:00 – 02:00" in
     * the editor for every all-day event a Berlin user received: midnight UTC,
     * read as though it had been an instant, rendered two hours later. In a
     * zone west of UTC the same arithmetic lands the event on the day before.
     */
    public function eventZone(CalendarEvent $event, User $user): DateTimeZone
    {
        if (true === $event->isAllDay) {
            return new DateTimeZone('UTC');
        }

        return $this->safeZone($event->timeZone ?? $this->zoneFor($user)->getName());
    }

    /**
     * UTC for anything PHP does not recognise. A zone name reaches this from a
     * form field and from rows written by other clients, so an unknown one is
     * an input to survive, not a condition to raise.
     */
    public function safeZone(string $name): DateTimeZone
    {
        try {
            return new DateTimeZone($name);
        } catch (\Exception) {
            return new DateTimeZone('UTC');
        }
    }

    /** A `Y-m-d` route segment at midnight in the given zone. */
    public function parseDate(string $date, DateTimeZone $zone): ?DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date, $zone);

        return false === $parsed ? null : $parsed->setTime(0, 0);
    }

    /** A datetime-local field's value, read as wall time in the given zone. */
    public function parseDateTime(string $value, DateTimeZone $zone): ?DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }

        try {
            return new DateTimeImmutable($value, $zone);
        } catch (\Exception) {
            return null;
        }
    }
}
