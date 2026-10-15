<?php

declare(strict_types=1);

namespace App\Tests\Service\Gmail;

use App\Entity\Mail\Account;
use App\Service\Gmail\GmailQuotaPacer;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

/**
 * The pacer exists to keep an import under Google's per-user limit, so the
 * tests that matter count a minute — at the limit it starts from, and at the
 * one a refusal teaches it (#90). The rest pin down who is made to wait and
 * who is not.
 *
 * MockClock::sleep() moves the clock instead of waiting, which is what makes
 * "how long was the caller held" readable as a difference of two times.
 */
final class GmailQuotaPacerTest extends TestCase
{
    /** One request: five messages at sixty units. */
    private const int BATCH = 300;

    public function testAFewBatchesInARowAreNotHeld(): void
    {
        $clock   = new MockClock('2026-10-08 14:30:00');
        $pacer   = new GmailQuotaPacer($clock);
        $account = $this->account(1);
        $start   = $clock->now();

        for ($i = 0; $i < 3; $i++) {
            $pacer->spend($account, self::BATCH);
        }

        self::assertEquals($start, $clock->now());
    }

    public function testAnImportNeverSpendsMoreThanTheLimitInAnyMinute(): void
    {
        $clock = new MockClock('2026-10-08 14:30:00');
        $pacer = new GmailQuotaPacer($clock);

        $worst = $this->worstMinute($pacer, $clock, $this->account(1));

        self::assertLessThanOrEqual(14_200, $worst);
        // And not so cautious that an import crawls: the steady rate is used.
        self::assertGreaterThanOrEqual(12_600, $worst);
    }

    /**
     * The whole of #90: a project with 6,000 units a minute, and a pacer that
     * allowed 9,000 because 15,000 was written into it.
     */
    public function testARefusalUnderALowerLimitIsWhatTheImportIsPacedToFromThenOn(): void
    {
        $clock   = new MockClock('2026-10-08 14:30:00');
        $pacer   = new GmailQuotaPacer($clock);
        $account = $this->account(1);

        self::assertSame(15_000, $pacer->limit());

        $pacer->refused($account, 'defaultPerMinutePerUser', 6000);

        self::assertSame(6000, $pacer->limit());

        $clock->sleep(120);

        $worst = $this->worstMinute($pacer, $clock, $account);

        self::assertLessThanOrEqual(5900, $worst);
        self::assertGreaterThanOrEqual(5000, $worst);
    }

    public function testARefusedAccountIsLeftAloneForAMinuteAndThenIsNot(): void
    {
        $clock   = new MockClock('2026-10-08 14:30:00');
        $pacer   = new GmailQuotaPacer($clock);
        $account = $this->account(1);

        self::assertSame(0, $pacer->waitSeconds($account));

        $pacer->refused($account, 'defaultPerMinutePerUser', 6000);

        self::assertSame(60, $pacer->waitSeconds($account));
        self::assertSame(0, $pacer->waitSeconds($this->account(2)), 'a refusal is one mailbox\'s, the limit is everybody\'s');

        $clock->sleep(61);

        self::assertSame(0, $pacer->waitSeconds($account));
    }

    /**
     * The quota probe needs a minute with nothing else in it. Holding an
     * account makes its jobs wait as a refusal does, and means none of what a
     * refusal means: nothing is learnt and nothing is slowed.
     */
    public function testAnAccountCanBeHeldWithoutBeingTreatedAsRefused(): void
    {
        $clock   = new MockClock('2026-10-08 14:30:00');
        $pacer   = new GmailQuotaPacer($clock);
        $account = $this->account(1);

        $pacer->refused($account, 'defaultPerMinutePerUser', 6000);
        $clock->sleep(61);

        $pacer->hold($account, 600);

        self::assertSame(600, $pacer->waitSeconds($account));
        self::assertSame(6000, $pacer->limit());

        $pacer->hold($account, 0);

        self::assertSame(0, $pacer->waitSeconds($account));
        self::assertSame(85, $pacer->messagesPerMinute($account));
    }

    public function testGooglesOwnRetryAfterIsBelievedOverTheMinute(): void
    {
        $pacer   = new GmailQuotaPacer(new MockClock('2026-10-08 14:30:00'));
        $account = $this->account(1);

        $pacer->refused($account, 'defaultPerMinutePerUser', 6000, 200);

        self::assertSame(200, $pacer->waitSeconds($account));
    }

    /**
     * The per-project limit is 1,200,000. Taken for the per-user one it would
     * teach nothing; a refusal under it still means "not now".
     */
    public function testARefusalUnderTheProjectsLimitHoldsTheAccountAndTeachesNothing(): void
    {
        $pacer   = new GmailQuotaPacer(new MockClock('2026-10-08 14:30:00'));
        $account = $this->account(1);

        $pacer->refused($account, 'defaultPerMinutePerProject', 1_200_000);

        self::assertSame(15_000, $pacer->limit());
        self::assertSame(60, $pacer->waitSeconds($account));
    }

    /** The sentence names the limit even when the detail with its value is missing. */
    public function testAPerUserRefusalThatGivesNoValueFallsBackToTodaysDefault(): void
    {
        $pacer = new GmailQuotaPacer(new MockClock('2026-10-08 14:30:00'));

        $pacer->refused($this->account(1), 'Unitsperminuteperuser');

        self::assertSame(6000, $pacer->limit());
    }

    /** Another client spending the same user's quota is not news about the limit. */
    public function testALearntLimitIsNeverRaisedByALaterRefusal(): void
    {
        $pacer   = new GmailQuotaPacer(new MockClock('2026-10-08 14:30:00'));
        $account = $this->account(1);

        $pacer->refused($account, 'defaultPerMinutePerUser', 6000);
        $pacer->refused($account, 'defaultPerMinutePerUser', 15_000);
        $pacer->refused($account, 'Unitsperminuteperuser');

        self::assertSame(6000, $pacer->limit());
    }

    /**
     * worker-backlog fetches an import's batches while worker-ingest fetches
     * the mail arriving meanwhile, each with a pacer of its own. What one
     * spent and what one was refused has to count for the other.
     */
    public function testTwoProcessesShareOneAllowanceAndOneRefusal(): void
    {
        $clock   = new MockClock('2026-10-08 14:30:00');
        $shared  = new ArrayAdapter();
        $backlog = new GmailQuotaPacer($clock, $shared);
        $ingest  = new GmailQuotaPacer($clock, $shared);
        $account = $this->account(1);
        $start   = $clock->now();

        for ($i = 0; $i < 3; $i++) {
            $backlog->spend($account, self::BATCH);
        }

        self::assertEquals($start, $clock->now());

        $ingest->spend($account, self::BATCH);

        self::assertGreaterThan($start, $clock->now(), 'the reserve the other process used is used');

        $backlog->refused($account, 'defaultPerMinutePerUser', 6000);

        self::assertSame(6000, $ingest->limit());
        self::assertSame(60, $ingest->waitSeconds($account));
    }

    /**
     * What the import line shows as "about N of the limit this minute". Its
     * own count, since Google reports none — and a minute's worth, so it falls
     * back as the minute passes.
     */
    public function testWhatWasSpentInTheLastMinuteIsKnownAndThenForgotten(): void
    {
        $clock   = new MockClock('2026-10-08 14:30:00');
        $pacer   = new GmailQuotaPacer($clock);
        $account = $this->account(1);

        self::assertSame(0, $pacer->usedThisMinute($account));

        $pacer->spend($account, self::BATCH);
        $pacer->spend($account, self::BATCH);

        self::assertSame(600, $pacer->usedThisMinute($account));
        self::assertSame(0, $pacer->usedThisMinute($this->account(2)));

        $clock->sleep(30);
        $pacer->spend($account, self::BATCH);

        self::assertSame(900, $pacer->usedThisMinute($account));

        $clock->sleep(45);

        self::assertSame(300, $pacer->usedThisMinute($account));

        $clock->sleep(60);

        self::assertSame(0, $pacer->usedThisMinute($account));
    }

    /** A worker flat out is shown spending what it is allowed, not more. */
    public function testAnImportFlatOutShowsItsSteadyAllowanceAsUsed(): void
    {
        $clock   = new MockClock('2026-10-08 14:30:00');
        $pacer   = new GmailQuotaPacer($clock);
        $account = $this->account(1);

        $pacer->refused($account, 'defaultPerMinutePerUser', 6000);
        $clock->sleep(120);

        for ($i = 0; $i < 100; $i++) {
            $pacer->spend($account, self::BATCH);
        }

        self::assertLessThanOrEqual(5900, $pacer->usedThisMinute($account));
        self::assertGreaterThanOrEqual(4300, $pacer->usedThisMinute($account));
        self::assertTrue($pacer->isLimitLearnt());
        self::assertSame(85, $pacer->messagesPerMinute($account));
    }

    /**
     * During the wait after a refusal nothing is spent and the running count
     * falls to nothing. What had been counted when Google said no is kept,
     * because that is the figure the pause is explained by.
     */
    public function testWhatHadBeenSpentWhenGoogleRefusedIsRemembered(): void
    {
        $clock   = new MockClock('2026-10-08 14:30:00');
        $pacer   = new GmailQuotaPacer($clock);
        $account = $this->account(1);

        $pacer->spend($account, self::BATCH);
        $pacer->spend($account, self::BATCH);
        $pacer->refused($account, 'defaultPerMinutePerUser', 6000);

        $clock->sleep(70);

        self::assertSame(0, $pacer->usedThisMinute($account));
        self::assertSame(600, $pacer->usedWhenRefused($account));
    }

    /**
     * An import that had counted 3,240 units of its 6,000 was refused (#90).
     * The count is not Google's — somebody reading mail, another client, a
     * cost reckoned differently — so a refusal at a limit already known means
     * "slower than this" whatever the count said.
     */
    public function testARefusalAtAKnownLimitSlowsThatAccountDown(): void
    {
        $clock   = new MockClock('2026-10-08 14:30:00');
        $pacer   = new GmailQuotaPacer($clock);
        $account = $this->account(1);

        // The refusal that teaches the limit is explained by the limit.
        $pacer->refused($account, 'defaultPerMinutePerUser', 6000);

        self::assertSame(85, $pacer->messagesPerMinute($account));

        $clock->sleep(61);
        $pacer->refused($account, 'defaultPerMinutePerUser', 6000);

        self::assertSame(63, $pacer->messagesPerMinute($account));
        self::assertSame(85, $pacer->messagesPerMinute($this->account(2)), 'who else spends a mailbox\'s quota is a fact about that mailbox');
    }

    /** A job that was under way when the first refusal came is not a second reason. */
    public function testRefusalsThatPileUpInsideOnePauseCountOnce(): void
    {
        $clock   = new MockClock('2026-10-08 14:30:00');
        $pacer   = new GmailQuotaPacer($clock);
        $account = $this->account(1);

        $pacer->refused($account, 'defaultPerMinutePerUser', 6000);
        $clock->sleep(61);

        $pacer->refused($account, 'defaultPerMinutePerUser', 6000);
        $clock->sleep(2);
        $pacer->refused($account, 'defaultPerMinutePerUser', 6000);
        $pacer->refused($account, 'defaultPerMinutePerUser', 6000);

        self::assertSame(63, $pacer->messagesPerMinute($account));
    }

    public function testASlowedAccountWinsItsPaceBackAndIsNeverStoppedOutright(): void
    {
        $clock   = new MockClock('2026-10-08 14:30:00');
        $pacer   = new GmailQuotaPacer($clock);
        $account = $this->account(1);

        $pacer->refused($account, 'defaultPerMinutePerUser', 6000);

        for ($i = 0; $i < 20; $i++) {
            $clock->sleep(61);
            $pacer->refused($account, 'defaultPerMinutePerUser', 6000);
        }

        self::assertSame(17, $pacer->messagesPerMinute($account), 'a fifth of 85, and no lower');

        // The pause, then ten quiet minutes: a fifth of the way back.
        $clock->sleep(60 + 600);

        self::assertSame(34, $pacer->messagesPerMinute($account));

        $clock->sleep(3600);

        self::assertSame(85, $pacer->messagesPerMinute($account));
    }

    public function testOneAccountImportingDoesNotHoldAnother(): void
    {
        $clock = new MockClock('2026-10-08 14:30:00');
        $pacer = new GmailQuotaPacer($clock);

        for ($i = 0; $i < 20; $i++) {
            $pacer->spend($this->account(1), self::BATCH);
        }

        $before = $clock->now();
        $pacer->spend($this->account(2), self::BATCH);

        self::assertEquals($before, $clock->now());
    }

    public function testAnIdleAccountGetsItsReserveBack(): void
    {
        $clock   = new MockClock('2026-10-08 14:30:00');
        $pacer   = new GmailQuotaPacer($clock);
        $account = $this->account(1);

        for ($i = 0; $i < 20; $i++) {
            $pacer->spend($account, self::BATCH);
        }

        $clock->sleep(120);
        $before = $clock->now();

        for ($i = 0; $i < 3; $i++) {
            $pacer->spend($account, self::BATCH);
        }

        self::assertEquals($before, $clock->now());
    }

    /**
     * Ten minutes of a worker that fetches as fast as it is allowed to, and
     * the most it spent in any sixty seconds of them.
     */
    private function worstMinute(GmailQuotaPacer $pacer, MockClock $clock, Account $account): int
    {
        /** @var list<float> $spentAt */
        $spentAt = [];

        for ($i = 0; $i < 300; $i++) {
            $pacer->spend($account, self::BATCH);
            $spentAt[] = (float) $clock->now()->format('U.u');
        }

        $worst = 0;

        foreach ($spentAt as $from) {
            $inWindow = count(array_filter($spentAt, static fn (float $at): bool => $at >= $from && $at < $from + 60.0));
            $worst    = max($worst, $inWindow * self::BATCH);
        }

        return $worst;
    }

    private function account(int $id): Account
    {
        $account = new Account();
        (new ReflectionProperty(Account::class, 'id'))->setValue($account, $id);

        return $account;
    }
}
