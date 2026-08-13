<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventOccurrenceRepository;
use App\Repository\Calendar\CalendarRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * How soon the next thing is, as one word the topbar can colour a dot with.
 *
 * The dot answers a question the calendar button cannot: "is there any reason
 * to open this right now?". So it only ever considers what is still ahead
 * today — a meeting that finished an hour ago is not news, and tomorrow's is
 * not urgent.
 *
 * Deliberately coarse. Three bands rather than a countdown, because the point
 * is a glance: a colour you have to read a number off is not a dot.
 */
final readonly class UpcomingEventIndicator
{
    /** Inside this, the dot is at its most urgent. */
    private const int IMMINENT_MINUTES = 60;

    /** Between the two, mid-urgency. Beyond it, calm. */
    private const int SOON_MINUTES = 240;

    public function __construct(
        private CalendarRepository                $calendars,
        private CalendarEventOccurrenceRepository $occurrences,
        private CalendarTimeResolver              $time,
    ) {
    }

    /**
     * @return array{state: string, startsAt: DateTimeImmutable, title: string}|null
     *         null when there is nothing left today
     */
    public function forUser(User $user, ?DateTimeImmutable $now = null): ?array
    {
        $calendarIds = [];
        $zone        = null;

        foreach ($this->calendars->findVisibleForUser($user) as $calendar) {
            $calendarIds[] = (int) $calendar->id;

            if (null === $zone && true === $calendar->isDefault) {
                $zone = $calendar->timeZone;
            }
        }

        if (0 === count($calendarIds)) {
            return null;
        }

        $utc   = new DateTimeZone('UTC');
        $now ??= new DateTimeImmutable('now', $utc);
        $now   = $now->setTimezone($utc);

        // "Today" is the user's today, not UTC's: at 01:00 in Berlin the two
        // disagree about which day it is, and the wrong one shows an empty dot
        // on a morning that has three meetings in it.
        $local  = $now->setTimezone($this->zone($zone, $user));
        $endsAt = $local->modify('tomorrow')->setTime(0, 0)->setTimezone($utc);

        $upcoming = $this->occurrences->findInRange($user, $calendarIds, $now, $endsAt);

        foreach ($upcoming as $occurrence) {
            // findInRange returns anything OVERLAPPING the window, which
            // includes the meeting that started before now and is still
            // running. Those are not upcoming.
            if ($occurrence->startsAt < $now) {
                continue;
            }

            $minutes = (int) floor(($occurrence->startsAt->getTimestamp() - $now->getTimestamp()) / 60);

            return [
                'state'    => $this->state($minutes),
                'startsAt' => $occurrence->startsAt,
                'title'    => (string) $occurrence->event?->title,
            ];
        }

        return null;
    }

    private function state(int $minutes): string
    {
        return match (true) {
            $minutes <= self::IMMINENT_MINUTES => 'imminent',
            $minutes <= self::SOON_MINUTES     => 'soon',
            default                            => 'later',
        };
    }

    /**
     * The visible default calendar's zone, else the user's own — never the
     * process's, which is pinned to UTC in this container.
     *
     * `$name` is null whenever no VISIBLE calendar carries the default flag,
     * which is a state a real account reaches: the flag is per-user, nothing
     * re-asserts it, and hiding the default is only refused while it still holds
     * the flag. Falling through to UTC put "today" on the wrong day for anyone
     * east of it between midnight and their offset — the dot reads empty on a
     * morning with three meetings in it, which is the one failure this class
     * exists to avoid.
     */
    private function zone(?string $name, User $user): DateTimeZone
    {
        if (null === $name) {
            return $this->time->zoneFor($user);
        }

        return $this->time->safeZone($name);
    }
}
