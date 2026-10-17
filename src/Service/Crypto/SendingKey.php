<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Domain\Enum\Crypto\AddressKeyStatus;
use App\Entity\Crypto\AddressKey;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Crypto\AddressKeyRepository;

/**
 * Which of a person's own keys stands for the address a message is sent from.
 *
 * An active key bound to that address, the one marked primary for it when
 * there are several — the same choice the browser makes when it signs
 * (assets/crypto/compose.js, signingKeyFor()), so the key a recipient is told
 * about in the Autocrypt header is the key the signature was made with.
 */
final readonly class SendingKey
{
    public function __construct(
        private AddressKeyRepository $addressKeys,
    ) {
    }

    public function for(User $user, string $email): ?AddressKey
    {
        $email = strtolower(trim($email));
        $found = null;

        foreach ($this->addressKeys->findForUser($user) as $key) {
            if (AddressKeyStatus::Active !== $key->status) {
                continue;
            }

            foreach ($key->bindings as $binding) {
                if ($email !== strtolower($binding->email)) {
                    continue;
                }

                if (true === $binding->isPrimary) {
                    return $key;
                }

                $found ??= $key;
            }
        }

        return $found;
    }

    /**
     * The key a message from this account is signed with: the one for the
     * address it is sent from, or — when that address has none, or is not an
     * address at all (an IMAP account whose "email" is a display name) — the
     * first of the account's own addresses that has one, in the order the
     * account lists them.
     */
    public function forAccount(User $user, Account $account, string $address): ?AddressKey
    {
        $key = $this->for($user, $address);

        foreach ($account->ownedAddresses as $owned) {
            $key ??= $this->for($user, (string) $owned);
        }

        return $key;
    }

    /**
     * The binary key inside an ASCII-armored block, or null when it is not one.
     * What Autocrypt's keydata and an attached `.asc` are made of.
     */
    public static function dearmor(string $armored): ?string
    {
        if (1 !== preg_match('/-----BEGIN PGP PUBLIC KEY BLOCK-----\r?\n(?:[^\r\n]+\r?\n)*?\r?\n([A-Za-z0-9+\/=\s]+?)(?:\r?\n=[A-Za-z0-9+\/]{4})?\s*-----END PGP PUBLIC KEY BLOCK-----/', $armored, $match)) {
            return null;
        }

        $binary = base64_decode((string) preg_replace('/\s+/', '', $match[1]), true);

        return false === $binary || '' === $binary ? null : $binary;
    }
}
