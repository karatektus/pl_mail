<?php

declare(strict_types=1);

namespace App\Jmap\Query;

/**
 * One page of CalendarEvent/query: the ids in sort order, where the page
 * starts, and how many matched in total.
 *
 * A DTO rather than a three-element array for the reason every DTO here exists:
 * `position` and `total` are both ints and both plausible in either slot, and
 * a caller that swapped them would page a client through a list whose length it
 * had misreported.
 *
 * @see CalendarEventQueryRunner
 */
final readonly class CalendarEventQueryResult
{
    /**
     * @param list<string> $ids
     */
    public function __construct(
        public int   $position,
        public array $ids,
        public int   $total,
    ) {
    }
}
