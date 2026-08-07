<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

use DateTimeImmutable;

/**
 * One week of a booking page: seven columns, and where the weeks either side
 * are.
 *
 * The page used to render every free day between now and the horizon as one
 * scroll — thirty days of headings on a default page, which is a list of times
 * rather than a calendar somebody recognises. A week is the unit the reader
 * actually decides in ("can I do Thursday?"), it fits seven columns on a laptop
 * and seven rows on a phone, and it gives the page the one control it was
 * missing: somewhere to go when this week does not suit.
 *
 * **Every day in the week is present, including the empty ones.** Same rule
 * SharedCalendarView keeps for its own days and for the same reason: "nothing
 * free on Wednesday" has to be something the page says, not a heading the
 * reader has to notice is missing. What it must not say is why — see
 * BookingWeekDay.
 *
 * $previous and $next are null at the ends of what the page offers, which are
 * the notice period at one end and the horizon at the other. They are not
 * "this week ± 7 days": stepping past the horizon would show a week of empty
 * columns that looks exactly like a week the owner has no time in.
 */
final readonly class BookingWeek
{
    /**
     * @param list<BookingWeekDay> $days     seven, Monday first
     * @param string|null          $previous 'Y-m-d' of that week's Monday, or null
     * @param string|null          $next     'Y-m-d' of that week's Monday, or null
     */
    public function __construct(
        public DateTimeImmutable $startsOn,
        public array             $days,
        public ?string           $previous,
        public ?string           $next,
    ) {
    }

    /** Whether this week offers anything at all — the honest empty state. */
    public function isEmpty(): bool
    {
        foreach ($this->days as $day) {
            if ([] !== $day->slots) {
                return false;
            }
        }

        return true;
    }
}
