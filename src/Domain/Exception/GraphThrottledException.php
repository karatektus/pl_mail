<?php

declare(strict_types=1);

namespace App\Domain\Exception;


/**
 * HTTP 429/503 from Graph. Mail throttling is per-mailbox (roughly 10k
 * requests / 10 minutes, and only ~4 concurrent requests per mailbox), so
 * this is expected under load rather than exceptional — callers honour
 * Retry-After and requeue.
 */
final class GraphThrottledException extends GraphApiException
{
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
}
