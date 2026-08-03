<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Mail\Message;

/**
 * Reconstructs an RFC822-shaped source for a Message.
 *
 * IMPORTANT: this is a reconstruction, not the original bytes. plMail stores a
 * parsed header map plus decoded bodies, never the raw message, so the output
 * is byte-for-byte faithful to neither the transfer encoding nor the MIME
 * structure it arrived with. Good enough for "show original" and for a JMAP
 * blob download; NOT good enough to verify a DKIM signature against.
 *
 * Shared by MessageSourceController (the web "show original" view) and the
 * JMAP blob download endpoint so the two cannot drift.
 */
final class MessageSourceBuilder
{
    public function build(Message $message): string
    {
        $lines = [];

        foreach ($message->headers ?? [] as $key => $value) {
            foreach (true === is_array($value) ? $value : [$value] as $single) {
                $lines[] = $key.': '.$single;
            }
        }

        $body = $message->bodyText;

        if (null === $body || '' === $body) {
            $body = $message->bodyHtml ?? '';
        }

        return implode("\n", $lines)."\n\n".$body;
    }
}
