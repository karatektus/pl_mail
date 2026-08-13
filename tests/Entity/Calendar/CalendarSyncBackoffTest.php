<?php

declare(strict_types=1);

namespace App\Tests\Entity\Calendar;

use App\Entity\Calendar\Calendar;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The backoff that stops a permanently failing calendar from re-logging the
 * same line every fifteen minutes forever.
 *
 * WHAT THIS IS FOR
 * ────────────────
 * On the install this was written from, one expired Google sign-in produced
 * 2 193 identical `CalendarSync: sync failed` lines and 503 dead-lettered jobs
 * across three calendars in two days. The mechanism was not subtle: the sweep
 * asks for calendars whose lastSyncedAt is old, a calendar that cannot sync
 * never updates lastSyncedAt, so it is due again on every single sweep.
 *
 * The tests below pin the three properties that make the fix a backoff rather
 * than a mute button, because a mute button would have passed a naive test
 * suite just as well:
 *
 *   - the FIRST failure always reports (testTheFirstFailureIsAlwaysReported)
 *   - a CHANGED failure reports immediately, whatever the window says
 *     (testADifferentFailureIsReportedEvenInsideTheWindow)
 *   - the window is bounded, so a condition that silently heals is retried
 *     within a day rather than in a fortnight (testTheDelayIsCappedAtOneDay)
 *
 * Times are injected throughout. A test that slept would be a test nobody runs.
 */
final class CalendarSyncBackoffTest extends TestCase
{
    private const string REASON = 'Google would not renew the sign-in for this account.';

    public function testAHealthyCalendarIsNotBackingOff(): void
    {
        $calendar = new Calendar();

        self::assertFalse($calendar->isBackingOff());
        self::assertNull($calendar->syncBackoffUntil);
        self::assertSame(0, $calendar->syncFailureCount);
    }

    /**
     * The rule the whole feature would be worthless without: suppressing the
     * first occurrence would mean a calendar could break and say nothing.
     */
    public function testTheFirstFailureIsAlwaysReported(): void
    {
        $calendar = new Calendar();
        $now      = new DateTimeImmutable('2026-08-13 09:00:00');

        self::assertTrue(
            $calendar->recordSyncFailure(self::REASON, $now),
            'the first failure a calendar ever has is news',
        );

        self::assertSame(self::REASON, $calendar->lastSyncError);
        self::assertSame(1, $calendar->syncFailureCount);
        self::assertTrue($calendar->isBackingOff($now));
    }

    /**
     * The 2 193 lines, prevented. Same calendar, same error, still inside the
     * window it already agreed to wait out — nothing further is logged.
     */
    public function testRepeatingTheSameFailureInsideTheWindowIsNotReported(): void
    {
        $calendar = new Calendar();
        $start    = new DateTimeImmutable('2026-08-13 09:00:00');

        self::assertTrue($calendar->recordSyncFailure(self::REASON, $start));

        // The messenger retries of the very same envelope, moments later.
        self::assertFalse($calendar->recordSyncFailure(self::REASON, $start->modify('+2 seconds')));
        self::assertFalse($calendar->recordSyncFailure(self::REASON, $start->modify('+10 seconds')));

        // And the sweeps that would have come round in the meantime.
        self::assertFalse($calendar->recordSyncFailure(self::REASON, $start->modify('+5 minutes')));
        self::assertFalse($calendar->recordSyncFailure(self::REASON, $start->modify('+14 minutes')));

        // The schedule did NOT advance on the silent ones. Otherwise a single
        // afternoon of retries would push the next attempt out by a day.
        self::assertSame(1, $calendar->syncFailureCount, 'silent repeats do not advance the schedule');
    }

    /**
     * The window expires and the calendar reports again — quieter, not muted.
     * This is what keeps the condition visible in the log over a long outage.
     */
    public function testTheFailureIsReportedAgainOnceTheWindowExpires(): void
    {
        $calendar = new Calendar();
        $start    = new DateTimeImmutable('2026-08-13 09:00:00');

        $calendar->recordSyncFailure(self::REASON, $start);

        // First window is a quarter of an hour.
        self::assertFalse($calendar->recordSyncFailure(self::REASON, $start->modify('+14 minutes')));
        self::assertTrue(
            $calendar->recordSyncFailure(self::REASON, $start->modify('+16 minutes')),
            'once the window is out, the condition says so again',
        );

        self::assertSame(2, $calendar->syncFailureCount);
    }

    /**
     * The case a plain "log at most once an hour" throttle would have hidden,
     * and the one that matters most: the calendar was failing on a dead
     * sign-in, and is now failing on something else entirely.
     */
    public function testADifferentFailureIsReportedEvenInsideTheWindow(): void
    {
        $calendar = new Calendar();
        $start    = new DateTimeImmutable('2026-08-13 09:00:00');

        $calendar->recordSyncFailure(self::REASON, $start);

        self::assertTrue(
            $calendar->recordSyncFailure('The calendar no longer exists at the remote.', $start->modify('+1 minute')),
            'a different failure is news whatever the window says',
        );

        self::assertSame('The calendar no longer exists at the remote.', $calendar->lastSyncError);
    }

    /**
     * Doubling, so a calendar that is genuinely dead costs less and less.
     */
    public function testTheDelayGrowsWithConsecutiveFailures(): void
    {
        $calendar = new Calendar();
        $at       = new DateTimeImmutable('2026-08-13 09:00:00');

        $windows = [];

        for ($i = 0; $i < 5; ++$i) {
            self::assertTrue($calendar->recordSyncFailure(self::REASON, $at));

            $until     = $calendar->syncBackoffUntil;
            $windows[] = $until->getTimestamp() - $at->getTimestamp();

            // Jump to the moment the window opens again.
            $at = $until->modify('+1 second');
        }

        self::assertSame([900, 1800, 3600, 7200, 14400], $windows);
    }

    /**
     * The cap is the part that stops backoff becoming abandonment. Without it a
     * calendar that failed for a fortnight would next be tried a fortnight
     * later, so a sign-in repaired this morning would sit dark for weeks.
     */
    public function testTheDelayIsCappedAtOneDay(): void
    {
        $calendar = new Calendar();
        $at       = new DateTimeImmutable('2026-08-13 09:00:00');

        for ($i = 0; $i < 20; ++$i) {
            $calendar->recordSyncFailure(self::REASON, $at);
            $at = $calendar->syncBackoffUntil->modify('+1 second');
        }

        $last = $calendar->recordSyncFailure(self::REASON, $at);

        self::assertTrue($last);
        self::assertSame(
            86400,
            $calendar->syncBackoffUntil->getTimestamp() - $at->getTimestamp(),
            'however long it has been broken, it is still tried once a day',
        );
    }

    /**
     * One success is enough to earn the full rate back. A calendar that
     * recovered but stayed on an hourly schedule would look stale with nothing
     * on screen to explain it.
     */
    public function testASuccessClearsTheBackoffCompletely(): void
    {
        $calendar = new Calendar();
        $at       = new DateTimeImmutable('2026-08-13 09:00:00');

        for ($i = 0; $i < 4; ++$i) {
            $calendar->recordSyncFailure(self::REASON, $at);
            $at = $calendar->syncBackoffUntil->modify('+1 second');
        }

        self::assertGreaterThan(1, $calendar->syncFailureCount);

        $calendar->recordSyncSuccess();

        self::assertNull($calendar->lastSyncError);
        self::assertNull($calendar->syncBackoffUntil);
        self::assertSame(0, $calendar->syncFailureCount);
        self::assertFalse($calendar->isBackingOff());

        // ...and the next failure after a recovery is news all over again.
        self::assertTrue($calendar->recordSyncFailure(self::REASON, $at));
    }

    /**
     * The repair button's half: clear the schedule, but do NOT pretend the
     * calendar is healthy. lastSyncError is still true until a sync succeeds,
     * and blanking it here would empty the health page the instant the button
     * was pressed whether or not anything got better.
     */
    public function testClearingTheBackoffKeepsTheStoredError(): void
    {
        $calendar = new Calendar();
        $at       = new DateTimeImmutable('2026-08-13 09:00:00');

        $calendar->recordSyncFailure(self::REASON, $at);
        $calendar->clearSyncBackoff();

        self::assertFalse($calendar->isBackingOff($at));
        self::assertSame(0, $calendar->syncFailureCount);
        self::assertSame(self::REASON, $calendar->lastSyncError, 'still broken until a sync says otherwise');
    }

    /**
     * The flag SyncCalendarHandler reads to decide whether to log, pinned
     * directly — the handler is thin enough that this IS the behaviour.
     */
    public function testTheNewsFlagTracksTheReturnValue(): void
    {
        $calendar = new Calendar();
        $at       = new DateTimeImmutable('2026-08-13 09:00:00');

        $calendar->recordSyncFailure(self::REASON, $at);
        self::assertTrue($calendar->syncFailureWasNews);

        $calendar->recordSyncFailure(self::REASON, $at->modify('+1 minute'));
        self::assertFalse($calendar->syncFailureWasNews);
    }
}
