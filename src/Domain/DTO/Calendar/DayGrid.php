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
 */
final readonly class DayGrid
{
    /**
     * @param list<TimeGridEntryInterface> $allDay
     * @param list<PlacedEntry>            $timed
     */
    public function __construct(
        public array $allDay,
        public array $timed,
    ) {
    }
}
