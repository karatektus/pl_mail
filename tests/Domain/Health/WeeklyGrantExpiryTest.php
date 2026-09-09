<?php

declare(strict_types=1);

namespace App\Tests\Domain\Health;

use App\Domain\Health\WeeklyGrantExpiry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Telling Google's seven-day Testing limit from every other dead sign-in.
 *
 * The provider gives no help at all: `invalid_grant` is what it says for a
 * revoked consent, a changed password, and a token that aged out of an app
 * still in Testing publishing status. Only the last repeats every week, and
 * only the last is fixed by changing a setting instead of signing in again
 * forever — so the interval is the entire evidence, and getting the window
 * wrong means telling somebody the wrong thing about their own Google project.
 *
 * Both directions are pinned. Too narrow and a real weekly expiry noticed a day
 * late says nothing; too wide and an account that ran for three months before
 * its owner revoked access gets told to go and publish an app, which is advice
 * about a cause that was not there.
 */
final class WeeklyGrantExpiryTest extends TestCase
{
    /**
     * @param bool $expected whether a grant of this many hours reads as weekly
     */
    #[DataProvider('lifetimes')]
    public function testRecognisesTheWeeklyShape(bool $expected, ?int $hours, string $because): void
    {
        self::assertSame($expected, WeeklyGrantExpiry::looksWeekly($hours), $because);
    }

    /**
     * @return iterable<string, array{bool, int|null, string}>
     */
    public static function lifetimes(): iterable
    {
        yield 'exactly seven days' => [true, 168, 'the documented limit itself'];

        // The limit is seven days; the moment it is NOTICED is whenever the
        // account next syncs, which is not the moment it died.
        yield 'noticed a day late'  => [true, 192, 'a weekly expiry seen on the next sync still is one'];
        yield 'noticed two days late' => [true, 216, 'still within the slack the window allows for'];
        yield 'five days'          => [true, 120, 'short of seven, and nothing else dies on that schedule'];

        // The cases that must NOT be claimed.
        yield 'a long-lived grant somebody revoked' => [
            false, 24 * 90, 'three months then revoked is not the Testing limit and must not say it is',
        ];
        yield 'a fortnight' => [false, 336, 'twice the limit is a different cause'];
        yield 'died the same day' => [
            false, 6, 'something else went wrong — a bad clock, a botched connect',
        ];
        yield 'nothing recorded' => [
            false, null, 'an account connected before this was measured has no history to read',
        ];
    }

    /**
     * The warning needs BOTH halves: evidence, and imminence.
     *
     * Without the history it would fire on every healthy Google account every
     * week. Without the age it would fire the moment somebody reconnected, and
     * a warning visible five days out of seven is furniture.
     */
    public function testWarnsOnlyWhenAWeeklyHistoryMeetsAnAgeingGrant(): void
    {
        $now = new DateTimeImmutable('2026-09-09 08:00:00');

        $sixDaysAgo = $now->modify('-6 days');
        $anHourAgo  = $now->modify('-1 hour');

        self::assertTrue(
            WeeklyGrantExpiry::isDueToExpire($sixDaysAgo, 168, $now),
            'a six-day-old grant on an account that already lost one at a week is about to go',
        );

        self::assertFalse(
            WeeklyGrantExpiry::isDueToExpire($anHourAgo, 168, $now),
            'just reconnected — true eventually, and saying it now makes the card furniture',
        );

        self::assertFalse(
            WeeklyGrantExpiry::isDueToExpire($sixDaysAgo, 24 * 90, $now),
            'the previous grant lasted months, so there is no weekly pattern to predict from',
        );

        self::assertFalse(
            WeeklyGrantExpiry::isDueToExpire($sixDaysAgo, null, $now),
            'no history at all is no evidence, and every healthy account would be warned weekly',
        );

        self::assertFalse(
            WeeklyGrantExpiry::isDueToExpire(null, 168, $now),
            'an account connected before the stamp existed cannot have its age inferred',
        );
    }

    /** A null in, a null out — never a zero, which would read as "just now". */
    public function testAgeIsUnknownRatherThanZeroWhenNothingWasRecorded(): void
    {
        self::assertNull(WeeklyGrantExpiry::ageInHours(null, new DateTimeImmutable()));

        $now = new DateTimeImmutable('2026-09-09 08:00:00');

        self::assertSame(48, WeeklyGrantExpiry::ageInHours($now->modify('-2 days'), $now));
    }
}
