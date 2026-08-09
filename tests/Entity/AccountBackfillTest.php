<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Mail\Account;
use PHPUnit\Framework\TestCase;

/**
 * The rule that decides whether a Gmail sync run walks back through the
 * mailbox again.
 *
 * The bug this originally pinned down: whether a backfill had run was inferred
 * from the stored historyId, which is written before any message is fetched, so
 * an account was stuck on whatever the first run happened to pull — an
 * interrupted initial sync looked exactly like a finished one. The answer is
 * still a recorded target rather than an inference, which is what these guard.
 *
 * Since the newest-N sync cap was removed the target has only one settled
 * value, 0 for "the whole mailbox". A positive one can only be an account that
 * was capped before the removal, and it has to keep reading as unfinished —
 * that is the whole of the upgrade path for those accounts, so it gets a test
 * of its own rather than being left to the migration.
 */
final class AccountBackfillTest extends TestCase
{
    public function testNeedsBackfillWhenNoneHasEverCompleted(): void
    {
        self::assertTrue((new Account())->needsBackfill());
    }

    public function testACompletedBackfillIsNeverRepeated(): void
    {
        $account = new Account();
        $account->backfillTarget = 0;

        self::assertFalse($account->needsBackfill());
    }

    public function testAnAccountLeftCappedByTheOldSyncLimitStillOwesItsOlderMail(): void
    {
        // The upgrade case: a run that settled at 500 because the cap said so.
        // The cap is gone, the mail below 500 is not, and nothing else in the
        // system remembers that this account was ever short.
        $account = new Account();
        $account->backfillTarget = 500;

        self::assertTrue($account->needsBackfill());
    }

    public function testBackfillRanAtSurvivesTheSettingsBagRoundTrip(): void
    {
        $ranAt   = new \DateTimeImmutable('2026-07-28 12:34:56');
        $account = new Account();
        $account->backfillRanAt = $ranAt;

        self::assertSame($ranAt->getTimestamp(), $account->backfillRanAt?->getTimestamp());
    }

    public function testBackfillStateIsUnsetOnAFreshAccount(): void
    {
        $account = new Account();

        self::assertNull($account->backfillTarget);
        self::assertNull($account->backfillRanAt);
    }
}
