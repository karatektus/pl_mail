<?php

declare(strict_types=1);

namespace App\Domain\DTO\Crypto;

use DateTimeImmutable;

/**
 * One public OpenPGP key as gpg read it: what plMail needs to decide whether
 * to keep it, and the armored copy to keep.
 *
 * Read by gpg rather than parsed here, so "the key has a user ID for this
 * address" means a user ID with a valid self-signature — gpg drops the ones
 * without — and "revoked" means what gpg means by it.
 */
final readonly class PublicKeyInfo
{
    /**
     * @param list<string> $addresses lower-cased addresses of the user IDs that are not revoked
     */
    public function __construct(
        public string             $fingerprint,
        public array              $addresses,
        public bool               $revoked,
        public bool               $expired,
        public bool               $canEncrypt,
        public ?DateTimeImmutable $expiresAt,
        public string             $armored,
    ) {
    }

    /**
     * Whether this key may stand for $email at all: not revoked, not
     * expired, and with a user ID for that address. Anything else is a key
     * plMail must not use for it, however it arrived.
     */
    public function usableFor(string $email): bool
    {
        return false === $this->revoked
            && false === $this->expired
            && true === in_array(strtolower(trim($email)), $this->addresses, true);
    }
}
