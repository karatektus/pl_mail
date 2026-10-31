<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use Symfony\Component\Messenger\Exception\RecoverableExceptionInterface;

/**
 * The JMAP server asked us to slow down: 429, or 503 with a Retry-After.
 *
 * Shaped like GraphThrottledException and GmailThrottledException, so the
 * sync handler can put a sync back for the time the server asked for rather
 * than spending Messenger's retry ladder on it — see
 * SyncAccountMessageHandler, which catches all three in one place.
 */
final class JmapThrottledException extends JmapApiException implements RecoverableExceptionInterface
{
    /** What to wait when the server said to wait and not for how long. */
    private const int FALLBACK_DELAY_SECONDS = 30;

    public function __construct(
        string $message = '',
        private readonly ?int $retryAfterSeconds = null,
        int $status = 429,
    ) {
        parent::__construct($message, $status, 'limit');
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
