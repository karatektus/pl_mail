<?php

declare(strict_types=1);

namespace App\Domain\Enum\Crypto;

/**
 * What a person's keyring hangs off: the secret the browser stretches into
 * the key that wraps their user keys.
 *
 * The login password for everybody who has one — the keyring opens with the
 * sign-in and nobody has a second thing to remember. An encryption passphrase
 * for an account that signs in only through single sign-on and so has no
 * password plMail could stretch: the provider's password never reaches
 * plMail, and a keyring hung off the provider's say-so would be one the
 * provider could open.
 *
 * Fixed once the keyring exists. An account on the passphrase that is later
 * given a login password keeps the passphrase — switching would mean
 * re-wrapping every key under a secret the browser that set it up never saw.
 */
enum KeyringRoot: string
{
    case Password   = 'password';
    case Passphrase = 'passphrase';

    /**
     * Whether the secret is the one the person signs in with — and so one the
     * server holds a hash of and can check before a session keeps its half of
     * the unlock (App\Controller\Crypto\UnlockController). A passphrase has no
     * hash here to check against; only the user key opening says it was right.
     */
    public function isLoginSecret(): bool
    {
        return match ($this) {
            self::Password   => true,
            self::Passphrase => false,
        };
    }
}
