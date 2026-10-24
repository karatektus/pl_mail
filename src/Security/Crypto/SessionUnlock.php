<?php

declare(strict_types=1);

namespace App\Security\Crypto;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The server's half of keeping a keyring unlocked across page loads.
 *
 * At sign-in the browser derives the key that unwraps the user key, wraps it
 * under a random key of its own (K1), keeps the result in localStorage and
 * sends only K1 here, where it lives in the session. Any later page fetches K1
 * back and opens what it stored. Neither half opens anything alone: the server
 * never sees the wrapped key, and what the browser keeps is useless once the
 * session — and K1 with it — is gone, at sign-out or expiry. That is what lets
 * somebody sign in once and never be asked again, in every tab, without the
 * password-derived key sitting anywhere in the clear. See assets/crypto/session.js.
 */
final readonly class SessionUnlock
{
    private const string KEY = 'plmail.e2e.unlock_key';

    /** 256 bits: an AES-256-GCM key. */
    private const int BYTES = 32;

    public function __construct(
        private RequestStack $requests,
    ) {
    }

    public static function isWellFormed(mixed $value): bool
    {
        return is_string($value)
            && self::BYTES === strlen((string) base64_decode($value, true))
            && base64_encode((string) base64_decode($value, true)) === $value;
    }

    public function store(string $unlockKey): void
    {
        if (true === self::isWellFormed($unlockKey)) {
            $this->requests->getSession()->set(self::KEY, $unlockKey);
        }
    }

    public function current(): ?string
    {
        $value = $this->requests->getSession()->get(self::KEY);

        return true === self::isWellFormed($value) ? $value : null;
    }
}
