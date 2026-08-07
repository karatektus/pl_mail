<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

/**
 * One column of a booking page's week: a date, and the times still free on it.
 *
 * **An empty $slots says nothing about why.** It is the same day with no free
 * appointments whether the owner does not work Sundays, is on holiday, or is
 * booked solid — BookableSlot carries an instant and a length and nothing else,
 * so there is no third state here for the template to draw differently. That is
 * the rule the whole booking feature keeps, stated on BookableSlot: whoever
 * holds this URL is not entitled to read the owner's diary out of the shape of
 * the holes in it.
 *
 * **$isPast is the one exception, and it is not an exception at all.** That a
 * Tuesday already happened is a fact about the clock, not about the owner — the
 * date is printed on the column and the reader can work it out — and without it
 * the first week of a page opened on a Friday is four dead columns saying
 * "nothing free" as though somebody had blocked out their whole week. Dimming
 * them says "over", which is what they are.
 */
final readonly class BookingWeekDay
{
    /**
     * @param list<BookableSlot> $slots in start order, empty for a day with nothing free
     */
    public function __construct(
        public string $date,
        public bool   $isToday,
        public bool   $isPast,
        public array  $slots = [],
    ) {
    }
}
