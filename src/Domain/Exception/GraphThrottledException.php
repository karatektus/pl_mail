<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use Symfony\Component\Messenger\Exception\RecoverableExceptionInterface;

/**
 * HTTP 429/503 from Graph. Mail throttling is per-mailbox (roughly 10k
 * requests / 10 minutes, and only ~4 concurrent requests per mailbox), so
 * this is expected under load rather than exceptional — callers honour
 * Retry-After and requeue.
 *
 * Recoverable, so a throttle that escapes a caller is retried by Messenger
 * rather than dead-lettered. That matters for the *whole-batch* case: the
 * sync handlers re-slice per-sub-request throttles themselves, but when the
 * batch request as a whole is refused there is nothing to re-slice, and the
 * handler that swallowed it lost the user's change outright.
 *
 * forceRetry() is false for the same reason as GmailThrottledException: the
 * interface defaults to retrying regardless of the transport's max_retries,
 * and an unbounded loop against a mailbox that is already throttling us is
 * worse than giving up.
 */
final class GraphThrottledException extends GraphApiException implements RecoverableExceptionInterface
{
    /**
     * Graph usually *does* send Retry-After, unlike Gmail. This is the floor
     * for the times it does not, and matches the delay the Graph handlers
     * already use when requeueing sub-request throttles by hand.
     */
    private const int FALLBACK_DELAY_SECONDS = 30;

    public function __construct(
        string $message = '',
        private readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message, 429);
    }

    public function getRetryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    public function getRetryDelay(): int
    {
        return 1000 * ($this->retryAfterSeconds ?? self::FALLBACK_DELAY_SECONDS);
    }

    public function forceRetry(): bool
    {
        return false;
    }
}
