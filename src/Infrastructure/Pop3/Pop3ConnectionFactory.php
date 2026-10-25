<?php

declare(strict_types=1);

namespace App\Infrastructure\Pop3;

use App\Entity\Mail\Account;
use App\Service\Imap\ImapDeadline;

/**
 * Opens a POP3 session for an account, the way ImapConnectionFactory opens an
 * IMAP one.
 *
 * The account's incoming server lives in the imap* columns whichever protocol
 * it speaks — see Account::$imapHost — so this is where those columns are read
 * for POP3, once, instead of at every caller.
 */
class Pop3ConnectionFactory
{
    public function __construct(private readonly ImapDeadline $deadline)
    {
    }

    /**
     * @throws Pop3Exception when the server cannot be reached or refuses the login
     */
    public function open(Account $account): Pop3Session
    {
        return Pop3Connection::open(
            (string) $account->imapHost,
            (int) $account->imapPort,
            $account->imapEncryption,
            (string) $account->username,
            (string) $account->password,
            $this->deadline,
        );
    }
}
