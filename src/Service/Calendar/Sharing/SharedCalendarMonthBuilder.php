<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sharing;

use App\Domain\DTO\Calendar\SharedCalendarDay;
use App\Domain\DTO\Calendar\SharedCalendarMonth;
use App\Domain\DTO\Calendar\SharedCalendarView;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Arranging an already-redacted shared window into a month grid.
 *
 * **It reads nothing.** The only input that came from the database is a
 * SharedCalendarView, which ShareLinkReader has already stripped — this class
 * has no repository, no link and no event, and there is no path from what it is
 * given back to one. That is deliberate and it is the reason the grid could be
 * added at all: rearranging redacted objects cannot leak, whereas a second
 * reader that fetched a month for itself would be a second place the redaction
 * had to be remembered.
 *
 * ── Why the arithmetic is here and not in Twig ────────────────────────────
 *
 * Six weeks from the Monday on or before the first, in the owner's zone, with
 * "today" resolved in that same zone. Every part of that is date arithmetic
 * across a DST boundary, and the rule this codebase keeps — see
 * CalendarRangeReader's day walk, and ShareLinkReader's — is that a template
 * doing it gets one day a year wrong.
 *
 * ── The month is clamped to the window ────────────────────────────────────
 *
 * A link publishes a window: a rolling fortnight, or two fixed dates. The grid
 * shows a calendar month, which is neither. Paging is therefore bounded to the
 * months the window touches, and $previous/$next answer null at the ends rather
 * than stepping into a month the link never published — an empty January that a
 * reader could reach reads as "free all January", which is a claim about the
 * owner's diary that nobody made.
 *
 * A requested month outside those bounds is clamped rather than refused, for
 * the reason ShareLinkReader clamps its rolling count: this is driven by a
 * query parameter on a public URL, and answering an error to a hand-edited one
 * is a worse page than answering the nearest month that exists.
 */
final readonly class SharedCalendarMonthBuilder
{
    /** What a `month` query parameter has to look like before it is believed. */
    private const string MONTH_PATTERN = '/^\d{4}-\d{2}$/';

    /**
     * Six weeks, always — not five-or-six.
     *
     * The grid then does not change height between months, which is what stops
     * the page jumping under the reader's thumb as they page through it. Same
     * number and same reason as CalendarRange's month, so the two calendars
     * cannot end up different shapes.
     */
    private const int CELLS = 42;

    /**
     * The grid for one month of $view.
     *
     * $now is passed rather than read for the reason ShareLinkReader::read()
     * passes it: the window, the default month and the "today" mark are three
     * answers to what time it is, and within one request they must be the same
     * answer.
     */
    public function build(SharedCalendarView $view, ?string $month, DateTimeImmutable $now): SharedCalendarMonth
    {
        $zone = new DateTimeZone($view->zone);

        $firstDay = $view->from->setTimezone($zone)->modify('midnight');
        // The window's end is exclusive — midnight on the morning after — so the
        // last day it covers is the day before it. Taking $to itself would
        // publish a month for a day the link does not.
        $lastDay = $view->to->setTimezone($zone)->modify('midnight')->modify('-1 day');

        if ($lastDay < $firstDay) {
            $lastDay = $firstDay;
        }

        $anchor = $this->anchor($month, $firstDay, $lastDay, $now->setTimezone($zone));

        return new SharedCalendarMonth(
            $anchor,
            $this->days($view, $anchor, $firstDay, $lastDay),
            $this->step($anchor, '-1 month', $firstDay, $lastDay),
            $this->step($anchor, '+1 month', $firstDay, $lastDay),
        );
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Which month the grid opens on: the one asked for, else the one today is
     * in, else the first the window covers.
     *
     * Today first among the fallbacks because a rolling link is nearly always
     * read on the day it is opened, and a page that opened on the window's
     * first month would be right and useless for a link that starts next
     * quarter — which the fallback behind it then answers.
     */
    private function anchor(
        ?string           $month,
        DateTimeImmutable $firstDay,
        DateTimeImmutable $lastDay,
        DateTimeImmutable $today,
    ): DateTimeImmutable {
        $requested = null !== $month && 1 === preg_match(self::MONTH_PATTERN, $month)
            ? $month
            : $today->format('Y-m');

        $first = $firstDay->modify('first day of this month')->modify('midnight');
        $last  = $lastDay->modify('first day of this month')->modify('midnight');

        // Built from the string rather than modified from $today, so a request
        // for a month with fewer days than this one cannot roll over — "31
        // January" plus a month is 3 March, and PHP will do it silently.
        $anchor = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $requested . '-01 00:00:00',
            $firstDay->getTimezone(),
        );

        if (false === $anchor) {
            return $first;
        }

        if ($anchor < $first) {
            return $first;
        }

        return $anchor > $last ? $last : $anchor;
    }

    /**
     * The 42 cells, Monday first.
     *
     * @return list<SharedCalendarDay>
     */
    private function days(
        SharedCalendarView $view,
        DateTimeImmutable  $anchor,
        DateTimeImmutable  $firstDay,
        DateTimeImmutable  $lastDay,
    ): array {
        $anchorMonth = $anchor->format('Y-m');
        $from        = $firstDay->format('Y-m-d');
        $to          = $lastDay->format('Y-m-d');

        // 'monday this week' on a Monday is that Monday, so a month starting on
        // one is not dragged back a week.
        $cursor = $anchor->modify('monday this week');
        $days   = [];

        for ($cell = 0; $cell < self::CELLS; $cell++) {
            $date = $cursor->format('Y-m-d');

            $days[] = new SharedCalendarDay(
                $date,
                $cursor->format('Y-m') === $anchorMonth,
                $date >= $from && $date <= $to,
                $view->days[$date] ?? [],
            );

            $cursor = $cursor->modify('+1 day');
        }

        return $days;
    }

    /**
     * The month one step either side, or null when that step leaves the window.
     */
    private function step(
        DateTimeImmutable $anchor,
        string            $modifier,
        DateTimeImmutable $firstDay,
        DateTimeImmutable $lastDay,
    ): ?string {
        $candidate = $anchor->modify($modifier)->format('Y-m');

        if ($candidate < $firstDay->format('Y-m') || $candidate > $lastDay->format('Y-m')) {
            return null;
        }

        return $candidate;
    }
}
