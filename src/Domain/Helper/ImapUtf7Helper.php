<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * Modified UTF-7 (RFC 3501 §5.1.3), the encoding IMAP mailbox names travel in.
 *
 * `Entwürfe` is `Entw&APw-rfe` on the wire; `Gelöschte Objekte` is
 * `Gel&APY-schte Objekte`.
 *
 * This is for display and nothing else. The encoded form is the folder's
 * identity in the protocol — it is what SELECT takes, and it is therefore what
 * Mailbox::$fullPath stores and what MailboxRepository indexes by. Decoding a
 * value on its way to a server, or on its way into that column, breaks folder
 * selection against every server that has a non-ASCII folder. Only names a
 * person reads go through here.
 */
final class ImapUtf7Helper
{
    /**
     * Decode one mailbox name segment, or hand it back untouched.
     *
     * The guard is a round trip rather than a syntax check because mbstring's
     * decoder reports nothing at all: it silently substitutes '?' for whatever
     * it cannot read, so a server sending a bare '&' where the encoding wants
     * '&-' turns "R&D" into "R?" with no error and no way to distinguish that
     * from a folder genuinely called "R?". Re-encoding the result and
     * comparing is exact — well-formed modified UTF-7 reproduces itself byte
     * for byte, and anything that does not is kept verbatim, which is at worst
     * what this code did before.
     */
    public static function decode(string $segment): string
    {
        // '&' is the encoding's only shift character, so a segment without one
        // already is its own decoding. That is every ASCII folder name there
        // is, which is nearly all of them, and skipping the conversion keeps
        // them exactly byte-for-byte rather than merely probably so.
        if (false === str_contains($segment, '&')) {
            return $segment;
        }

        $decoded = (string) mb_convert_encoding($segment, 'UTF-8', 'UTF7-IMAP');

        return $segment === mb_convert_encoding($decoded, 'UTF7-IMAP', 'UTF-8')
            ? $decoded
            : $segment;
    }
}
