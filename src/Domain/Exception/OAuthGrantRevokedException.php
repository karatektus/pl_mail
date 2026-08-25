<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use RuntimeException;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;
use Throwable;

/**
 * The account's sign-in is gone, and no number of attempts will bring it back.
 *
 * A refresh token stops working for reasons that are all decisions rather than
 * conditions: the user revoked access in their Google or Microsoft account, the
 * password behind it changed, the token aged out of an unverified app's
 * six-month window, or an admin removed the consent. Every one of those answers
 * identically on the tenth attempt as on the first.
 *
 * Unrecoverable, so the envelope goes straight to the failure transport. That
 * matters for what the logs say rather than for what the app does: a dead grant
 * produced five CRITICAL lines per calendar and three per mailbox, all of them
 * the same sentence, which is how the one line that means something — the
 * account needs reconnecting — stops being read at all.
 *
 * The user is not left guessing either way. OAuthTokenManager records the
 * failure on the account as it happens, and AccountHealthInspector turns that
 * into the card with the Reconnect button on it; none of that depends on the
 * message being retried.
 *
 * WHAT IS DELIBERATELY NOT IN HERE
 *
 * A timeout against the token endpoint, a 500 from the provider, a DNS failure.
 * Those look the same from a distance — "the refresh did not work" — and are
 * the opposite case, so they stay unclassified and get retried. Classifying a
 * failure as permanent is a decision never to try again, and it is only safe
 * where the provider has said, in the vocabulary the spec defines for exactly
 * this, that the grant itself is finished.
 */
final class OAuthGrantRevokedException extends RuntimeException implements UnrecoverableExceptionInterface
{
    /**
     * The OAuth 2.0 error codes that mean the grant is over.
     *
     * RFC 6749 §5.2. `invalid_grant` is the one that actually arrives — it
     * covers revoked, expired and mismatched refresh tokens alike. The other
     * two mean the client registration itself no longer works, which is not the
     * same fault but has the same answer: nothing changes by asking again.
     */
    private const array TERMINAL_ERRORS = [
        'invalid_grant',
        'invalid_client',
        'unauthorized_client',
    ];

    /**
     * Whether a failed refresh is the grant being finished rather than a bad
     * moment.
     *
     * Read from the provider's own error code, not from an HTTP status: the
     * token endpoint answers 400 for a revoked refresh token and 400 for a
     * malformed request, and only the body tells them apart.
     */
    public static function isTerminal(Throwable $error): bool
    {
        if (false === $error instanceof IdentityProviderException) {
            return false;
        }

        // league/oauth2-client puts the provider's `error` field in the message
        // for a standards-shaped response; the body is checked too because not
        // every provider is standards-shaped, and Google nests the code under
        // `error` while returning the human sentence in `error_description`.
        $body = $error->getResponseBody();
        $haystack = strtolower($error->getMessage() . ' ' . (is_string($body) ? $body : json_encode($body)));

        foreach (self::TERMINAL_ERRORS as $code) {
            if (true === str_contains($haystack, $code)) {
                return true;
            }
        }

        return false;
    }
}
