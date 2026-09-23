<?php

declare(strict_types=1);

namespace App\Jmap\Query;

/**
 * The windowed id list plus the totals Email/query reports back. total is
 * null when the client declined it (calculateTotal: false).
 */
final class EmailQueryResult
{
    /**
     * @param list<string> $ids
     */
    public function __construct(
        public readonly array $ids,
        public readonly ?int $total,
        public readonly int $position,
    ) {
    }
}
