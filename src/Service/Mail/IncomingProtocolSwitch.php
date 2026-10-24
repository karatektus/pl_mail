<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Account\IncomingProtocol;
use App\Entity\Mail\Account;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\Mail\Pop3UidlRepository;
use Psr\Log\LoggerInterface;

/**
 * An account that changes how its incoming mail is read, IMAP to POP3 or back,
 * keeping the mail it has (#289).
 *
 * The mail on the server does not change when the protocol does; only the
 * names the server gives it do. An IMAP UID means nothing to a POP3 session
 * and a UIDL nothing to an IMAP one, so a switch keeps every row and drops
 * every server address, exactly as a UIDVALIDITY change does
 * (VanishedMessageReconciler::invalidate()). What the next sync then does with
 * the rows it finds is the same in both directions — it recognises them by
 * Message-ID instead of storing them a second time:
 *
 *  - To POP3: the account is flagged as adopting (Account::$pop3Adopting), and
 *    its first POP3 pass records a message whose Message-ID the account
 *    already holds as stored rather than fetching it. The IMAP INBOX row is
 *    the mailbox POP3 files into from then on. Folders other than the inbox
 *    keep their mail and simply stop syncing: POP3 has no folders to sync
 *    them from.
 *  - To IMAP: every folder is unplanned, so it is read like a first one, and
 *    SentCopyReconciler::claim() matches each message to the address-less
 *    row the account already holds for it — the same path a UIDVALIDITY
 *    rebuild takes. Mail POP3 deleted from the server stays here, with no
 *    address, which is where it was all along.
 *
 * A message with no Message-ID cannot be recognised either way and is
 * fetched again. That is the one cost, and it is a duplicate, never a loss.
 *
 * Dropping the addresses is also what keeps the old protocol from being used
 * by anything still queued: flag changes, moves and purges all act on rows
 * with an IMAP UID, and after a switch to POP3 there are none.
 */
final readonly class IncomingProtocolSwitch
{
    public function __construct(
        private MailboxRepository  $mailboxes,
        private MessageRepository  $messages,
        private Pop3UidlRepository $uidls,
        private LoggerInterface    $logger,
    ) {
    }

    /**
     * Bring the account's sync state in line with the protocol it now has.
     *
     * Called with the protocol it had before the change; does nothing when
     * that is the one it still has. The caller flushes.
     */
    public function switched(Account $account, IncomingProtocol $from): void
    {
        $to = $account->incomingProtocol;

        if ($from === $to || true === $account->isGmail() || true === $account->isMicrosoft()) {
            return;
        }

        $unlocated = 0;

        foreach ($this->mailboxes->findBy(['account' => $account]) as $mailbox) {
            $unlocated += $this->messages->unlocateAll($mailbox);

            $mailbox->uidValidity       = null;
            $mailbox->lastSeenUid       = 0;
            $mailbox->sweptAt           = null;
            $mailbox->failedUid         = null;
            $mailbox->failedUidAttempts = 0;

            // To IMAP: unplanned, so each folder is read like a first one and
            // its rows are matched as their pages come in. To POP3: finished,
            // because there is no IMAP import left to do and the tracker would
            // otherwise show one running for ever.
            $mailbox->importFloorUid = $to->isImap() ? null : 0;
        }

        // UIDLs belong to the POP3 server's view of the mailbox, which a
        // switch in either direction makes stale.
        $this->uidls->forget($account);

        $account->pop3Adopting = $to->isPop3();

        $this->logger->info('Account changed incoming protocol; server addresses dropped, mail kept', [
            'accountId' => $account->id,
            'from'      => $from->value,
            'to'        => $to->value,
            'unlocated' => $unlocated,
        ]);
    }
}
