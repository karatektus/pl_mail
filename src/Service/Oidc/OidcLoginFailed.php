<?php

declare(strict_types=1);

namespace App\Service\Oidc;

use RuntimeException;

/**
 * A sign-in through the provider that cannot complete, for a reason the
 * person at the login form can be told.
 *
 * Carries a translation key in the `security` domain and a message for the
 * log, kept apart on purpose: the log wants the provider's own words and the
 * claim that failed, and the login form must not repeat either to whoever is
 * looking at it.
 */
final class OidcLoginFailed extends RuntimeException
{
    public function __construct(
        public readonly string $messageKey,
        string $logMessage,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($logMessage, 0, $previous);
    }
}
