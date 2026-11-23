<?php

declare(strict_types=1);

namespace App\Service\Maintenance\Backfill;

use DateTimeImmutable;
use InvalidArgumentException;
use Symfony\Component\Clock\Clock;

/**
 * How far back a backfill that re-reads mail goes: "36h", "7d", "4w", a date,
 * or "all".
 *
 * One reading for both doors. `app:backfill --since` and the window on Admin →
 * Backfills hand the same strings to the same parser, so "7d" cannot mean one
 * thing at the console and another on the page. It lived inside BackfillCommand
 * until the page needed it too.
 *
 * See WindowedBackfillTaskInterface for why these tasks take a window at all,
 * and why the default is a day.
 */
final readonly class BackfillWindow
{
    /** How far back a task that re-reads mail goes when nobody says. */
    public const string DEFAULT = '24h';

    /** The value that lifts the limit. */
    public const string ALL = 'all';

    /**
     * The choices Admin → Backfills offers, in order. The page posts one of
     * these and nothing else; a date stays a console affair, where the
     * operator who wants one is already typing.
     *
     * @var list<string>
     */
    public const array PRESETS = [self::DEFAULT, '7d', '4w', self::ALL];

    /**
     * The moment a value names, or null for "all".
     *
     * A small vocabulary on purpose — a count and h, d or w, or a date. What
     * strtotime() would additionally accept ("last tuesday", "-1 fortnight")
     * is also what it silently misreads, and this decides how much of a
     * mailbox gets rewritten.
     *
     * @throws InvalidArgumentException when the value is none of those
     */
    public function since(string $value): ?DateTimeImmutable
    {
        $value = mb_strtolower(trim($value));

        if (self::ALL === $value) {
            return null;
        }

        $now = Clock::get()->now();

        if (1 === preg_match('~^(\d+)\s*([hdw])$~', $value, $m) && (int) $m[1] > 0) {
            $unit = ['h' => 'hours', 'd' => 'days', 'w' => 'weeks'][$m[2]];

            return $now->modify(sprintf('-%d %s', (int) $m[1], $unit));
        }

        if (1 === preg_match('~^\d{4}-\d{2}-\d{2}$~', $value)) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $now->getTimezone());

            // createFromFormat rolls 2026-02-31 over into March rather than
            // refusing it; the round trip is what catches that.
            if (false !== $date && $date->format('Y-m-d') === $value) {
                return $date;
            }
        }

        throw new InvalidArgumentException(sprintf(
            '--since=%s is not understood. Use a count with h, d or w (36h, 7d, 4w), a date (2026-09-01), or "all".',
            $value,
        ));
    }
}
