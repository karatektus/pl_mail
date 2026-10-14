<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Domain\DTO\Crypto\PublicKeyInfo;
use DateTimeImmutable;

/**
 * Reads public OpenPGP keys — armored or binary, one or several — through gpg.
 *
 * gpg, not a parser of plMail's own, for the reason the signature check uses
 * it: it checks every self-signature and drops what does not hold, so a user
 * ID somebody bolted onto another person's key without that person's key
 * signing it never reaches the question "does this key belong to anna@?".
 * A parser here would have to get that right on its own, and that is exactly
 * the part that is easy to get wrong.
 *
 * Private key material is refused before gpg sees it. Nothing a contact sends
 * should contain it, and importing secret keys would need an agent the
 * throwaway home deliberately does not have.
 */
final readonly class PublicKeyReader
{
    /**
     * Several kilobytes for an ordinary key, more for an old RSA key with years
     * of certifications on it. Anything past this is not a key anybody needs
     * plMail to keep.
     */
    public const int MAX_BYTES = 262144;

    /** A key file with more keys than this is a keyring dump, not a contact's key. */
    private const int MAX_KEYS = 20;

    /**
     * @return list<PublicKeyInfo> empty when nothing in $data is a public key,
     *         when it is too big, or when ext-gnupg is not installed or
     *         gpg does not start
     */
    public function read(string $data): array
    {
        if ('' === trim($data) || strlen($data) > self::MAX_BYTES || true === self::containsPrivateKey($data)) {
            return [];
        }

        if (false === GnupgHome::available()) {
            return [];
        }

        return GnupgHome::with(static function (\gnupg $gpg) use ($data): array {
            $imported = $gpg->import($data);

            if (false === $imported || 0 === (int) ($imported['imported'] ?? 0) + (int) ($imported['unchanged'] ?? 0)) {
                return [];
            }

            $keys = [];

            foreach (array_slice((array) $gpg->keyinfo(''), 0, self::MAX_KEYS) as $key) {
                $info = self::describe($gpg, (array) $key);

                if (null !== $info) {
                    $keys[] = $info;
                }
            }

            return $keys;
        });
    }

    public static function containsPrivateKey(string $data): bool
    {
        if (true === str_contains($data, 'PRIVATE KEY BLOCK')) {
            return true;
        }

        // Binary: a secret key packet first, old format (0x94–0x97) or new
        // (0xC5). A public key starts 0x98–0x9B or 0xC6.
        $first = '' === $data ? 0 : ord($data[0]);

        return 0x94 === ($first & 0xFC) || 0xC5 === $first;
    }

    /**
     * @param array<string, mixed> $key one entry of gnupg::keyinfo()
     */
    private static function describe(\gnupg $gpg, array $key): ?PublicKeyInfo
    {
        $subkeys = (array) ($key['subkeys'] ?? []);
        $primary = (array) ($subkeys[0] ?? []);
        $fingerprint = strtolower((string) ($primary['fingerprint'] ?? ''));

        if ('' === $fingerprint || true === (bool) ($key['is_secret'] ?? false)) {
            return null;
        }

        $addresses = [];

        foreach ((array) ($key['uids'] ?? []) as $uid) {
            $uid   = (array) $uid;
            $email = strtolower(trim((string) ($uid['email'] ?? '')));

            if ('' !== $email && false === (bool) ($uid['revoked'] ?? false) && false === (bool) ($uid['invalid'] ?? false)) {
                $addresses[] = $email;
            }
        }

        $armored = $gpg->export($fingerprint);
        $expires = (int) ($primary['expires'] ?? 0);

        if (false === $armored || '' === $armored) {
            return null;
        }

        return new PublicKeyInfo(
            fingerprint: $fingerprint,
            addresses:   array_values(array_unique($addresses)),
            revoked:     true === (bool) ($key['revoked'] ?? false) || true === (bool) ($primary['revoked'] ?? false),
            expired:     true === (bool) ($key['expired'] ?? false) || true === (bool) ($primary['expired'] ?? false),
            canEncrypt:  true === (bool) ($key['can_encrypt'] ?? false),
            expiresAt:   0 < $expires ? new DateTimeImmutable('@' . $expires) : null,
            armored:     $armored,
        );
    }
}
