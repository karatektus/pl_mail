<?php

declare(strict_types=1);

namespace App\Domain\Helper;

use App\Entity\Mail\Account;

/**
 * Where an account's stored password is allowed to go.
 *
 * A blank password field on the edit form and in the connection test means
 * "keep the stored one". That was true whatever server the same request named,
 * so whoever held the session (a stolen remember-me cookie is enough) could
 * point the test, or a saved edit, at a server of their own and receive the
 * mailbox password in clear text. The stored password now only travels to the
 * servers it was typed for: change any of them and the password has to be
 * typed again.
 *
 * Every field that decides where a sign-in goes is part of it, encryption
 * included — "none" on the same host and port sends the password in clear.
 * Hosts compare without case, because DNS does.
 */
final class StoredSecretTarget
{
    public static function of(Account $account): string
    {
        return implode("\n", [
            $account->incomingProtocol->value,
            strtolower(trim((string) $account->imapHost)),
            self::port($account->imapPort),
            strtolower((string) $account->imapEncryption),
            strtolower(trim((string) $account->smtpHost)),
            self::port($account->smtpPort),
            strtolower((string) $account->smtpEncryption),
            trim((string) $account->jmapSessionUrl),
        ]);
    }

    /** Empty and 0 both mean "the default port", on the form and in the test. */
    private static function port(?int $port): string
    {
        if (null === $port || 0 === $port) {
            return '';
        }

        return (string) $port;
    }

    public static function same(Account $stored, Account $requested): bool
    {
        return self::of($stored) === self::of($requested);
    }
}
