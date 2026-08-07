<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

use DateTimeImmutable;

/**
 * One month of a shared calendar, as a grid.
 *
 * The page this feeds used to be a flat list of days, and the argument for that
 * — a grid needs a viewport and a zoom control to be readable, and this page is
 * opened once, usually on a phone — was right about phones and wrong about
 * everything else. A calendar that arrives as a list does not look like a
 * calendar, which is what the person who sent the link thought they were
 * sending. So the page is both: this grid, and the day list underneath it built
 * from the same objects, with the list carrying the detail on a narrow screen.
 *
 * **42 days, always.** Six weeks from the Monday on or before the first, so the
 * grid does not change height between months — the same rule
 * calendar/_view_month.html.twig states for the authenticated calendar, and the
 * reason the two look alike is that they are now drawn by the same partial.
 *
 * **The window is not the month.** A link covers a rolling fortnight or a fixed
 * range; the grid covers a calendar month. Every day carries $isShared for the
 * difference, and $previous/$next are null at the ends of the window rather
 * than paging into months the link never published — a reader who could step
 * forward into an empty December would read it as "free in December".
 */
final readonly class SharedCalendarMonth
{
    /**
     * @param list<SharedCalendarDay> $days     42 of them, Monday first
     * @param string|null             $previous 'Y-m', or null at the window's first month
     * @param string|null             $next     'Y-m', or null at the window's last month
     */
    public function __construct(
        public DateTimeImmutable $anchor,
        public array             $days,
        public ?string           $previous,
        public ?string           $next,
    ) {
    }

    /**
     * The 42 dates, in grid order.
     *
     * The shell wants a flat list of keys and a map to look them up in, because
     * that is the shape the authenticated calendar's range reader already
     * produces. Deriving both here rather than in Twig keeps the two callers
     * passing the same thing.
     *
     * @return list<string>
     */
    public function dayKeys(): array
    {
        $keys = [];

        foreach ($this->days as $day) {
            $keys[] = $day->date;
        }

        return $keys;
    }

    /**
     * The cells the link publishes, keyed Y-m-d.
     *
     * A day the window does not cover is ABSENT rather than empty, and that is
     * the whole contract with calendar/_month_shell.html.twig: a missing key is
     * what makes it draw the cell as outside the range instead of as a free
     * day. The two states have to be distinguishable in the data or they cannot
     * be distinguishable on screen.
     *
     * @return array<string, list<SharedOccurrence>>
     */
    public function gridDays(): array
    {
        $grid = [];

        foreach ($this->days as $day) {
            if (true === $day->isShared) {
                $grid[$day->date] = $day->entries;
            }
        }

        return $grid;
    }

    /**
     * The days of THIS month the link actually covers, in order.
     *
     * What the list under the grid renders. In-month as well as shared, so a
     * spill-in day from the neighbouring month is drawn in the grid — where it
     * is visibly a spill-in — and not printed twice in two months' lists.
     *
     * Stays a method rather than a second constructor argument: it is a filter
     * over $days, and two arrays that could disagree about which days are
     * shared is exactly the bug that would put an entry in the list and not in
     * the grid.
     *
     * @return list<SharedCalendarDay>
     */
    public function sharedDays(): array
    {
        $shared = [];

        foreach ($this->days as $day) {
            if (true === $day->isShared && true === $day->isInMonth) {
                $shared[] = $day;
            }
        }

        return $shared;
    }

    /** Whether this month has anything on it at all — the grid's empty state. */
    public function isEmpty(): bool
    {
        foreach ($this->days as $day) {
            if ([] !== $day->entries) {
                return false;
            }
        }

        return true;
    }
}
