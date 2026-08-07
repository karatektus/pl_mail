<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

/**
 * One cell of the month grid a shared link draws.
 *
 * Three facts and the entries: which day, whether it belongs to the month on
 * screen, and whether the link covers it at all. Today and weekend are
 * deliberately absent — calendar/_month_shell.html.twig derives both from the
 * date it is handed, for the authenticated calendar and for this one alike, and
 * a second answer here would be a second thing to keep in step.
 *
 * The entries are SharedOccurrence objects and are therefore already redacted.
 * Nothing here can reveal more than the flat list this replaced could: it is the
 * same objects in a different arrangement.
 *
 * **$isShared is not decoration.** A month has 42 cells and a rolling link
 * covers fourteen days, so most of a grid is outside the window — and a cell
 * outside the window is not "free", it is "not published". Drawing those two
 * the same way would make a shared calendar claim its owner has nothing on for
 * a fortnight it says nothing about, which is the one lie this page must not
 * tell. The grid dims them, the day list skips them, and a legend names them.
 */
final readonly class SharedCalendarDay
{
    /**
     * @param list<SharedOccurrence> $entries what the link reveals on this day, in start order
     */
    public function __construct(
        public string $date,
        public bool   $isInMonth,
        public bool   $isShared,
        public array  $entries = [],
    ) {
    }
}
