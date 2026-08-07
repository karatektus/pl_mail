<?php

declare(strict_types=1);

namespace App\Service\Calendar\Booking;

use App\Domain\DTO\Calendar\BookableSlot;
use App\Domain\DTO\Calendar\BookingWeek;
use App\Domain\DTO\Calendar\BookingWeekDay;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Cutting a booking page's free slots into the week the reader is looking at.
 *
 * **It generates nothing.** BookingAvailabilityReader decides which slots exist
 * — against the owner's real diary, in the owner's zone, at the instant the
 * request arrived — and this only groups what it produced. The separation is
 * the point: a week that could add a slot would be a second answer to "is this
 * time free", and the two would disagree the moment one of them was cached.
 *
 * ── The week is a display, like the zone ──────────────────────────────────
 *
 * Which week is on screen comes from a query parameter, so it is exactly as
 * trustworthy as the `tz` beside it: it changes what is PRINTED and nothing
 * about what may be booked. A hand-edited week outside the offered range is
 * clamped to the nearest one that exists rather than refused, because the POST
 * re-derives its slot from the reader and would refuse an instant this never
 * offered anyway — see BookingAvailabilityReader::findFreeSlot(), which is the
 * thing actually standing between this page and "book any hour you like".
 *
 * ── Where it opens ────────────────────────────────────────────────────────
 *
 * On the week holding the first free slot, not on "this week". A page whose
 * notice period is a fortnight has nothing this week by construction, and
 * opening on a week of empty columns is a page that looks broken to the person
 * it was published for.
 */
final readonly class BookingWeekBuilder
{
    /** What a `week` parameter has to look like before it is believed. */
    private const string WEEK_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    /**
     * @param array<string, list<BookableSlot>> $days free slots keyed Y-m-d in $display,
     *                                                as BookingAvailabilityReader groups them —
     *                                                days with nothing free are absent
     */
    public function build(
        array             $days,
        DateTimeZone      $display,
        DateTimeImmutable $now,
        ?string           $week,
    ): BookingWeek {
        $today = $now->setTimezone($display)->format('Y-m-d');
        $dates = array_keys($days);

        sort($dates);

        // Nothing free anywhere in the horizon. The week around today, with no
        // steps either side: there is no other week to offer, and prev/next
        // that led to more of the same would be a control that does nothing.
        if ([] === $dates) {
            return $this->week(
                $now->setTimezone($display)->modify('midnight')->modify('monday this week'),
                $days,
                $today,
                null,
                null,
            );
        }

        $first = $this->monday($dates[0], $display);
        $last  = $this->monday($dates[count($dates) - 1], $display);

        $startsOn = $this->clamp($this->requested($week, $display) ?? $first, $first, $last);

        return $this->week(
            $startsOn,
            $days,
            $today,
            $startsOn > $first ? $startsOn->modify('-7 days')->format('Y-m-d') : null,
            $startsOn < $last ? $startsOn->modify('+7 days')->format('Y-m-d') : null,
        );
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, list<BookableSlot>> $days
     */
    private function week(
        DateTimeImmutable $startsOn,
        array             $days,
        string            $today,
        ?string           $previous,
        ?string           $next,
    ): BookingWeek {
        $columns = [];
        $cursor  = $startsOn;

        for ($column = 0; $column < 7; $column++) {
            $date = $cursor->format('Y-m-d');

            $columns[] = new BookingWeekDay($date, $date === $today, $date < $today, $days[$date] ?? []);

            $cursor = $cursor->modify('+1 day');
        }

        return new BookingWeek($startsOn, $columns, $previous, $next);
    }

    /** The Monday of the week a Y-m-d falls in, at local midnight. */
    private function monday(string $date, DateTimeZone $display): DateTimeImmutable
    {
        $day = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date . ' 00:00:00', $display);

        // The keys come from DateTimeImmutable::format() a few frames up the
        // stack, so this cannot fail; the fallback exists because the signature
        // says it can and a null here would be a fatal rather than a page.
        if (false === $day) {
            return new DateTimeImmutable('midnight', $display)->modify('monday this week');
        }

        // 'monday this week' on a Monday is that Monday, so a week is not
        // dragged back seven days when the reader is already on one.
        return $day->modify('monday this week');
    }

    private function requested(?string $week, DateTimeZone $display): ?DateTimeImmutable
    {
        if (null === $week || 1 !== preg_match(self::WEEK_PATTERN, $week)) {
            return null;
        }

        return $this->monday($week, $display);
    }

    private function clamp(
        DateTimeImmutable $candidate,
        DateTimeImmutable $first,
        DateTimeImmutable $last,
    ): DateTimeImmutable {
        if ($candidate < $first) {
            return $first;
        }

        return $candidate > $last ? $last : $candidate;
    }
}
