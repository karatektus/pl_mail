<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * Any non-success response from the Gmail REST API.
 *
 * Carries Google's own failure `reason` alongside the status because the two
 * are not interchangeable: Gmail answers 403 for rate limiting as well as for
 * permission errors, so a bare status tells a caller nothing about whether the
 * call is worth making again. Symfony's own HTTP exception discards the body
 * entirely, which is how a rate limit reached production disguised as a
 * permissions failure.
 *
 * Deliberately carries neither Messenger marker interface: an unclassified
 * failure falls back to the transport's default retry strategy, which is what
 * happened before this hierarchy existed.
 */
class GmailApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $status = 0,
        private readonly string $reason = '',
    ) {
        parent::__construct($message, $status);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Google's machine-readable failure reason ("userRateLimitExceeded",
     * "insufficientPermissions", …), or '' when the body carried none.
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}
