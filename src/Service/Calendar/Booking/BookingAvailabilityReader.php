<?php

declare(strict_types=1);

namespace App\Service\Calendar\Booking;

use App\Domain\DTO\Calendar\BookableSlot;
use App\Domain\Enum\Calendar\EventStatus;
use App\Entity\Calendar\BookingPage;
use App\Repository\Calendar\CalendarBookingRepository;
use App\Repository\Calendar\CalendarEventOccurrenceRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Which of a page's slots are actually free, checked against the owner's real
 * diary.
 *
 * "Actually" is the requirement and it is why this exists at all: a page that
 * offered its configured hours and let the owner sort out the collisions would
 * be a worse calendar than a piece of paper. So every public read of a booking
 * page asks the occurrence table — the same table the owner's own month view
 * reads — across every calendar the page nominates.
 *
 * ── Three queries, never one per slot ─────────────────────────────────────
 *
 * A month of half-hour slots on a five-day week is around three hundred
 * candidates. The naive shape asks "is this free?" per slot and makes three
 * hundred round trips to render one page that anybody on the internet can ask
 * for. This makes exactly two reads — the occurrences overlapping the whole
 * window, and the instants already booked on this page — and does the
 * subtraction in PHP against arrays that are already in memory. The generator
 * makes none.
 *
 * ── The buffer is applied to the busy interval, not to the slot ───────────
 *
 * An event from 10:00 to 11:00 with a fifteen minute buffer occupies 09:45 to
 * 11:15 as far as this is concerned. Doing it the other way — widening the slot
 * — gives the same answer for a symmetric buffer and a different one the moment
 * somebody wants an asymmetric one, and it also makes the query window wrong:
 * an event starting just outside the window can still reach into it once the
 * buffer is added, which is why the occurrence read is widened by the buffer
 * too.
 *
 * ── What counts as busy ───────────────────────────────────────────────────
 *
 * Everything on the nominated calendars that is not cancelled, whatever its
 * privacy. A secret event blocks: it is the owner's time, and the one thing a
 * booking page must never do is let a stranger book over something because it
 * was too private to mention. Nothing about it reaches the page — the slot is
 * simply absent, which is indistinguishable from an hour outside the working
 * day.
 *
 * A cancelled event does NOT block, and neither does a cancelled occurrence.
 * That is the whole of "a cancelled or moved owner event frees its slot again":
 * this asks the table on every request rather than caching a window, so calling
 * a meeting off makes its hour reappear on the next page load, and dragging one
 * to Thursday moves the hole with it because RecurrenceMaterialiser has already
 * rewritten the occurrence row. There is nothing to invalidate and nothing that
 * can be stale.
 *
 * An event whose JSCalendar says `freeBusyStatus: "free"` does not block
 * either. That is RFC 8984 §4.4.5 and it is the property a calendar sets on
 * "working from home" and other markers — honouring it is what stops an all-day
 * banner erasing a week of availability.
 */
final readonly class BookingAvailabilityReader
{
    /**
     * The JSCalendar value that means "this is in my calendar and does not
     * occupy me" (RFC 8984 §4.4.5). Named rather than inlined because the
     * absence of the key means the opposite, and a reader comparing against the
     * wrong spelling would silently treat every event as free.
     */
    private const string FREE_BUSY_FREE = 'free';

    public function __construct(
        private BookingSlotGenerator              $generator,
        private CalendarEventOccurrenceRepository $occurrences,
        private CalendarBookingRepository         $bookings,
    ) {
    }

    /**
     * Every slot on this page a stranger could take right now.
     *
     * $now is passed rather than read for the reason ShareLinkReader's is: the
     * notice period, the horizon and the busy window must all be computed
     * against one instant, and a test has to be able to say which.
     *
     * @return list<BookableSlot>
     */
    public function freeSlots(BookingPage $page, DateTimeImmutable $now): array
    {
        [$from, $to] = $this->window($page, $now);

        if ($from >= $to) {
            return [];
        }

        $candidates = $this->generator->generate($page, $from, $to);

        if ([] === $candidates) {
            return [];
        }

        $busy  = $this->busyIntervals($page, $from, $to);
        $taken = $this->bookings->takenInstantsFor($page, $from, $to);

        $free = [];

        foreach ($candidates as $slot) {
            if (true === array_key_exists($slot->key(), $taken)) {
                continue;
            }

            if (true === $this->collides($slot, $busy)) {
                continue;
            }

            $free[] = $slot;
        }

        return $free;
    }

    /**
     * The same slots, grouped by the local day they fall on in the clock the
     * page is being READ on.
     *
     * Grouped here rather than in Twig for the reason CalendarRangeReader gives
     * about its own day walk: it is date arithmetic, it depends on a zone, and
     * a template doing it gets one day a year wrong. The zone is the visitor's
     * rather than the owner's, and that is the whole point of the method — a
     * booker in Auckland must see Tuesday's slots under Tuesday's heading on
     * their own calendar, not under Monday's on somebody else's.
     *
     * Only empty days are absent, unlike ShareLinkReader's day map. A shared
     * calendar renders its blank days because "free on the 4th" is information;
     * a booking page's blank day is a day with nothing to click, and a heading
     * over nothing reads as a page that is broken.
     *
     * @return array<string, list<BookableSlot>>
     */
    public function freeSlotsByDay(BookingPage $page, DateTimeImmutable $now, DateTimeZone $display): array
    {
        $days = [];

        foreach ($this->freeSlots($page, $now) as $slot) {
            $days[$slot->startsAt->setTimezone($display)->format('Y-m-d')][] = $slot;
        }

        return $days;
    }

    /**
     * The slot a posted instant names, if it is still free.
     *
     * Re-derived from freeSlots() rather than trusted from the form, and that
     * is not belt-and-braces — it is the only thing standing between a booking
     * page and "POST any instant you like into somebody's calendar". The
     * browser posts a string; this decides whether that string is one of the
     * appointments the page was offering, at this moment, against this diary.
     *
     * Still not enough on its own, and deliberately so: two requests can both
     * pass this and only one can win. The unique constraint on CalendarBooking
     * is what settles that — see BookingService.
     */
    public function findFreeSlot(BookingPage $page, DateTimeImmutable $now, string $slotKey): ?BookableSlot
    {
        foreach ($this->freeSlots($page, $now) as $slot) {
            if ($slot->key() === $slotKey) {
                return $slot;
            }
        }

        return null;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The bookable window: no sooner than the notice period, no further than
     * the horizon.
     *
     * Both clamped here rather than trusted from the row, because a public GET
     * drives this and the form is not the only writer — the same reason the
     * generator clamps its own loops.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function window(BookingPage $page, DateTimeImmutable $now): array
    {
        $notice  = max(0, $page->noticeMinutes);
        $horizon = max(1, min(BookingPage::MAX_HORIZON_DAYS, $page->horizonDays));

        $utc = new DateTimeZone('UTC');

        return [
            $now->setTimezone($utc)->modify(sprintf('+%d minutes', $notice)),
            $now->setTimezone($utc)->modify(sprintf('+%d days', $horizon)),
        ];
    }

    /**
     * The intervals the owner is occupied, already widened by the buffer.
     *
     * One query for the window, widened by the buffer at both ends so an event
     * whose buffer reaches into the window is not missed by starting just
     * outside it.
     *
     * @return list<array{0: DateTimeImmutable, 1: DateTimeImmutable}>
     */
    private function busyIntervals(BookingPage $page, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $calendarIds = [];

        foreach ($page->calendarsToCheck() as $calendar) {
            $calendarIds[] = (int) $calendar->id;
        }

        if ([] === $calendarIds) {
            return [];
        }

        $buffer = max(0, $page->bufferMinutes);

        $occurrences = $this->occurrences->findInRange(
            $page->usr,
            $calendarIds,
            $from->modify(sprintf('-%d minutes', $buffer)),
            $to->modify(sprintf('+%d minutes', $buffer)),
        );

        $intervals = [];

        foreach ($occurrences as $occurrence) {
            $event = $occurrence->event;

            if (null === $event || null === $occurrence->startsAt || null === $occurrence->endsAt) {
                continue;
            }

            if (EventStatus::Cancelled === $event->status) {
                continue;
            }

            if (self::FREE_BUSY_FREE === ($event->jscalendar['freeBusyStatus'] ?? null)) {
                continue;
            }

            $intervals[] = [
                $occurrence->startsAt->modify(sprintf('-%d minutes', $buffer)),
                $occurrence->endsAt->modify(sprintf('+%d minutes', $buffer)),
            ];
        }

        return $intervals;
    }

    /**
     * Half-open overlap: a slot ending exactly when a meeting starts does not
     * collide.
     *
     * The same convention every end in this schema uses — CalendarEventOccurrence
     * builds its tsrange as `[)` — so back-to-back appointments stay bookable,
     * which is what a zero buffer is supposed to mean.
     *
     * @param list<array{0: DateTimeImmutable, 1: DateTimeImmutable}> $intervals
     */
    private function collides(BookableSlot $slot, array $intervals): bool
    {
        foreach ($intervals as [$busyFrom, $busyTo]) {
            if ($slot->startsAt < $busyTo && $slot->endsAt > $busyFrom) {
                return true;
            }
        }

        return false;
    }
}
