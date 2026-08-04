<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use Symfony\Component\Messenger\Exception\RecoverableExceptionInterface;

/**
 * The remote is refusing us for now and will not later — 429 with a
 * Retry-After, Google's rateLimitExceeded, Graph's 429 or 503 on the calendar
 * endpoints, a CalDAV server under load.
 *
 * Recoverable, so Messenger retries rather than dead-letters. The shape is
 * lifted wholesale from GmailThrottledException because the reasoning is
 * identical and having two answers to the same question is how they drift:
 *
 *   forceRetry() is false. The interface's default retries regardless of the
 *   transport's max_retries, and an unbounded loop against something already
 *   rate-limiting us is the one outcome worse than failing.
 *
 *   getRetryDelay() never returns null. Null hands the decision back to the
 *   transport's strategy, and for a quota that clears in minutes that is
 *   several more rejections rather than a backoff.
 *
 * The fallback is a whole minute rather than the ingest transport's five
 * seconds because calendar quotas are per-user-per-minute on both Google and
 * Graph: retrying inside that window is guaranteed to be refused again and
 * spends the quota the retry is waiting for.
 */
final class CalendarSyncThrottledException extends CalendarSyncException implements RecoverableExceptionInterface
{
    /** Used when the remote sends no Retry-After, which is common. */
    private const int FALLBACK_DELAY_SECONDS = 60;

    public function __construct(
        string $message,
        int $status = 429,
        private readonly ?int $retryAfterSeconds = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    // Narrowed from the interface's ?int on purpose — see the class docblock.
    public function getRetryDelay(): int
    {
        return 1000 * ($this->retryAfterSeconds ?? self::FALLBACK_DELAY_SECONDS);
    }

    public function forceRetry(): bool
    {
        return false;
    }

    public function getRetryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }
}
