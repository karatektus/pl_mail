<?php

declare(strict_types=1);

namespace App\Domain\Helper;

use Symfony\Component\Mime\Email;

/**
 * The raw MIME an HTTP send API is handed, Bcc header included.
 *
 * Email::toString() is the wrong serialisation for these senders, and the
 * reason is one line in Symfony: Message::getPreparedHeaders() removes Bcc,
 * because on SMTP the blind recipients travel in the envelope (RCPT TO) and
 * must never be written into the message itself. Gmail messages.send and
 * Graph sendMail have no envelope — both read the recipients out of the MIME
 * they are given — so the Bcc addresses were simply dropped: the mail left
 * without them and nothing said so.
 *
 * Both providers document the Bcc header as the way to address blind
 * recipients in a raw send, and both strip it before delivery, so writing it
 * here does not disclose anything to the visible recipients.
 */
final class ApiMime
{
    public static function toString(Email $email): string
    {
        $headers = $email->getPreparedHeaders();
        $bcc     = $email->getBcc();

        if ([] !== $bcc) {
            $headers->addMailboxListHeader('Bcc', $bcc);
        }

        return $headers->toString() . $email->getBody()->toString();
    }
}
