<?php

declare(strict_types=1);

namespace App\Security\Crypto;

/**
 * The shape of everything the browser wraps and the server only stores.
 *
 *     v1.<base64 12-byte IV>.<base64 AES-256-GCM ciphertext with its tag>
 *
 * The server cannot open one, and has no business trying. What it can do is
 * refuse anything that is not this shape before it overwrites a good copy with
 * it — a user key replaced by garbage is a keyring nobody can open again, and
 * the browser that sent the garbage is the last one that would notice.
 * assets/crypto/aead.js writes this format.
 */
final class Wrapped
{
    private const int IV_BYTES = 12;

    /** The GCM tag alone is 16 bytes; anything shorter wraps nothing. */
    private const int MIN_CIPHERTEXT_BYTES = 17;

    /** Generous for a 32-byte key or passphrase, and a bound on the column. */
    private const int MAX_LENGTH = 4096;

    /**
     * For a wrapped private key rather than a key or passphrase: an S/MIME
     * key is PKCS#8, which for RSA-4096 is some 2.4 kB before base64.
     */
    public const int MAX_KEY_LENGTH = 16384;

    public static function isWellFormed(mixed $value, int $maxLength = self::MAX_LENGTH): bool
    {
        if (false === is_string($value) || $maxLength < strlen($value)) {
            return false;
        }

        $parts = explode('.', $value);

        if (3 !== count($parts) || 'v1' !== $parts[0]) {
            return false;
        }

        $iv         = base64_decode($parts[1], true);
        $ciphertext = base64_decode($parts[2], true);

        return false !== $iv
            && false !== $ciphertext
            && self::IV_BYTES === strlen($iv)
            && self::MIN_CIPHERTEXT_BYTES <= strlen($ciphertext);
    }
}
