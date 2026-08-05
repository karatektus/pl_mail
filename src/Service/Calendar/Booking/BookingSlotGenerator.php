<?php

declare(strict_types=1);

namespace App\Service\Calendar\Booking;

use App\Domain\DTO\Calendar\BookableSlot;
use App\Entity\Calendar\BookingPage;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The slots a page would offer if the owner's diary were empty.
 *
 * Split from the busy check on purpose. What the availability rules produce and
 * what the diary removes are two different kinds of wrong — a slot at the wrong
 * hour is a rules bug, a slot on top of a meeting is a busy-check bug — and
 * keeping them apart means each can be tested against a fixed answer instead of
 * against whatever the other one happened to do. BookingAvailabilityReader is
 * the half that subtracts.
 *
 * ── Bounded, twice ────────────────────────────────────────────────────────
 *
 * The day walk runs at most BookingPage::MAX_HORIZON_DAYS iterations and the
 * per-day walk at most MINUTES_IN_DAY / slotMinutes, both clamped here rather
 * than trusted from the row. A public GET drives this, so "the loop terminates
 * because the form validates" is not a guarantee — the form is one writer and
 * the console, a future import and a hand-edited row are others.
 *
 * ── Timezones, which is the part that is actually hard ────────────────────
 *
 * A slot is a WALL CLOCK fact in the owner's zone — 09:00 means nine in the
 * morning — and an INSTANT everywhere else. So each day's slots are built by
 * constructing local midnight for that calendar date in the owner's zone and
 * adding minutes to it, then converting to UTC. The obvious alternative, taking
 * the previous slot and adding the slot length, is wrong twice a year:
 *
 *   In autumn the clocks go back and an hour repeats. Local arithmetic walks
 *   02:00 → 02:30 → 03:00 in wall-clock terms while the underlying instants
 *   jump by an extra hour, so the day silently loses an hour of slots or gains
 *   a pair that sit on top of each other, depending on which side the addition
 *   is done on.
 *
 *   In spring an hour does not exist. 02:30 on the changeover day is not a
 *   time; PHP resolves it forward to 03:30, so two adjacent local slots can
 *   land on the SAME instant and the page offers the same appointment twice.
 *
 * Both are handled by one rule rather than by two special cases: the slot is
 * built from local midnight plus an offset, and a slot whose UTC instant has
 * already been produced this run is dropped. That makes the spring duplicate
 * impossible by construction and leaves the autumn repeat producing each real
 * instant once — which is the honest answer, since both 02:30s are genuinely
 * bookable hours of the owner's day.
 *
 * Local midnight itself is taken through a format-and-reparse rather than by
 * modifying an instant, because 'midnight' applied to a DateTimeImmutable in a
 * zone that has just changed offset lands an hour out on the one day a year it
 * matters.
 */
final readonly class BookingSlotGenerator
{
    /**
     * The instants a slot may start on, in order, ignoring everything already
     * in the diary.
     *
     * $from and $to bound the window in real time; the caller has already
     * decided what those are from the page's notice period and horizon, because
     * that is a policy about bookings rather than about slots.
     *
     * @return list<BookableSlot>
     */
    public function generate(BookingPage $page, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $slotMinutes = max(BookingPage::MIN_SLOT_MINUTES, $page->slotMinutes);
        $open        = $page->openMinutes();

        // A page whose hours are backwards or empty offers nothing. Answered as
        // an empty list rather than as an exception: it is a configuration a
        // form can produce, the public page renders "no times available", and a
        // 500 on a stranger's GET would be the wrong way to tell the owner.
        if ($open < $slotMinutes || [] === $page->weekdays) {
            return [];
        }

        $zone  = $this->zoneOf($page);
        $slots = [];
        $seen  = [];

        // Local dates, not instants. The window is bounded in UTC but the days
        // are the owner's, so the walk is over calendar dates in their zone and
        // the bounds are re-applied per slot below.
        $day  = $from->setTimezone($zone);
        $last = $to->setTimezone($zone);

        for ($index = 0; $index <= BookingPage::MAX_HORIZON_DAYS; $index++) {
            if ($day > $last) {
                break;
            }

            if (true === $page->isOpenOn((int) $day->format('N'))) {
                foreach ($this->slotsOn($page, $day, $zone, $slotMinutes) as $slot) {
                    if ($slot->startsAt < $from || $slot->startsAt >= $to) {
                        continue;
                    }

                    // The spring-forward guard. Two local wall clocks that do
                    // not both exist collapse onto one instant, and offering
                    // the same appointment twice is worse than losing the
                    // second spelling of it.
                    if (true === array_key_exists($slot->key(), $seen)) {
                        continue;
                    }

                    $seen[$slot->key()] = true;
                    $slots[]            = $slot;
                }
            }

            $day = $day->modify('+1 day');
        }

        return $slots;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * One local day's slots, built from local midnight plus an offset each.
     *
     * @return list<BookableSlot>
     */
    private function slotsOn(
        BookingPage       $page,
        DateTimeImmutable $day,
        DateTimeZone      $zone,
        int               $slotMinutes,
    ): array {
        $midnight = new DateTimeImmutable($day->format('Y-m-d') . ' 00:00:00', $zone);
        $utc      = new DateTimeZone('UTC');

        $slots = [];

        // Strictly `+ $slotMinutes <=`, so a slot never runs past the end of
        // the bookable day. A page open 09:00–17:00 with 45 minute slots offers
        // ten and stops at 16:30, rather than offering an eleventh that ends at
        // 17:15 and books over whatever the owner does at five.
        for (
            $offset = $page->startMinute;
            $offset + $slotMinutes <= $page->endMinute;
            $offset += $slotMinutes
        ) {
            $startsAt = $midnight->modify(sprintf('+%d minutes', $offset));
            $endsAt   = $midnight->modify(sprintf('+%d minutes', $offset + $slotMinutes));

            $slots[] = new BookableSlot($startsAt->setTimezone($utc), $endsAt->setTimezone($utc));
        }

        return $slots;
    }

    /**
     * The page's zone, or UTC when it names one PHP does not know.
     *
     * A page can only get an unknown zone from a hand-edited row — the form
     * offers a list — but a public GET must not 500 on one, and UTC is the
     * answer that shows *something* rather than nothing.
     */
    private function zoneOf(BookingPage $page): DateTimeZone
    {
        try {
            return new DateTimeZone($page->timeZone);
        } catch (\Exception) {
            return new DateTimeZone('UTC');
        }
    }
}
