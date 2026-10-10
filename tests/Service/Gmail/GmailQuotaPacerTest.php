<?php

declare(strict_types=1);

namespace App\Tests\Service\Gmail;

use App\Entity\Mail\Account;
use App\Service\Gmail\GmailQuotaPacer;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Clock\MockClock;

/**
 * The pacer exists to keep an import under Google's 6,000 units a minute, so
 * the test that matters is the one that counts a minute. The rest pin down who
 * is made to wait and who is not.
 *
 * MockClock::sleep() moves the clock instead of waiting, which is what makes
 * "how long was the caller held" readable as a difference of two times.
 */
final class GmailQuotaPacerTest extends TestCase
{
    /** A full batch: fifty messages.get at twenty units. */
    private const int BATCH = 1000;

    public function testAFewBatchesInARowAreNotHeld(): void
    {
        $clock   = new MockClock('2026-10-08 14:30:00');
        $pacer   = new GmailQuotaPacer($clock);
        $account = $this->account(1);
        $start   = $clock->now();

        for ($i = 0; $i < 1; $i++) {
            $pacer->spend($account, self::BATCH);
        }

        self::assertEquals($start, $clock->now());
    }

    public function testAnImportNeverSpendsMoreThanTheLimitInAnyMinute(): void
    {
        $clock   = new MockClock('2026-10-08 14:30:00');
        $pacer   = new GmailQuotaPacer($clock);
        $account = $this->account(1);

        /** @var list<float> $spentAt */
        $spentAt = [];

        // Ten minutes of a worker that fetches as fast as it is allowed to.
        for ($i = 0; $i < 200; $i++) {
            $pacer->spend($account, self::BATCH);
            $spentAt[] = (float) $clock->now()->format('U.u');
        }

        $worst = 0;

        foreach ($spentAt as $from) {
            $inWindow = count(array_filter($spentAt, static fn (float $at): bool => $at >= $from && $at < $from + 60.0));
            $worst    = max($worst, $inWindow * self::BATCH);
        }

        self::assertLessThanOrEqual(4_800, $worst);
        // And not so cautious that an import crawls: the steady rate is used.
        self::assertGreaterThanOrEqual(3_800, $worst);
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

        for ($i = 0; $i < 1; $i++) {
            $pacer->spend($account, self::BATCH);
        }

        self::assertEquals($before, $clock->now());
    }

    private function account(int $id): Account
    {
        $account = new Account();
        (new ReflectionProperty(Account::class, 'id'))->setValue($account, $id);

        return $account;
    }
}
