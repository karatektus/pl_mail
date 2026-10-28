<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\DTO\Mail\ServerCopy;
use App\Entity\Mail\Message;

/**
 * Every place an IMAP server keeps this message — the seam for #415.
 *
 * Today a Message row IS one server copy: one Mailbox, one UID, and an email
 * kept in two folders is two rows. karatektus/pl_mail#415 separates the two
 * (Message + MessageLocation), and an email then has as many copies as folders
 * that hold it. Keywords are per copy on IMAP, like every flag, so a label
 * written as a keyword has to go to each of them.
 *
 * So the keyword code asks here and writes to whatever comes back, and never
 * reads `$message->mailbox` / `$message->imapUid` itself. When #415 lands, this
 * is the one method that changes: from the row's own address to its locations.
 *
 * A row waiting for its UID after a move has no copy anyone can address yet,
 * and answers none.
 */
final class MessageCopies
{
    /**
     * @return list<ServerCopy>
     */
    public static function of(Message $message): array
    {
        $mailbox = $message->mailbox;
        $uid     = $message->imapUid;

        if (null === $mailbox || null === $uid) {
            return [];
        }

        return [new ServerCopy($mailbox, $uid)];
    }
}
