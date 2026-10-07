<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\PostIngest;

use App\Entity\Mail\Message;
use App\Service\Mail\PostIngest\RecentMailPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The one definition of "recent" that every post-ingest step shares.
 *
 * What is pinned here is the edge and the two fallbacks, because those are
 * where five steps would each have chosen slightly differently.
 */
final class RecentMailPolicyTest extends TestCase
{
    private const string NOW = '2026-10-07 12:00:00';

    public function testMailInsideTheWindowIsRecentAndMailOutsideItIsNot(): void
    {
        $policy = new RecentMailPolicy(30);
        $now    = new DateTimeImmutable(self::NOW);

        self::assertTrue($policy->isRecent($this->received('2026-10-06 12:00:00'), $now));
        self::assertTrue($policy->isRecent($this->received('2026-09-07 12:00:00'), $now), 'the cutoff itself is inside');
        self::assertFalse($policy->isRecent($this->received('2026-09-07 11:59:59'), $now));
        self::assertFalse($policy->isRecent($this->received('2019-03-01 08:00:00'), $now));
    }

    /**
     * A message is dated by when it was received and, failing that, by when it
     * was sent — the order EventProposer anchors on.
     */
    public function testTheSentDateStandsInForAMissingReceivedDate(): void
    {
        $policy = new RecentMailPolicy(30);
        $now    = new DateTimeImmutable(self::NOW);

        $old         = new Message();
        $old->sentAt = new DateTimeImmutable('2020-01-01 00:00:00');

        self::assertFalse($policy->isRecent($old, $now));
    }

    /**
     * Of the two mistakes available for a message with no date, doing a little
     * unnecessary work is the one nobody notices.
     */
    public function testAMessageWithNoDateAtAllIsRecent(): void
    {
        self::assertTrue(new RecentMailPolicy(30)->isRecent(new Message(), new DateTimeImmutable(self::NOW)));
    }

    /**
     * It comes from the environment: a typo must not switch every step off,
     * nor quietly restore the behaviour this exists to remove.
     */
    public function testTheWindowIsClampedRatherThanTrusted(): void
    {
        self::assertSame(1, new RecentMailPolicy(0)->recentDays);
        self::assertSame(1, new RecentMailPolicy(-5)->recentDays);
        self::assertSame(3650, new RecentMailPolicy(1_000_000)->recentDays);
        self::assertSame(90, new RecentMailPolicy(90)->recentDays);
    }

    private function received(string $at): Message
    {
        $message             = new Message();
        $message->receivedAt = new DateTimeImmutable($at);

        return $message;
    }
}
