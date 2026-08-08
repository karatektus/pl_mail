<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

/**
 * One day of a shared calendar page — a cell of its month grid, a column of its
 * week, a row of its agenda.
 *
 * Three facts and the entries: which day, whether it belongs to the span on
 * screen, and whether the link covers it at all. Today and weekend are
 * deliberately absent — the shells under templates/calendar/ derive both from
 * the date they are handed, for the authenticated calendar and for this one
 * alike, and a second answer here would be a second thing to keep in step.
 *
 * The entries are SharedOccurrence objects and are therefore already redacted.
 * Nothing here can reveal more than the flat list this replaced could: it is the
 * same objects in a different arrangement.
 *
 * **$isShared is not decoration.** A month has 42 cells and a rolling link
 * covers fourteen days, so most of a grid is outside the window — and a day
 * outside the window is not "free", it is "not published". Drawing those two
 * the same way would make a shared calendar claim its owner has nothing on for
 * a fortnight it says nothing about, which is the one lie this page must not
 * tell. The month grid dims the cell, the time-grid dims the column and drops
 * its hour lines, the agenda leaves the day out, and a legend names the state on
 * every view.
 *
 * **$isInSpan is about the month grid and costs the other views nothing.** A
 * month draws 42 cells and only 28 to 31 of them are its own; the rest spill in
 * from its neighbours, and printing those in the agenda as well would print them
 * twice, once under each month. On the views whose span is exactly their range —
 * week, day, agenda — every day in range is in span, so the filter excludes
 * nothing.
 */
final readonly class SharedCalendarDay
{
    /**
     * @param list<SharedOccurrence> $entries what the link reveals on this day, in start order
     */
    public function __construct(
        public string $date,
        public bool   $isInSpan,
        public bool   $isShared,
        public array  $entries = [],
    ) {
    }
}
