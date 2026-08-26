<?php

declare(strict_types=1);

namespace App\Domain\Enum\Ai;

use DateTimeImmutable;

/**
 * How far back the panel's aggregates look.
 *
 * Three choices rather than a free-form number, because they are three
 * different questions and not three points on a slider. The hour answers "is
 * it slow right now"; the day answers "what does this box normally do"; the
 * week answers "has it got worse since we changed something". Anything finer
 * would be noise on a table that fills a handful of rows an hour on most
 * installations.
 *
 * A closed set is also what keeps the window out of the SQL: the query takes a
 * timestamp, and the only timestamps it can be given come from here.
 */
enum MetricWindow: string
{
    case Hour = 'hour';
    case Day  = 'day';
    case Week = 'week';

    public function since(DateTimeImmutable $now): DateTimeImmutable
    {
        return match ($this) {
            self::Hour => $now->modify('-1 hour'),
            self::Day  => $now->modify('-24 hours'),
            self::Week => $now->modify('-7 days'),
        };
    }

    /**
     * What a query string asked for, or the day.
     *
     * The day is the default because it is the one that describes the machine:
     * an hour on an installation nobody has used since breakfast is an empty
     * table, and an empty table reads as a broken panel rather than a quiet
     * morning.
     */
    public static function fromRequest(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Day;
    }
}
