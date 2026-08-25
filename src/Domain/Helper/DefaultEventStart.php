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
     * The named day at the next full hour, unless that would be in the past,
     * in which case the next full hour itself. The two differ only when the
     * hour has rolled past midnight, and only for a day that is already
     * underway; on any other day they are the same answer.
     */
    public static function onDay(DateTimeImmutable $day, DateTimeImmutable $now): DateTimeImmutable
    {
        $hour      = self::nextFullHour($now);
        $candidate = $day->setTime((int) $hour->format('G'), 0);

        // Never behind the clock. A default the user has to correct is worse
        // than no default, and one that is in the past is a meeting that
        // cannot happen.
        return $candidate < $now ? $hour : $candidate;
    }
}
