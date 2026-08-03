<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Mail\Account;
use PHPUnit\Framework\TestCase;

/**
 * The rule that decides whether a Gmail sync run walks back through the
 * mailbox again.
 *
 * The bug this pins down: raising the sync cap after the first sync used to do
 * nothing at all. Whether a backfill ran was inferred from the stored
 * historyId, which is written before any message is fetched, so an account was
 * stuck on whatever the first run happened to pull — an interrupted initial
 * sync looked exactly like a finished one.
 */
final class AccountBackfillTest extends TestCase
{
    public function testNeedsBackfillWhenNoneHasEverCompleted(): void
    {
        self::assertTrue($this->account(limit: 5000)->needsBackfill());
    }

    public function testDoesNotNeedBackfillOnceTheCapIsCovered(): void
    {
        $account = $this->account(limit: 5000);
        $account->backfillTarget = 5000;

        self::assertFalse($account->needsBackfill());
    }

    public function testNeedsBackfillWhenTheCapIsRaised(): void
    {
        // The reported symptom: 500 synced, cap raised to 5000, nothing more
        // ever arrived.
        $account = $this->account(limit: 5000);
        $account->backfillTarget = 500;

        self::assertTrue($account->needsBackfill());
    }

    public function testNeedsBackfillWhenTheCapIsLifted(): void
    {
        $account = $this->account(limit: 0);
        $account->backfillTarget = 5000;

        self::assertTrue($account->needsBackfill());
    }

    public function testLoweringTheCapDoesNotTriggerAnotherBackfill(): void
    {
        // Already holds more than the new cap asks for. Lowering is not a
        // request to delete anything, so there is simply nothing to do.
        $account = $this->account(limit: 500);
        $account->backfillTarget = 5000;

        self::assertFalse($account->needsBackfill());
    }

    public function testACompletedUncappedBackfillIsNeverRepeated(): void
    {
        $account = $this->account(limit: 0);
        $account->backfillTarget = 0;

        self::assertFalse($account->needsBackfill());
    }

    public function testBackfillRanAtSurvivesTheSettingsBagRoundTrip(): void
    {
        $ranAt   = new \DateTimeImmutable('2026-07-28 12:34:56');
        $account = $this->account(limit: 0);
        $account->backfillRanAt = $ranAt;

        self::assertSame($ranAt->getTimestamp(), $account->backfillRanAt?->getTimestamp());
    }

    public function testBackfillStateIsUnsetOnAFreshAccount(): void
    {
        $account = $this->account(limit: 0);

        self::assertNull($account->backfillTarget);
        self::assertNull($account->backfillRanAt);
    }

    private function account(int $limit): Account
    {
        $account            = new Account();
        $account->syncLimit = $limit;

        return $account;
    }
}
