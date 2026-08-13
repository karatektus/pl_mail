<?php

declare(strict_types=1);

namespace App\Service\Calendar;

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
 * The zone comes from the calendar rather than from the user's profile, and
 * falls back to the profile when no calendar answers. UserTimezoneResolver
 * answers "what clock is this person reading?", which is the right question for
 * a rendered timestamp; a calendar's own zone is what an event with none of its
 * own is stored and shown in, and the two can honestly differ — a shared work
 * calendar pinned to the office. What is never right is falling back past both
 * of them to the process's zone; see zoneFor().
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
     * The zone a user's calendar pages and new events are read in.
     *
     * The default calendar's zone when there is one, and the user's OWN
     * configured zone when there is not — never `date_default_timezone_get()`.
     * That fallback is the defect this method used to carry, and it is the exact
     * one UserTimezoneResolver's header warns about: frankenphp/conf.d/10-app.ini
     * pins PHP's default to UTC, which is right for arithmetic and catastrophic
     * as a display default.
     *
     * A user with no default calendar is not exotic — the flag is per-user and
     * nothing re-asserts it, so a calendar deleted or provisioned before the
     * flag existed leaves the account without one. Every such account had its
     * whole calendar drawn in UTC: the current-time line two hours off the
     * gutter it is read against, and a new event proposed at the next full UTC
     * hour, which for a Berlin user in summer is a slot already an hour past.
     * Neither looks like an error; both are just wrong by the offset.
     */
    public function zoneFor(User $user): DateTimeZone
    {
        $calendar = $this->calendars->findDefaultForUser($user);

        return $this->safeZone($calendar->timeZone ?? $this->timezones->nameFor($user));
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
