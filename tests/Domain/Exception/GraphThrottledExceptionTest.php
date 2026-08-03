<?php

declare(strict_types=1);

namespace App\Tests\Domain\Exception;

use App\Domain\Exception\GraphThrottledException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\RecoverableExceptionInterface;

/**
 * What a Graph throttle tells Messenger when it escapes a handler.
 *
 * The sync handlers re-slice per-sub-request throttles themselves, so this
 * only matters for the whole-batch case — which is exactly the one that used
 * to be logged and swallowed, losing the user's change.
 */
final class GraphThrottledExceptionTest extends TestCase
{
    public function testItIsRecoverableSoMessengerRetriesRatherThanDeadLetters(): void
    {
        self::assertInstanceOf(RecoverableExceptionInterface::class, new GraphThrottledException('throttled'));
    }

    public function testItHonoursGraphsRetryAfter(): void
    {
        // Graph, unlike Gmail, usually does send one.
        self::assertSame(120_000, new GraphThrottledException('throttled', 120)->getRetryDelay());
    }

    public function testItFallsBackToThirtySecondsWhenGraphSendsNoRetryAfter(): void
    {
        self::assertSame(30_000, new GraphThrottledException('throttled')->getRetryDelay());
    }

    /**
     * The interface defaults this to true, which retries regardless of the
     * transport's max_retries. An unbounded loop against a mailbox that is
     * already throttling us is worse than giving up, so it has to be false.
     */
    public function testItDoesNotForceRetryPastTheTransportsLimit(): void
    {
        self::assertFalse(new GraphThrottledException('throttled')->forceRetry());
    }
}
