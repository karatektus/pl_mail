<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\DTO\Mail\ServerCopy;
use App\Entity\Mail\Message;

/**
 * Every place an IMAP server keeps this message: its locations (#415).
 *
 * An email has as many copies as folders that hold it — INBOX and All Mail,
 * a folder and a copy another client made. Keywords are per copy on IMAP,
 * like every flag, so a label written as a keyword goes to each of them.
 *
 * The keyword code asks here and writes to whatever comes back, and never
 * reads `$message->mailbox` / `$message->imapUid` itself: those name one copy
 * only, the primary.
 *
 * A copy whose UID the server has not reported yet cannot be addressed, and is
 * left out.
 */
final class MessageCopies
{
    /**
     * @return list<ServerCopy>
     */
    public static function of(Message $message): array
    {
        $copies = [];

        foreach ($message->locations as $location) {
            if (null !== $location->imapUid) {
                $copies[] = new ServerCopy($location->mailbox, $location->imapUid);
            }
        }

        return $copies;
    }
}
