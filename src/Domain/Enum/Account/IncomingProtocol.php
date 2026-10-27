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
 * JMAP is the third, and the odd one out in a different way: it is not a
 * socket protocol at all but JSON over HTTPS, and a message is addressed by an
 * id that survives moves, sits in any number of mailboxes at once and carries
 * keywords rather than flags. That is the Gmail model, not the IMAP one, so
 * JMAP answers false to isImap() even though its server has every capability
 * that method describes: every caller of isImap() goes on to open an IMAP
 * connection, and a JMAP account must never reach one.
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
    case Jmap = 'jmap';

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

    public function isJmap(): bool
    {
        return self::Jmap === $this;
    }

    /**
     * The port clients use when the form has not been told one.
     *
     * POP3's only encrypted port. There is no plaintext equivalent offered:
     * the form's encryption choices are the same list for both protocols and
     * "None" is what a server on 110 would need, so that stays available —
     * this is a default, not a restriction.
     *
     * JMAP's is HTTPS's. The session resource is found at a well-known path on
     * the web port, and a server that serves it elsewhere is reached through
     * the session URL override rather than a different port here.
     */
    public function defaultPort(): int
    {
        return match ($this) {
            self::Imap => 993,
            self::Pop3 => 995,
            self::Jmap => 443,
        };
    }
}
