<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

use DateTimeImmutable;

/**
 * One appointment somebody could take: an instant, a length, and nothing about
 * why it is free.
 *
 * The absence is the design. A slot never carries what it was checked against —
 * not the events it dodged, not the calendars they were on, not the reason a
 * neighbouring slot is missing — because the whole object is rendered on a page
 * anybody with the URL can open. A booking page that said "free, because the
 * 10:00 dentist appointment ends at 10:45" would be a busy/free leak with extra
 * steps, and the way to make that impossible is for the concrete data never to
 * reach the object the template sees. The same argument SharedOccurrence makes,
 * arrived at from the other feature.
 *
 * $startsAt is UTC and is the value the booking form posts back. It is the only
 * thing that identifies a slot — not an index into a list, not a day and a row
 * — because the list is regenerated on the POST and an index would name a
 * different hour the moment somebody else's booking shifted it.
 */
final readonly class BookableSlot
{
    public function __construct(
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
    ) {
    }

    /**
     * How this slot is spelled in a form field and in a URL.
     *
     * The same format CalendarBookingRepository keys taken instants by, and it
     * is deliberately the plain UTC one rather than ISO 8601 with an offset:
     * two spellings of one moment would make the posted value fail to match a
     * generated slot, and the booking would be refused as "no longer available"
     * for a slot that was sitting right there.
     */
    public function key(): string
    {
        return $this->startsAt->format('Y-m-d H:i:s');
    }
}
