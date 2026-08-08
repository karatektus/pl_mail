<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

use App\Domain\Enum\Calendar\CalendarView;
use DateTimeImmutable;

/**
 * One page of a shared calendar: which view, which days, and where the paging
 * runs out.
 *
 * This replaced SharedCalendarMonth when the public page grew the other three
 * views. A DTO per view was the obvious shape and the wrong one: month, week,
 * day and agenda differ in how many days they draw and in nothing else that
 * matters here — the clamping, the window arithmetic and the bounded paging are
 * one set of rules asked four times, and four types would have been four places
 * to get them subtly different. What varies between the views lives in
 * CalendarView, which the authenticated calendar already asks the same
 * questions of.
 *
 * **A day the link does not publish is not a free day.** Every day carries
 * $isShared for that difference and $previous/$next answer null at the ends of
 * the window rather than paging into a range the link never published — a reader
 * who could step forward into an empty December would read it as "free in
 * December". SharedCalendarDay says the same at the level of one cell.
 *
 * **$days may hold days the window does not cover**, and that is deliberate: a
 * month grid is 42 cells and a week is seven columns whatever the window does,
 * so the unpublished ones have to be present in order to be drawn as
 * unpublished. gridDays() is the filtered view of the same list, keyed, and it
 * is what the shells are handed — a MISSING key is how they tell "not published"
 * from "nothing on".
 *
 * **The entries are SharedOccurrence objects and are therefore already
 * redacted.** Nothing here can reveal more than the flat map it was built from:
 * it is the same objects in a different arrangement, which is the property that
 * made adding three views a rearrangement rather than a second read path. See
 * SharedCalendarRangeBuilder, which has no repository and could not fetch more
 * if it wanted to.
 */
final readonly class SharedCalendarRange
{
    /**
     * @param list<SharedCalendarDay> $days     every day this page draws, in order
     * @param array<string, DayGrid>  $grid     placements for the shared days, keyed
     *                                          Y-m-d; empty for a view with no time
     *                                          axis
     * @param string|null             $previous 'Y-m-d' to step back to, or null at
     *                                          the window's near end
     * @param string|null             $next     likewise, forwards
     * @param string|null             $today    'Y-m-d' when the window contains
     *                                          today, else null — the toolbar
     *                                          renders no "today" button when
     *                                          there is nowhere for it to go
     * @param string                  $focus    'Y-m-d' the reader is looking at:
     *                                          clamped into the window but NOT
     *                                          normalised to what this view means
     *                                          by an anchor. The switcher's links
     *                                          carry this, not $anchor — a month
     *                                          page anchored on the 1st was asked
     *                                          for by somebody looking at the
     *                                          23rd, and switching to day view
     *                                          must open the 23rd, not the 1st
     */
    public function __construct(
        public CalendarView      $view,
        public DateTimeImmutable $anchor,
        public string            $focus,
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public array             $days,
        public array             $grid,
        public ?string           $previous,
        public ?string           $next,
        public ?string           $today,
    ) {
    }

    /**
     * The dates this page draws, in order.
     *
     * The shells want a flat list of keys and a map to look them up in, because
     * that is the shape CalendarRangeReader already produces for the
     * authenticated calendar. Deriving both here rather than in Twig keeps the
     * two callers passing the same thing.
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
     * The days the link publishes, keyed Y-m-d.
     *
     * A day the window does not cover is ABSENT rather than empty, and that is
     * the whole contract with the three shells: a missing key is what makes them
     * draw a dimmed cell, a dimmed column or no row at all, instead of a free
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
     * The days this page LISTS, in order — shared, and belonging to the span
     * rather than spilling into it.
     *
     * What the agenda renders. The spill-in distinction is what stops a month
     * grid's neighbouring days being printed twice, once in each month's agenda;
     * on the views whose span is exactly their range it excludes nothing. See
     * SharedCalendarDay::$isInSpan.
     *
     * Stays a method rather than a second constructor argument: it is a filter
     * over $days, and two arrays that could disagree about which days are shared
     * is exactly the bug that would put an entry in the list and not in the grid.
     *
     * @return list<SharedCalendarDay>
     */
    public function sharedDays(): array
    {
        $shared = [];

        foreach ($this->days as $day) {
            if (true === $day->isShared && true === $day->isInSpan) {
                $shared[] = $day;
            }
        }

        return $shared;
    }

    /** Whether this page has anything on it at all — the view's empty state. */
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
