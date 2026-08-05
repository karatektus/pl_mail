<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

use DateTimeImmutable;

/**
 * Everything a shared calendar page renders: the window it resolved to, the
 * clock it is drawn on, and the redacted entries grouped by local day.
 *
 * Grouped here rather than in Twig for the reason CalendarRangeReader gives
 * about its own day walk — the grouping depends on a zone, and a template doing
 * date arithmetic gets one day a year wrong. Every day in the window is
 * present, including the empty ones, so the page can say "nothing on the 4th"
 * rather than skipping it and leaving the reader to notice the gap.
 *
 * The owner is not here, and the absence is the point: a shared page names the
 * calendar's owner nowhere unless the owner put a name on the link, which they
 * did not — $name is the link's own label and is never rendered publicly. What
 * a recipient learns from this object is when somebody is busy and, if the
 * boxes were ticked, what about. Not whose diary it is, not which install, not
 * how many calendars it came from.
 */
final readonly class SharedCalendarView
{
    /**
     * @param array<string, list<SharedOccurrence>> $days keyed Y-m-d in $zone, every day present
     */
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public string            $zone,
        public array             $days,
        public bool              $isBusyFreeOnly,
    ) {
    }

    /**
     * Whether there is anything at all in the window.
     *
     * Stays a method: it is a question about the whole map rather than one
     * piece of state, and the page needs it to choose between the day list and
     * a single "nothing in this window" line.
     */
    public function isEmpty(): bool
    {
        foreach ($this->days as $entries) {
            if ([] !== $entries) {
                return false;
            }
        }

        return true;
    }
}
