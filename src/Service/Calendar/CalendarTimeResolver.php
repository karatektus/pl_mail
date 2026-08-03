<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarRepository;
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
 * The zone comes from the calendar rather than from the user's profile.
 * UserTimezoneResolver answers "what clock is this person reading?", which is
 * the right question for a rendered timestamp; a calendar's own zone is what an
 * event with none of its own is stored and shown in, and the two can honestly
 * differ — a shared work calendar pinned to the office.
 *
 * Every parse is total: an unusable zone or an unparseable date returns a
 * fallback or null rather than throwing, because all of it arrives from a
 * request and none of it is a server error.
 */
final readonly class CalendarTimeResolver
{
    public function __construct(
        private CalendarRepository $calendars,
    ) {
    }

    /** The zone a user's calendar pages and new events are read in. */
    public function zoneFor(User $user): DateTimeZone
    {
        $calendar = $this->calendars->findDefaultForUser($user);

        return $this->safeZone($calendar->timeZone ?? date_default_timezone_get());
    }

    /**
     * An event's own zone, falling back to the user's when it was stored
     * without one — every event predating the timeZone column, and every one
     * imported from a source that omitted it.
     */
    public function eventZone(CalendarEvent $event, User $user): DateTimeZone
    {
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
