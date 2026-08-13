<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use RuntimeException;

/**
 * A reconnect came back authorised as somebody other than the account being
 * repaired.
 *
 * Raised by OAuthAccountLinker::relink() and never recovered from — see that
 * method's identity-guard note. The two addresses are carried so the callback
 * can tell the user which one it got, because "that was the wrong account" is
 * only actionable if you say which one you were signed in as.
 *
 * Its own exception type rather than a plain RuntimeException so the callback
 * can distinguish it from the provider failing: one means "sign out of the
 * other account and try again" and the other means "the provider is unhappy",
 * and offering the wrong advice for either wastes the user's afternoon.
 */
final class AccountIdentityMismatch extends RuntimeException
{
    public function __construct(
        public readonly string $expected,
        public readonly string $actual,
    ) {
        parent::__construct(sprintf(
            'Reconnect authorised %s, but this account is %s. Refusing to re-point it.',
            $actual,
            $expected,
        ));
    }
}
