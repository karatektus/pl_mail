<?php

declare(strict_types=1);

namespace App\Domain\Helper;

use DateTimeImmutable;

/**
 * When a new event should start, given a day and the time it is now.
 *
 * A calendar that opens at 09:00 all afternoon asks every user past
 * mid-morning to retype the one field they were most likely to accept, so the
 * default is the next full hour on their own clock. For a bare date — "new
 * event on Thursday" — that hour is carried onto the day they named, because
 * midnight is not what anybody means by it.
 *
 * THE HOUR THAT ROLLED OVER
 *
 * That carrying is where it broke. Between 23:00 and midnight the next full
 * hour is 00:00 TOMORROW, and taking its hour-of-day and pinning it to the
 * requested date produced today at 00:00 — twenty-three hours in the past, on
 * the one control the user was most likely to accept unchanged. Once a day,
 * for an hour, on every install.
 *
 * A pure function here rather than a private method on the controller, because
 * a rule about clocks that is only reachable through a browser can only be
 * tested in the hour it is wrong.
 */
final readonly class DefaultEventStart
{
    /**
     * The next full hour, on $now's own clock.
     */
    public static function nextFullHour(DateTimeImmutable $now): DateTimeImmutable
    {
        // `G` is the hour with no leading zero, so no octal-looking string
        // reaches the cast.
        return $now->setTime((int) $now->format('G'), 0)->modify('+1 hour');
    }

    /**
     * The default start for a bare date — a day named with no time on it.
     *
     * The named day at the next full hour's time of day. For today, unless
     * that would be in the past, in which case the next full hour itself: the
     * two differ only when the hour has rolled past midnight. A day that has
     * already gone keeps its date, at that time of day.
     */
    public static function onDay(DateTimeImmutable $day, DateTimeImmutable $now): DateTimeImmutable
    {
        $hour      = self::nextFullHour($now);
        $candidate = $day->setTime((int) $hour->format('G'), 0);

        // A day that has already gone keeps its date (#253). Somebody who
        // picks last Thursday and presses + is entering last Thursday — a
        // meeting being written down after it happened, a day being filled in
        // — and moving it to today put the event on a day they did not choose,
        // in a field they had every reason to trust. It was an explicit rule
        // here that a past day was pulled to the next hour; the month's day
        // list, which offers + on any day of six weeks, is what showed it up.
        if ($day->format('Y-m-d') < $now->format('Y-m-d')) {
            return $candidate;
        }

        // Today or later, never behind the clock: the case that matters is the
        // last hour of today, whose next full hour is 00:00 tomorrow, and
        // which pinned to today's date would be today's midnight, gone.
        return $candidate < $now ? $hour : $candidate;
    }
}
