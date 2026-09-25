<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

use App\Domain\Interface\TimeGridEntryInterface;

/**
 * One day of a time-grid, split into the two things a time-grid draws.
 *
 * The split is the reason this type exists rather than a bare list. An all-day
 * event has no time to be positioned at: it would land on the midnight line as
 * a block of zero height, which is both invisible and a lie — "all day" is a
 * statement that the time axis does not apply. So they are lifted out here,
 * once, and drawn in a band above the hours where they can be read.
 *
 * $allDay keeps the entries unplaced, because the band is a flow row and has
 * no vertical axis to place them on. $timed carries placements, because the
 * grid does.
 *
 * Both hold TimeGridEntryInterface rather than one concrete type, so the same
 * grid serves the owner's calendar and the public shared page — see PlacedEntry
 * for what that widening bought and cost.
 *
 * Both are lists in the order the reader produced them, which is by start and
 * then by id — so two renders of the same data put the same chip in the same
 * place, and a keyboard walking the blocks walks them down the day.
 *
 * $shaded is the third thing, and it is neither of the two: a timed entry that
 * runs a day or more. It is drawn once, as a bar in the band (see
 * DayGridLayout::band()), and the hours it takes on this day are shaded under
 * the day's own blocks rather than drawn as a block of their own. A block per
 * day made a weekend away into three events, each lifting on its own when
 * pointed at, and a column-high block pushed every other meeting that day into
 * half a lane. Shading has no lane and takes no width. Placed like a block —
 * top, height and the two clip flags — with lane 0 of 1, because it spans the
 * column.
 */
final readonly class DayGrid
{
    /**
     * @param list<TimeGridEntryInterface> $allDay
     * @param list<PlacedEntry>            $timed
     * @param list<PlacedEntry>            $shaded
     */
    public function __construct(
        public array $allDay,
        public array $timed,
        public array $shaded = [],
    ) {
    }
}
