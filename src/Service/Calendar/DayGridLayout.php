<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Domain\DTO\Calendar\Band;
use App\Domain\DTO\Calendar\BandEntry;
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
 *
 * **A timed entry of a day or more is not a block.** It goes to the all-day
 * band as one bar across the days it covers, and on each of those days the hours
 * it takes are shaded rather than drawn — see band() and DayGrid::$shaded. Drawn
 * as a block per day, a Friday-evening-to-Sunday trip was three blocks that each
 * lifted on their own when pointed at, two of them titled at a midnight nobody
 * had scrolled to, and a column-high block that pushed every other meeting of
 * the weekend into half a lane.
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
     * How long a timed entry runs before it leaves the hours for the band: a
     * day, measured as elapsed time.
     *
     * The line Google and Outlook draw, and for the reason they draw it: under a
     * day, an event that crosses midnight is an evening that ran late — a
     * concert, a night shift — and reads right as a block down to midnight and
     * another from it, lit together when pointed at. A day or more is a stretch
     * of the calendar rather than a slot in it, and its place is the band.
     *
     * Elapsed rather than wall-clock, unlike the axis, because this is a
     * question about duration: a 24-hour event across a DST change is 23 or 25
     * hours on the labels and still a day long.
     */
    private const int BAND_SECONDS = 86_400;

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

    /**
     * The all-day band over the same days place() lays out: every entry that is
     * not on the hours — all-day ones, and timed ones of a day or more — once,
     * across the days it covers, in the first row where those days are free.
     *
     * An entry is recognised across days by identity, because that is how the
     * readers hand it over: CalendarRangeReader and SharedCalendarRangeBuilder
     * both put the same object on every day it touches. Comparing titles or
     * times instead would merge two events that merely look alike.
     *
     * Lanes are assigned the way the hours assign them: earliest first, the
     * longest first among equal starts so it takes the top row and the short
     * ones stack under it, each into the first row free from its first day.
     *
     * @param array<string, list<TimeGridEntryInterface>> $days keyed Y-m-d in $zone, in column order
     */
    public function band(array $days, DateTimeZone $zone): Band
    {
        $dayKeys = array_keys($days);

        if ([] === $dayKeys) {
            return Band::empty();
        }

        $columnOf = array_flip($dayKeys);

        /** @var array<int, array{entry: TimeGridEntryInterface, first: int, last: int, order: int}> $found */
        $found = [];

        foreach ($days as $dayKey => $entries) {
            foreach ($entries as $entry) {
                if (false === $this->belongsInBand($entry)) {
                    continue;
                }

                $id     = spl_object_id($entry);
                $column = $columnOf[$dayKey];

                if (true === isset($found[$id])) {
                    $found[$id]['last'] = max($found[$id]['last'], $column);

                    continue;
                }

                $found[$id] = ['entry' => $entry, 'first' => $column, 'last' => $column, 'order' => count($found)];
            }
        }

        $items = array_values($found);

        usort($items, static fn (array $left, array $right): int => [$left['first'], $right['last'], $left['order']]
            <=> [$right['first'], $left['last'], $right['order']]);

        $lastColumn = count($dayKeys) - 1;

        /** @var list<int> $laneEnds the last column each row is taken up to */
        $laneEnds = [];
        $entries  = [];

        foreach ($items as $item) {
            $lane = null;

            foreach ($laneEnds as $candidate => $takenUntil) {
                if ($takenUntil < $item['first']) {
                    $lane = $candidate;

                    break;
                }
            }

            $lane ??= count($laneEnds);
            $laneEnds[$lane] = $item['last'];

            $entries[] = new BandEntry(
                entry:           $item['entry'],
                firstDay:        $dayKeys[$item['first']],
                days:            $item['last'] - $item['first'] + 1,
                lane:            $lane,
                timed:           false === $item['entry']->occupiesWholeDay(),
                // Only a bar that reaches an edge can have been cut there: the
                // readers put an entry on every day it touches, so one that
                // starts in a later column started on that day.
                continuesBefore: 0 === $item['first']
                    && $this->startsBefore($item['entry'], $dayKeys[0], $zone),
                continuesAfter:  $lastColumn === $item['last']
                    && $this->endsAfter($item['entry'], $dayKeys[$lastColumn], $zone),
                key:             self::keyOf($item['entry']),
            );
        }

        return new Band($entries, count($laneEnds));
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
        $shaded = [];

        foreach ($entries as $entry) {
            if (true === $entry->occupiesWholeDay()) {
                $allDay[] = $entry;

                continue;
            }

            $span = $this->spanOf($entry, $dayStart, $dayEnd, $zone);

            if (true === $this->runsADayOrMore($entry)) {
                $shaded[] = new PlacedEntry(
                    entry:           $entry,
                    top:             $span['from'] / self::MINUTES_IN_DAY,
                    height:          ($span['to'] - $span['from']) / self::MINUTES_IN_DAY,
                    lane:            0,
                    lanes:           1,
                    continuesBefore: $span['before'],
                    continuesAfter:  $span['after'],
                    key:             self::keyOf($entry),
                );

                continue;
            }

            $spans[] = $span;
        }

        return new DayGrid($allDay, $this->assignLanes($spans), $shaded);
    }

    /**
     * Whether an entry is drawn in the band rather than on the hours.
     */
    private function belongsInBand(TimeGridEntryInterface $entry): bool
    {
        return true === $entry->occupiesWholeDay() || true === $this->runsADayOrMore($entry);
    }

    /**
     * A timed entry at least BAND_SECONDS long. An entry missing either end is
     * a data fault the grid survives by drawing it as a block, which is where
     * spanOf() already knows how to put it.
     */
    private function runsADayOrMore(TimeGridEntryInterface $entry): bool
    {
        if (true === $entry->occupiesWholeDay()) {
            return false;
        }

        $starts = $entry->gridStartsAt();
        $ends   = $entry->gridEndsAt();

        if (null === $starts || null === $ends) {
            return false;
        }

        return $ends->getTimestamp() - $starts->getTimestamp() >= self::BAND_SECONDS;
    }

    /**
     * Whether an entry began before a day's first minute.
     *
     * An all-day entry is compared by its wall date and never converted: its
     * midnights are FLOATING, and reading one as an instant in $zone moves it
     * onto the day before for everyone west of UTC. See CalendarRangeReader.
     */
    private function startsBefore(TimeGridEntryInterface $entry, string $dayKey, DateTimeZone $zone): bool
    {
        $starts = $entry->gridStartsAt();

        if (null === $starts) {
            return false;
        }

        if (true === $entry->occupiesWholeDay()) {
            return $starts->format('Y-m-d') < $dayKey;
        }

        return $starts->setTimezone($zone) < new DateTimeImmutable($dayKey . ' 00:00', $zone);
    }

    /**
     * Whether an entry runs past a day's last minute. An all-day entry's end is
     * the morning after its last day, as iCalendar writes it, so it runs past
     * $dayKey only when it ends after the next one.
     */
    private function endsAfter(TimeGridEntryInterface $entry, string $dayKey, DateTimeZone $zone): bool
    {
        $ends = $entry->gridEndsAt();

        if (null === $ends) {
            return false;
        }

        $nextDay = new DateTimeImmutable($dayKey . ' 00:00', $zone)->modify('+1 day');

        if (true === $entry->occupiesWholeDay()) {
            return $ends->format('Y-m-d') > $nextDay->format('Y-m-d');
        }

        return $ends->setTimezone($zone) > $nextDay;
    }

    /**
     * The name the hover links an entry's pieces by. Object identity, for the
     * reason band() recognises an entry by it.
     */
    private static function keyOf(TimeGridEntryInterface $entry): string
    {
        return 'entry-' . spl_object_id($entry);
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
                key:             self::keyOf($span['entry']),
            );
        }

        return $placed;
    }
}
