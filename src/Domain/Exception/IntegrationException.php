<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * Any failure talking to an integration service.
 *
 * The message is shown to the user — it lands in Integration::$lastError and
 * in the connect form — so drivers must phrase it for a person and must never
 * interpolate a credential into it.
 */
class IntegrationException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $status = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Whether the service rejected who we are, as opposed to failing for some
     * other reason. Callers use this to tell "your app password was revoked"
     * apart from "the server is down".
     */
    public function isAuthFailure(): bool
    {
        return 401 === $this->status || 403 === $this->status;
    }
}
