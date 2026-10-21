<?php

declare(strict_types=1);

namespace App\Domain\Enum\Account;

/**
 * How an account's incoming mail is read.
 *
 * The two protocols are not variants of one thing and this enum is the place
 * that says so. IMAP names messages by UID, keeps them in folders and lets the
 * client change what is on the server; POP3 numbers messages by position, has
 * exactly one mailbox, and the only destructive thing it can do is delete.
 * Everything downstream — the syncer, the folder listing, the flag
 * reconciler, the Sent copy — reads this and takes a different road or none
 * at all.
 *
 * Stored in its own column rather than inferred from the host. Misk is the
 * account that made this a column: it serves both protocols on the same
 * domain, so there is nothing about `mail.example.com:995` that says which one
 * is on the other end.
 */
enum IncomingProtocol: string
{
    case Imap = 'imap';
    case Pop3 = 'pop3';

    /**
     * Whether the provider's server can describe a folder tree, hold flags and
     * accept changes pushed back at it.
     *
     * The whole of what "plain IMAP" was used for before this enum existed.
     * POP3 has none of it, which is why so many call sites read this rather
     * than the protocol itself.
     */
    public function isImap(): bool
    {
        return self::Imap === $this;
    }

    public function isPop3(): bool
    {
        return self::Pop3 === $this;
    }

    /**
     * The port clients use when the form has not been told one.
     *
     * POP3's only encrypted port. There is no plaintext equivalent offered:
     * the form's encryption choices are the same list for both protocols and
     * "None" is what a server on 110 would need, so that stays available —
     * this is a default, not a restriction.
     */
    public function defaultPort(): int
    {
        return self::Pop3 === $this ? 995 : 993;
    }
}
