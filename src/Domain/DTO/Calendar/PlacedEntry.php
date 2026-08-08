<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

use App\Domain\Interface\TimeGridEntryInterface;

/**
 * Where one chip sits on one day of a time-grid, as fractions of that day.
 *
 * Fractions rather than pixels, and that is the whole point of the type. The
 * grid's hour height is a CSS decision that changes with the window, the theme
 * and whatever a future zoom control does; a layout expressed in pixels would
 * have to be recomputed in the browser every time one of those moved, and the
 * server would be stating something it cannot know. `top` and `height` are
 * percentages of a 24-hour column, so the same numbers are correct at every
 * height the column is ever given.
 *
 * $lane and $lanes are the answer to overlapping. Everything that overlaps
 * anything else in the same run of a day shares one $lanes count, so the
 * columns line up down the whole run rather than each pair of events choosing
 * its own width — a block two lanes wide beside a block three lanes wide reads
 * as a rendering fault. DayGridLayout owns how the lanes are assigned.
 *
 * $continuesBefore and $continuesAfter are how a multi-day or midnight-crossing
 * event says it was clipped. The block itself is clamped to the day, because a
 * column is 24 hours and there is nowhere else to draw it — so without these
 * two an event running 23:00 to 01:00 would be indistinguishable from one that
 * genuinely stops at midnight, on both of the days it touches.
 *
 * **$entry is an interface, and it was a cluster.** It was widened when the
 * public shared calendar grew week and day views: those draw SharedOccurrence
 * objects, which are redactions with no event behind them, and the alternative
 * to widening was a second lane-assignment written beside this one that would
 * have drifted from it on the first fix to either. What is placed is now
 * whatever can answer TimeGridEntryInterface's three questions, and the caller
 * that handed the entries over is the one that knows what to draw them as.
 *
 * Deliberately not carrying the day it belongs to: the placements are handed
 * over keyed by day (see DayGrid), and repeating the key inside the value is a
 * second place for it to be wrong.
 */
final readonly class PlacedEntry
{
    /**
     * @param float $top    0.0 at local midnight, 1.0 at the next one
     * @param float $height a fraction of the same 24 hours; 0.0 for a zero-length event
     * @param int   $lane   0-based, which lane of $lanes this one is drawn in
     * @param int   $lanes  how many lanes the overlapping run this belongs to needed
     */
    public function __construct(
        public TimeGridEntryInterface $entry,
        public float                  $top,
        public float                  $height,
        public int                    $lane,
        public int                    $lanes,
        public bool                   $continuesBefore,
        public bool                   $continuesAfter,
    ) {
    }
}
