<?php

declare(strict_types=1);

namespace App\Domain\DTO\Crypto;

use App\Domain\Enum\Crypto\AddressKeyOrigin;
use App\Domain\Exception\KeyringRefused;
use App\Security\Crypto\Wrapped;
use DateTimeImmutable;
use Throwable;

/**
 * One address key as a browser sends it, checked for shape before anything is
 * stored.
 *
 * The server cannot open the private key and does not try; it checks what it
 * can without: that the armor says what it should, that the passphrase is
 * wrapped, that every address is one the person owns. A key bound to somebody
 * else's address would be offered to their contacts under that address.
 */
final readonly class AddressKeyInput
{
    /** A Curve25519 key is about 1 KB armored, a 4096-bit RSA one about 7 KB. */
    private const int MAX_ARMOR_BYTES = 65536;

    /**
     * @param list<array{email: string, primary: bool}> $addresses
     */
    private function __construct(
        public string $fingerprint,
        public string $publicKey,
        public string $privateKey,
        public string $token,
        public AddressKeyOrigin $origin,
        public ?DateTimeImmutable $expiresAt,
        public ?string $revocationCertificate,
        public array $addresses,
    ) {
    }

    /**
     * @param list<string> $ownedAddresses lower-cased
     */
    public static function fromArray(mixed $input, array $ownedAddresses): self
    {
        if (false === is_array($input)) {
            throw new KeyringRefused('An address key must be an object.');
        }

        $fingerprint = strtolower((string) ($input['fingerprint'] ?? ''));

        if (1 !== preg_match('/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/', $fingerprint)) {
            throw new KeyringRefused('The fingerprint is not a v4 or v6 OpenPGP fingerprint.');
        }

        $publicKey  = self::armor($input['publicKey'] ?? null, 'PUBLIC KEY BLOCK');
        $privateKey = self::armor($input['privateKey'] ?? null, 'PRIVATE KEY BLOCK');

        if (false === Wrapped::isWellFormed($input['token'] ?? null)) {
            throw new KeyringRefused('The passphrase of the key is not wrapped.');
        }

        $origin = AddressKeyOrigin::tryFrom((string) ($input['origin'] ?? ''))
            ?? throw new KeyringRefused('Unknown key origin.');

        $revocation = null;

        if (null !== ($input['revocationCertificate'] ?? null)) {
            $revocation = self::armor($input['revocationCertificate'], 'PUBLIC KEY BLOCK');
        }

        $expiresAt = null;

        if (null !== ($input['expiresAt'] ?? null)) {
            try {
                $expiresAt = new DateTimeImmutable((string) $input['expiresAt']);
            } catch (Throwable) {
                throw new KeyringRefused('The expiry is not a date.');
            }
        }

        $addresses = [];

        foreach (is_array($input['addresses'] ?? null) ? $input['addresses'] : [] as $binding) {
            $email = strtolower(trim((string) ($binding['email'] ?? '')));

            if (false === in_array($email, $ownedAddresses, true)) {
                throw new KeyringRefused(sprintf('%s is not an address of this account.', $email));
            }

            $addresses[] = ['email' => $email, 'primary' => true === ($binding['primary'] ?? false)];
        }

        if ([] === $addresses) {
            throw new KeyringRefused('A key has to serve at least one address.');
        }

        return new self($fingerprint, $publicKey, $privateKey, (string) $input['token'], $origin, $expiresAt, $revocation, $addresses);
    }

    private static function armor(mixed $value, string $block): string
    {
        if (false === is_string($value) || self::MAX_ARMOR_BYTES < strlen($value)
            || false === str_starts_with(ltrim($value), '-----BEGIN PGP ' . $block . '-----')) {
            throw new KeyringRefused(sprintf('Expected an armored PGP %s.', strtolower($block)));
        }

        return $value;
    }
}
