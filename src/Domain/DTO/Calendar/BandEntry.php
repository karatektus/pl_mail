<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

use App\Domain\Interface\TimeGridEntryInterface;

/**
 * One entry in a time-grid's all-day band, drawn once across the days it covers.
 *
 * The band used to be a column of chips per day, so a three-day offsite was
 * three chips that each said "Offsite", and a weekend away that started on a
 * Friday evening was three blocks down three columns of the hours, each lifting
 * on its own when pointed at. One bar is how every other calendar draws both,
 * and it is what makes "this is one thing" true on screen. DayGridLayout::band()
 * decides what goes here and in which lane.
 *
 * **$firstDay is a day key, not a column index.** The caller that draws the band
 * may draw more columns than the entries were laid out over — the shared page
 * dims the days its link does not cover and publishes nothing on them — so only
 * the drawing side knows where a day sits. $days counts the days from there.
 *
 * **$timed says it is on the clock.** An entry that runs a day or more leaves the
 * hours for this band, and the hours it takes are shaded in the grid instead of
 * drawn as a block per day; the bar carries its start and end so the shading can
 * stay wordless. An all-day entry has no times to print, which is the difference.
 *
 * $continuesBefore and $continuesAfter say the bar was cut at the edge of what is
 * drawn: it started before the first column or runs past the last. They square
 * that end, the way PlacedEntry's pair marks a block clipped at midnight.
 *
 * $key names the entry for the grid's hover. The bar and every stretch of hours
 * shaded for it carry the same one, so pointing at any of them lights them all.
 * It is only stable within one render, which is all it is for.
 */
final readonly class BandEntry
{
    /**
     * @param string $firstDay Y-m-d, the first drawn day the entry covers
     * @param int    $days     how many days from $firstDay, at least one
     * @param int    $lane     0-based row of the band
     */
    public function __construct(
        public TimeGridEntryInterface $entry,
        public string                 $firstDay,
        public int                    $days,
        public int                    $lane,
        public bool                   $timed,
        public bool                   $continuesBefore,
        public bool                   $continuesAfter,
        public string                 $key,
    ) {
    }
}
