<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sharing;

/**
 * Minting and recognising the secret in a public calendar URL.
 *
 * Both public features — a share link and a booking page — are gated by a
 * string in the path and nothing else. There is no session, no header and no
 * second factor: whoever holds the URL is the audience. That makes the string a
 * credential, and this is the one place that decides what a credential of that
 * kind looks like, so the two features cannot end up with different entropy or
 * different storage by being written a fortnight apart.
 *
 * **32 bytes of CSPRNG, and the reasoning is DevicePairingService's.** A
 * pairing code gets 32 bytes because guessing one inside its two-minute window
 * is not a thing that happens; a share link gets the same because it has no
 * window at all — it is live until somebody revokes it, so the only defence
 * against enumeration is that the space cannot be walked. That is also why
 * neither endpoint carries a lockout of its own: there is nothing to
 * brute-force, and a lockout on a public GET is a way for a stranger to take
 * somebody's link off the internet.
 *
 * **The digest is what is stored, never the token.** DevicePairingService keys
 * its cache by `hash('sha256', $code)` rather than by the code — its test is
 * called testTheCacheKeyIsADigestNotTheCodeItself — and the same argument gets
 * stronger the longer the secret lives. A pairing code in a stolen cache is
 * worthless in two minutes. A share link in a stolen database dump is a working
 * URL into somebody's diary until they notice.
 *
 * **Plain SHA-256 rather than a password hash, and that is not an oversight.**
 * Argon2 exists to make a low-entropy secret expensive to guess. This secret
 * has 256 bits of entropy and no guessing is happening; what is needed is a
 * lookup on an indexed column, which a deliberately slow hash cannot serve —
 * the digest is the WHERE clause, so every public request would pay the work
 * factor before it could find the row. The same trade ApiToken makes, for the
 * same reason.
 *
 * **URL-safe base64 rather than hex**, so the token is 43 characters instead of
 * 64 and survives being pasted into a chat window, an email client that wraps
 * long lines, and a QR code.
 */
final readonly class PublicLinkToken
{
    /**
     * 32 bytes, matching DevicePairingService::CODE_BYTES. Named rather than
     * inlined so the two cannot drift while both claiming to be "the same
     * entropy as pairing".
     */
    private const int TOKEN_BYTES = 32;

    /**
     * What a token may contain, as a route requirement.
     *
     * Declared here rather than written into two route attributes: it is the
     * alphabet mint() produces, and a requirement that disagreed with it would
     * 404 a freshly minted link rather than fail anywhere a test would look.
     * The length is bounded so a multi-kilobyte path cannot reach the hash.
     */
    public const string ROUTE_PATTERN = '[A-Za-z0-9_-]{20,64}';

    /**
     * A new token. The only moment it exists in a readable form is the return
     * of this call and whatever the caller does with it immediately after.
     */
    public function mint(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
    }

    /**
     * What goes in the column, for a token that arrived from anywhere.
     *
     * Takes any string, including a hostile one, because that is exactly what
     * the public routes hand it. Hashing rather than validating is what makes
     * that safe: an attacker-supplied path becomes 64 hex characters before it
     * reaches a query, so there is no shape of input that is not a digest by
     * the time it is compared.
     */
    public function digest(string $token): string
    {
        return hash('sha256', $token);
    }
}
