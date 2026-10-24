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
     *         when it is too big, or when gpg is not there or does not start
     */
    public function read(string $data): array
    {
        if ('' === trim($data) || strlen($data) > self::MAX_BYTES || true === self::containsPrivateKey($data)) {
            return [];
        }

        if (false === GnupgHome::available()) {
            return [];
        }

        return GnupgHome::with(static function (GnupgHome $home) use ($data): array {
            // The exit code says nothing useful here: gpg exits 2 when it
            // skipped one key of several, having imported the rest. What
            // counts is what is in the home afterwards.
            $home->output($data, '--import');

            $keys = [];

            foreach (array_slice($home->keys(), 0, self::MAX_KEYS) as $key) {
                $info = self::describe($home, $key);

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
     * @param array{fingerprint: string, validity: string, expires: int, capabilities: string, addresses: list<string>} $key
     */
    private static function describe(GnupgHome $home, array $key): ?PublicKeyInfo
    {
        $armored = $home->output(null, '--armor', '--export', $key['fingerprint']);

        if ('' === $armored) {
            return null;
        }

        return new PublicKeyInfo(
            fingerprint: $key['fingerprint'],
            addresses:   array_values(array_unique($key['addresses'])),
            revoked:     'r' === $key['validity'],
            expired:     'e' === $key['validity'],
            // Upper case on the primary key: what the key as a whole can
            // still do, through a subkey that is neither expired nor revoked.
            canEncrypt:  true === str_contains($key['capabilities'], 'E'),
            expiresAt:   0 < $key['expires'] ? new DateTimeImmutable('@' . $key['expires']) : null,
            armored:     $armored,
        );
    }
}
