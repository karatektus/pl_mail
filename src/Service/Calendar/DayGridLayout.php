<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Domain\DTO\Calendar\DayGrid;
use App\Domain\DTO\Calendar\PlacedEntry;
use App\Domain\Interface\TimeGridEntryInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Turning a day's entries into positions on a time-grid.
 *
 * In PHP rather than in Twig or in the browser, for the same reason
 * CalendarRangeReader groups by day here: it is date arithmetic in a specific
 * zone, and a template that does date arithmetic is a template that gets it
 * wrong on one day a year. Doing it in the browser would be worse still — the
 * grid would render at the wrong positions and then jump, and a user with
 * JavaScript off would get a column of blocks all at midnight.
 *
 * **Positions are wall-clock minutes since local midnight, not elapsed time.**
 * The grid draws twenty-four labelled rows, so a block has to land against the
 * row whose label matches the clock the user reads the event in; deriving the
 * offset from a timestamp difference instead would agree with the labels on
 * every day except the two a year that are not twenty-four hours long, and
 * disagree by an hour on those. The consequence is stated rather than hidden:
 * on the day a zone springs forward the 02:00 row is drawn although no event
 * can be in it, and on the day it falls back the two 02:00s are drawn on top of
 * each other as an overlap. Both are what every other calendar does, and both
 * are honest about a grid that has one row per label.
 *
 * **Overlap is answered by lanes, in runs.** Everything that overlaps anything
 * else in an unbroken run of the day shares one lane count, so the block edges
 * line up down the whole run. Sizing each pair independently is the obvious
 * cheaper thing and it produces a column where a two-wide block sits beside a
 * three-wide one, which reads as a rendering fault rather than as information.
 *
 * Deliberately NOT done: an event with free lanes to its right is not widened
 * to fill them. Google does that and it looks tidier, but it makes a block's
 * width depend on events it does not overlap, so adding an event at 3pm can
 * resize one at 9am — and the widths then stop being readable as "this many
 * things are happening at once", which is the only thing the width is for.
 *
 * **What is placed is a TimeGridEntryInterface, not an occurrence.** The
 * authenticated calendar hands over OccurrenceCluster objects and the public
 * shared page hands over SharedOccurrence objects, and this class cannot tell
 * the difference because the interface gives it nothing to tell them apart by —
 * two instants and "does the time axis apply". That is what lets one grid draw
 * both calendars: the alternative was a second copy of the lane assignment on
 * the sharing side, which would have drifted from this one on the first fix to
 * either, and it is drift between those two grids that the whole shell
 * extraction exists to prevent.
 *
 * A cluster is placed by its primary's span, which is the whole cluster's:
 * members that disagree about when they are have already been split apart by
 * EventClusterer, so there is no second answer to choose between.
 */
final readonly class DayGridLayout
{
    /**
     * The vertical axis, in minutes. A grid column is one calendar day and is
     * drawn as twenty-four equal rows whatever the zone did that day — see the
     * note on wall-clock positions above.
     */
    private const int MINUTES_IN_DAY = 1440;

    /**
     * @param array<string, list<TimeGridEntryInterface>> $days keyed Y-m-d in $zone, as
     *                                                          CalendarRangeReader groups them
     *
     * @return array<string, DayGrid> the same keys, in the same order
     */
    public function place(array $days, DateTimeZone $zone): array
    {
        $placed = [];

        foreach ($days as $dayKey => $entries) {
            $placed[$dayKey] = $this->placeDay($dayKey, $entries, $zone);
        }

        return $placed;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @param list<TimeGridEntryInterface> $entries
     */
    private function placeDay(string $dayKey, array $entries, DateTimeZone $zone): DayGrid
    {
        $dayStart = new DateTimeImmutable($dayKey . ' 00:00', $zone);

        // `+1 day` rather than `+1440 minutes`: on a day that springs forward
        // the next local midnight is twenty-three hours away, and an event
        // starting at 23:30 that evening would otherwise be judged to run past
        // the end of its own day and be clipped to nothing.
        $dayEnd = $dayStart->modify('+1 day');

        $allDay = [];
        $spans  = [];

        foreach ($entries as $entry) {
            if (true === $entry->occupiesWholeDay()) {
                $allDay[] = $entry;

                continue;
            }

            $spans[] = $this->spanOf($entry, $dayStart, $dayEnd, $zone);
        }

        return new DayGrid($allDay, $this->assignLanes($spans));
    }

    /**
     * One entry clipped to one day, as a pair of minute offsets.
     *
     * @return array{entry: TimeGridEntryInterface, from: int, to: int, before: bool, after: bool}
     */
    private function spanOf(
        TimeGridEntryInterface $entry,
        DateTimeImmutable      $dayStart,
        DateTimeImmutable      $dayEnd,
        DateTimeZone           $zone,
    ): array {
        $starts = ($entry->gridStartsAt() ?? $dayStart)->setTimezone($zone);
        $ends   = ($entry->gridEndsAt() ?? $starts)->setTimezone($zone);

        $before = $starts < $dayStart;

        // `>=` on the end, `>` on the flag, and the difference is the bug this
        // line exists for: an event finishing exactly at midnight is not
        // continuing into tomorrow, but minuteOf() reads its end as 00:00 and
        // would give it a height running from its start back up to the top of
        // the column.
        $from = true === $before ? 0 : $this->minuteOf($starts);
        $to   = $ends >= $dayEnd ? self::MINUTES_IN_DAY : $this->minuteOf($ends);

        return [
            'entry' => $entry,
            'from'  => $from,
            // An end before its start is data to survive, not a condition to
            // raise — a negative height would be a block drawn upwards over the
            // ones above it.
            'to'     => max($from, $to),
            'before' => $before,
            'after'  => $ends > $dayEnd,
        ];
    }

    /**
     * Minutes since local midnight, read off the wall clock rather than off a
     * difference of instants. See the class docblock for why that distinction
     * is the whole of DST handling here.
     */
    private function minuteOf(DateTimeImmutable $local): int
    {
        return (int) $local->format('G') * 60 + (int) $local->format('i');
    }

    /**
     * Hand every span a lane, and every span in the same overlapping run the
     * same lane count.
     *
     * A run ends at the first span that starts at or after everything before it
     * has finished. Within a run, a span takes the first lane whose previous
     * occupant has ended — greedy, which is optimal for interval colouring on a
     * list sorted by start, and is why the sort below is not incidental.
     *
     * @param list<array{entry: TimeGridEntryInterface, from: int, to: int, before: bool, after: bool}> $spans
     *
     * @return list<PlacedEntry>
     */
    private function assignLanes(array $spans): array
    {
        // Longest-first among equal starts, so the block that covers the others
        // takes lane 0 and the short ones stack to its right. The reverse puts
        // the long block in the rightmost lane with a column of stubs beside it
        // that look like the main event.
        usort($spans, static fn (array $left, array $right): int => [$left['from'], $right['to']]
            <=> [$right['from'], $left['to']]);

        $placed   = [];
        $run      = [];
        $runEnd   = null;

        foreach ($spans as $span) {
            if (null !== $runEnd && $span['from'] >= $runEnd) {
                $placed = array_merge($placed, $this->layOutRun($run));
                $run    = [];
                $runEnd = null;
            }

            $run[]  = $span;
            $runEnd = null === $runEnd ? $span['to'] : max($runEnd, $span['to']);
        }

        return array_merge($placed, $this->layOutRun($run));
    }

    /**
     * @param list<array{entry: TimeGridEntryInterface, from: int, to: int, before: bool, after: bool}> $run
     *
     * @return list<PlacedEntry>
     */
    private function layOutRun(array $run): array
    {
        if ([] === $run) {
            return [];
        }

        /** @var list<int> $laneEnds where each lane's current occupant finishes */
        $laneEnds = [];
        $lanes    = [];

        foreach ($run as $index => $span) {
            $lane = null;

            foreach ($laneEnds as $candidate => $endsAt) {
                if ($endsAt <= $span['from']) {
                    $lane = $candidate;

                    break;
                }
            }

            if (null === $lane) {
                $lane = count($laneEnds);
            }

            $laneEnds[$lane] = $span['to'];
            $lanes[$index]   = $lane;
        }

        $width  = count($laneEnds);
        $placed = [];

        foreach ($run as $index => $span) {
            $placed[] = new PlacedEntry(
                entry:           $span['entry'],
                top:             $span['from'] / self::MINUTES_IN_DAY,
                height:          ($span['to'] - $span['from']) / self::MINUTES_IN_DAY,
                lane:            $lanes[$index],
                lanes:           $width,
                continuesBefore: $span['before'],
                continuesAfter:  $span['after'],
            );
        }

        return $placed;
    }
}
