<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

/**
 * A time-grid's all-day band: its bars, and how many rows they need.
 *
 * $lanes is carried rather than left to the template to count, because the
 * band's day columns are drawn as a background spanning every row, and a grid
 * row line past the last explicit one does not exist to span to. Zero for a
 * band with nothing in it, which still draws as one empty row.
 */
final readonly class Band
{
    /**
     * @param list<BandEntry> $entries in lane order: earliest first, longest first among equals
     */
    public function __construct(
        public array $entries,
        public int   $lanes,
    ) {
    }

    public static function empty(): self
    {
        return new self([], 0);
    }
}
