<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sharing;

use App\Domain\DTO\Calendar\SharedCalendarDay;
use App\Domain\DTO\Calendar\SharedCalendarRange;
use App\Domain\DTO\Calendar\SharedCalendarView;
use App\Domain\DTO\Calendar\SharedOccurrence;
use App\Domain\Enum\Calendar\CalendarView;
use App\Service\Calendar\DayGridLayout;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Arranging an already-redacted shared window into one page of one view.
 *
 * **It reads nothing.** The only input that came from the database is a
 * SharedCalendarView, which ShareLinkReader has already stripped — this class
 * has no repository, no link and no event, and there is no path from what it is
 * given back to one. That is deliberate and it is the reason four views could be
 * added at all: rearranging redacted objects cannot leak, whereas a second
 * reader that fetched a week for itself would be a second place the redaction
 * had to be remembered. It grew out of SharedCalendarMonthBuilder, which did
 * exactly this for the month alone.
 *
 * ── One builder, four views ───────────────────────────────────────────────
 *
 * What each view means by "a page" is CalendarView::range()'s answer, which is
 * the same method the authenticated calendar's reader asks — so the two
 * calendars cannot end up disagreeing about how many days a week has or which
 * Monday a month grid starts on. What is left here is the three things that are
 * true of a SHARED calendar and of no other: the window, the clamping, and
 * paging that stops at the window's edges.
 *
 * ── Why the arithmetic is here and not in Twig ────────────────────────────
 *
 * Every part of it is date arithmetic in the owner's zone across a possible DST
 * boundary — the day walk, "which Monday", "is today in the window", the
 * exclusive end. The rule this codebase keeps, stated in CalendarRangeReader and
 * in ShareLinkReader, is that a template doing that gets one day a year wrong.
 *
 * ── The anchor is clamped, never refused ──────────────────────────────────
 *
 * A date arrives in a public URL, which means it arrives hand-edited. It is
 * clamped into the window rather than answered with an error, for the reason
 * ShareLinkReader clamps its rolling count: answering 404 to a mistyped date is
 * a worse page than answering the nearest day that exists. A bad TOKEN is still
 * a 404 — that is a different question, and the controller's own docblock says
 * why all three of its refusals look alike.
 *
 * ── Paging is bounded by what the next page would publish ──────────────────
 *
 * $previous and $next are null unless the stepped page would contain at least
 * one day that is both inside the window and inside that page's own span. One
 * rule, and it produces the right answer for all four views without a match:
 * stepping a month grid back from a window that lives entirely in August offers
 * July, whose 42 cells DO overlap the window by two spill-in days — and whose
 * own month publishes nothing, so it is refused. An empty page a reader could
 * reach reads as "free all that month", which is a claim about the owner's diary
 * that nobody made.
 *
 * ── A midnight-crossing entry is on both columns of a time-grid ────────────
 *
 * ShareLinkReader files each entry under the day it STARTS, which is right for a
 * list: an entry printed on two days reads as two meetings. It is wrong for a
 * grid. A meeting running 23:00 to 01:00 that appeared only in the first column
 * would leave the second column empty at 00:30 — and an empty published column
 * is this page saying its owner is free, which is the one thing it must never
 * say untruthfully. So the grid's map is walked separately, spreading each entry
 * over every day it touches, exactly as CalendarRangeReader does for the
 * owner's own calendar. Still a rearrangement of the same objects; still nothing
 * fetched.
 */
final readonly class SharedCalendarRangeBuilder
{
    public function __construct(private DayGridLayout $layout)
    {
    }

    /**
     * One page of $which over $view's window.
     *
     * $now is passed rather than read for the reason ShareLinkReader::read()
     * passes it: the window, the default anchor and the "today" mark are three
     * answers to what time it is, and within one request they must be the same
     * answer.
     *
     * $date is whatever the URL carried, believed only if it parses.
     */
    public function build(
        SharedCalendarView $view,
        CalendarView       $which,
        ?string            $date,
        DateTimeImmutable  $now,
    ): SharedCalendarRange {
        $zone = new DateTimeZone($view->zone);

        $firstDay = $view->from->setTimezone($zone)->modify('midnight');

        // The window's end is exclusive — midnight on the morning after — so the
        // last day it covers is the day before it. Taking $to itself would
        // publish a day the link does not.
        $lastDay = $view->to->setTimezone($zone)->modify('midnight')->modify('-1 day');

        if ($lastDay < $firstDay) {
            $lastDay = $firstDay;
        }

        $today  = $now->setTimezone($zone)->modify('midnight');
        $anchor = $this->anchor($which, $date, $firstDay, $lastDay, $today);

        [$from, $to] = $which->range($anchor);

        return new SharedCalendarRange(
            view:     $which,
            anchor:   $anchor,
            from:     $from,
            to:       $to,
            days:     $this->days($view, $which, $anchor, $from, $to, $firstDay, $lastDay),
            grid:     $this->grid($view, $which, $from, $to, $firstDay, $lastDay, $zone),
            previous: $this->step($view, $which, $anchor, -1, $firstDay, $lastDay),
            next:     $this->step($view, $which, $anchor, 1, $firstDay, $lastDay),
            today:    $today >= $firstDay && $today <= $lastDay ? $today->format('Y-m-d') : null,
        );
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Which day the page opens on: the one asked for, else today, else the
     * window's first — each clamped into the window and then normalised to what
     * its view means by an anchor.
     *
     * Today first among the fallbacks because a rolling link is nearly always
     * read on the day it is opened, and a page that opened on the window's first
     * day would be right and useless for a link that starts next quarter — which
     * the fallback behind it then answers.
     */
    private function anchor(
        CalendarView      $which,
        ?string           $date,
        DateTimeImmutable $firstDay,
        DateTimeImmutable $lastDay,
        DateTimeImmutable $today,
    ): DateTimeImmutable {
        $requested = $this->parse($date, $firstDay->getTimezone())
            ?? ($today >= $firstDay && $today <= $lastDay ? $today : $firstDay);

        $clamped = $requested < $firstDay ? $firstDay : ($requested > $lastDay ? $lastDay : $requested);

        // Normalised so that a step is a step and not a rollover. `+1 month` on
        // the 31st is 3 March and PHP will do it silently; from the first of the
        // month it is the month after, every time. A week is anchored on its own
        // Monday for the same reason — two anchors in the same week must produce
        // the same page, or the "next" link from a Wednesday would land on the
        // following Wednesday and draw a week overlapping the one it came from.
        return match ($which) {
            CalendarView::Month        => $clamped->modify('first day of this month'),
            CalendarView::Week         => $clamped->modify('monday this week'),
            CalendarView::Day,
            CalendarView::Agenda       => $clamped,
        };
    }

    /**
     * A Y-m-d from a public URL, or null.
     *
     * Built from the string with an exact format rather than handed to the
     * constructor, so "2026-02-31" and "yesterday" are both refused instead of
     * being interpreted — a relative date in a URL would let the page be linked
     * to in a way that means something different tomorrow.
     */
    private function parse(?string $date, DateTimeZone $zone): ?DateTimeImmutable
    {
        if (null === $date) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);

        if (false === $parsed || $parsed->format('Y-m-d') !== $date) {
            return null;
        }

        return $parsed;
    }

    /**
     * Every day this page draws, in order — including the ones the window does
     * not cover, because a cell that is not there cannot be drawn as
     * unpublished.
     *
     * @return list<SharedCalendarDay>
     */
    private function days(
        SharedCalendarView $view,
        CalendarView       $which,
        DateTimeImmutable  $anchor,
        DateTimeImmutable  $from,
        DateTimeImmutable  $to,
        DateTimeImmutable  $firstDay,
        DateTimeImmutable  $lastDay,
    ): array {
        $anchorMonth = $anchor->format('Y-m');
        $windowFrom  = $firstDay->format('Y-m-d');
        $windowTo    = $lastDay->format('Y-m-d');

        $days = [];

        for ($cursor = $from; $cursor < $to; $cursor = $cursor->modify('+1 day')) {
            $date = $cursor->format('Y-m-d');

            $days[] = new SharedCalendarDay(
                $date,
                // Only a month grid has a span narrower than its range. See
                // SharedCalendarDay::$isInSpan.
                CalendarView::Month === $which ? $cursor->format('Y-m') === $anchorMonth : true,
                $date >= $windowFrom && $date <= $windowTo,
                $view->days[$date] ?? [],
            );
        }

        return $days;
    }

    /**
     * The placements for a time-grid page, keyed by day — and nothing at all for
     * a view with no time axis.
     *
     * Gated on CalendarView::isTimeGrid() rather than on who is asking, exactly
     * as CalendarRangeReader gates its own: a month is 42 days that no view will
     * ever position, and an agenda's whole value is that it skips the empty time
     * between entries.
     *
     * A day the window does not cover is absent from the map, which is what
     * makes the shell dim its column rather than rule twenty-four empty hours
     * across it.
     *
     * @return array<string, \App\Domain\DTO\Calendar\DayGrid>
     */
    private function grid(
        SharedCalendarView $view,
        CalendarView       $which,
        DateTimeImmutable  $from,
        DateTimeImmutable  $to,
        DateTimeImmutable  $firstDay,
        DateTimeImmutable  $lastDay,
        DateTimeZone       $zone,
    ): array {
        if (false === $which->isTimeGrid()) {
            return [];
        }

        $windowFrom = $firstDay->format('Y-m-d');
        $windowTo   = $lastDay->format('Y-m-d');

        /** @var array<string, list<SharedOccurrence>> $columns */
        $columns = [];

        for ($cursor = $from; $cursor < $to; $cursor = $cursor->modify('+1 day')) {
            $date = $cursor->format('Y-m-d');

            if ($date >= $windowFrom && $date <= $windowTo) {
                $columns[$date] = [];
            }
        }

        foreach ($view->days as $entries) {
            foreach ($entries as $entry) {
                foreach ($this->touched($entry, $zone) as $date) {
                    if (true === array_key_exists($date, $columns)) {
                        $columns[$date][] = $entry;
                    }
                }
            }
        }

        return $this->layout->place($columns, $zone);
    }

    /**
     * Every local day one entry occupies.
     *
     * An all-day entry is FLOATING — a wall date at midnight carrying no zone —
     * so it is read as it is stored. Converting it into the link's zone does not
     * translate it, it moves it, which files it on the day before for any reader
     * west of UTC. Same rule as CalendarRangeReader and as ShareLinkReader's own
     * day walk.
     *
     * The loop is bounded by the entry's own length, and an entry's length is
     * bounded by the window the reader queried plus its padding — so a
     * ten-year event cannot turn one public GET into ten years of arithmetic.
     * A zero-length entry still occupies the day it starts on, which is what the
     * `do` shape below guarantees.
     *
     * @return list<string>
     */
    private function touched(SharedOccurrence $entry, DateTimeZone $zone): array
    {
        $floating = $entry->isAllDay;

        $start = $floating ? $entry->startsAt : $entry->startsAt->setTimezone($zone);
        $end   = $floating ? $entry->endsAt : $entry->endsAt->setTimezone($zone);

        $cursor = $start->setTime(0, 0);
        $dates  = [];

        do {
            $dates[] = $cursor->format('Y-m-d');
            $cursor  = $cursor->modify('+1 day');
        } while ($cursor < $end);

        return $dates;
    }

    /**
     * The anchor one page either side, or null when that page publishes nothing.
     *
     * The step is taken on the normalised anchor — the first of a month, the
     * Monday of a week — so a month step is a month and not a rollover onto the
     * 3rd of March.
     */
    private function step(
        SharedCalendarView $view,
        CalendarView       $which,
        DateTimeImmutable  $anchor,
        int                $direction,
        DateTimeImmutable  $firstDay,
        DateTimeImmutable  $lastDay,
    ): ?string {
        // A count and a unit rather than one string, because the agenda's step
        // is thirty of something: `sprintf('%+d %s', -1, '30 days')` builds
        // "-1 30 days", which PHP accepts and does not mean.
        [$count, $unit] = match ($which) {
            CalendarView::Month  => [1, 'month'],
            CalendarView::Week   => [1, 'week'],
            CalendarView::Day    => [1, 'day'],
            // A page rather than a day, unlike the authenticated agenda's
            // rolling list. That one steps a day at a time because it has
            // nowhere to arrive; this one is a bounded window, and a day at a
            // time would be fourteen near-identical pages of a fortnight link.
            // Thirty days is CalendarView::Agenda's own range, so the pages
            // abut rather than overlap.
            CalendarView::Agenda => [30, 'days'],
        };

        $stepped = $anchor->modify(sprintf('%+d %s', $direction * $count, $unit));

        [$from, $to] = $which->range($stepped);

        return $this->publishes($which, $stepped, $from, $to, $firstDay, $lastDay)
            ? $stepped->format('Y-m-d')
            : null;
    }

    /**
     * Whether a page would have at least one day that is both inside the window
     * and inside its own span.
     *
     * Both halves are load-bearing, and the month grid is why. Its 42 cells
     * reach a week into each neighbour, so a window living entirely in August
     * DOES overlap July's grid — by two spill-in cells that July's page draws
     * dimmed and lists nowhere. Offering that step would put a page on the
     * internet that says nothing and looks like an empty month.
     */
    private function publishes(
        CalendarView      $which,
        DateTimeImmutable $anchor,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        DateTimeImmutable $firstDay,
        DateTimeImmutable $lastDay,
    ): bool {
        $anchorMonth = $anchor->format('Y-m');
        $windowFrom  = $firstDay->format('Y-m-d');
        $windowTo    = $lastDay->format('Y-m-d');

        for ($cursor = $from; $cursor < $to; $cursor = $cursor->modify('+1 day')) {
            $date = $cursor->format('Y-m-d');

            if ($date < $windowFrom || $date > $windowTo) {
                continue;
            }

            if (CalendarView::Month === $which && $cursor->format('Y-m') !== $anchorMonth) {
                continue;
            }

            return true;
        }

        return false;
    }
}
