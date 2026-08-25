<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\DefaultEventStart;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The time a new event opens at, including the hour when it went wrong.
 *
 * "New event on Thursday" defaults to Thursday at the next full hour, because
 * midnight is not what anybody means by it. Between 23:00 and midnight the next
 * full hour is 00:00 TOMORROW, and carrying its hour-of-day onto the named day
 * produced that day at 00:00 — the whole day in the past, on the one field a
 * user was most likely to accept unchanged.
 *
 * Once a day, for an hour, on every install. It was found by an E2E test that
 * happened to run at 23:10, which is the only hour it could ever have found it
 * in — hence a pure function with a clock passed in.
 */
final class DefaultEventStartTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function days(): iterable
    {
        yield 'mid-afternoon, today' => ['2026-08-25 14:10', '2026-08-25', '2026-08-25 15:00'];
        yield 'mid-afternoon, a future day' => ['2026-08-25 14:10', '2026-08-27', '2026-08-27 15:00'];

        // The bug. The next full hour is 2026-08-26 00:00, and pinning hour 0
        // to the requested day gave 2026-08-25 00:00.
        yield 'the last hour of the day, today' => ['2026-08-25 23:10', '2026-08-25', '2026-08-26 00:00'];

        // A future day is unaffected: its midnight is still ahead.
        yield 'the last hour of the day, a future day' => ['2026-08-25 23:10', '2026-08-27', '2026-08-27 00:00'];

        // Exactly on the hour still moves forward — the next full hour, not
        // this one, or the default would be a meeting starting now.
        yield 'exactly on the hour' => ['2026-08-25 15:00', '2026-08-25', '2026-08-25 16:00'];

        // A day already past is a deliberate choice by the user; the clamp
        // pulls it to the next real hour rather than offering a dead time.
        yield 'a day that has gone' => ['2026-08-25 14:10', '2026-08-20', '2026-08-25 15:00'];
    }

    #[DataProvider('days')]
    public function testTheDefaultIsNeverBehindTheClock(string $now, string $day, string $expected): void
    {
        $start = DefaultEventStart::onDay(
            new DateTimeImmutable($day . ' 00:00'),
            new DateTimeImmutable($now),
        );

        self::assertSame($expected, $start->format('Y-m-d H:i'));
    }

    /**
     * And whatever it answers, it is never in the past — the property the
     * cases above are examples of.
     */
    #[DataProvider('days')]
    public function testTheAnswerIsAlwaysInTheFuture(string $now, string $day, string $expected): void
    {
        $clock = new DateTimeImmutable($now);
        $start = DefaultEventStart::onDay(new DateTimeImmutable($day . ' 00:00'), $clock);

        self::assertGreaterThan($clock, $start, sprintf('%s on %s is a time that has gone', $expected, $day));
    }

    public function testTheNextFullHourDropsMinutesAndSeconds(): void
    {
        $hour = DefaultEventStart::nextFullHour(new DateTimeImmutable('2026-08-25 09:41:33'));

        self::assertSame('2026-08-25 10:00:00', $hour->format('Y-m-d H:i:s'));
    }
}
