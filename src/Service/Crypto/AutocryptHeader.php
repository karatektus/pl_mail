<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Entity\User\User;

/**
 * The `Autocrypt:` header for a message somebody sends: their address and
 * their public key, so the program on the other end can answer encrypted
 * without anybody exchanging keys by hand (https://autocrypt.org/level1.html).
 *
 * On by default (User::$autocryptHeader) because it costs nobody anything —
 * mail programs do not show it — and it is the one thing that makes the reply
 * from a Thunderbird or a Delta Chat user arrive encrypted. Nothing is written
 * for an address the person has no active key for.
 *
 * The key data is folded with spaces every 76 characters: the header is
 * longer than any line a mail server is obliged to carry, and Autocrypt
 * readers ignore whitespace inside keydata, which is the folding the spec has
 * in mind.
 */
final readonly class AutocryptHeader
{
    public function __construct(
        private SendingKey $sendingKey,
    ) {
    }

    public function for(User $user, string $email): ?string
    {
        if (false === $user->autocryptHeader || '' === trim($email)) {
            return null;
        }

        $key    = $this->sendingKey->for($user, $email);
        $binary = null === $key ? null : SendingKey::dearmor($key->publicKey);

        if (null === $binary) {
            return null;
        }

        return sprintf('addr=%s; keydata=%s', strtolower(trim($email)), implode(' ', str_split(base64_encode($binary), 76)));
    }
}
