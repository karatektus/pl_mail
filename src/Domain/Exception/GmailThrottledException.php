<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use Symfony\Component\Messenger\Exception\RecoverableExceptionInterface;

/**
 * A transient Gmail quota rejection — rateLimitExceeded, userRateLimitExceeded
 * or quotaExceeded. Gmail signals these with 403, not 429, which is why they
 * were indistinguishable from a permissions failure until the body was read.
 *
 * Recoverable, so Messenger retries rather than dead-letters. forceRetry() is
 * false on purpose: the interface's default is to retry regardless of the
 * transport's max_retries, and an unbounded loop against something that is
 * already rate-limiting us is the one outcome worse than failing.
 *
 * getRetryDelay() never returns null, because null hands the decision back to
 * the transport's strategy — currently 1s/2s/4s, which for a quota that clears
 * in minutes is three more rejections rather than a backoff.
 */
final class GmailThrottledException extends GmailApiException implements RecoverableExceptionInterface
{
    /**
     * Used when Gmail sends no Retry-After, which is the norm for a 403 quota
     * rejection. Matches the fallback the Graph handlers use for the same
     * situation, rounded up: Gmail's per-user limits are measured over a
     * rolling window rather than a fixed second.
     */
    private const int FALLBACK_DELAY_SECONDS = 60;

    public function __construct(
        string $message,
        int $status,
        string $reason,
        private readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message, $status, $reason);
    }

    // Narrowed from the interface's ?int on purpose — see the class docblock:
    // null would hand the delay back to the transport's strategy.
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
