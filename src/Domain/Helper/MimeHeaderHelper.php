<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * RFC 2047 header decoding that does not eat the characters it cannot name.
 */
final class MimeHeaderHelper
{
    /**
     * Decodes encoded words, leaving the rest of the header byte for byte.
     *
     * iconv_mime_decode() must not be pointed at a whole header value: headers
     * are specified as ASCII, so it silently drops every raw 8-bit byte it
     * meets — "Jörg Müller" comes back as "Jrg Mller". Servers send raw UTF-8
     * headers anyway, and webklex/php-imap has usually decoded the encoded
     * words before we ever see them, so that stripping ran over every synced
     * subject and sender name and took every umlaut with it.
     *
     * Decoding only the encoded-word runs keeps both shapes intact, including
     * a header that mixes them.
     */
    public static function decode(string $value): string
    {
        // Raw 8-bit bytes are normalised BEFORE the encoded words, never
        // after, and this is the only order that works. An encoded word is
        // ASCII by construction (RFC 2047 §2), so running the guard first
        // cannot reach inside one — whereas running it last would meet a
        // header carrying both shapes, find a correctly decoded "Jörg" sitting
        // beside a raw 0xFC that never went through RFC 2047 at all, and read
        // the whole string as cp1252 to rescue the one byte, turning the half
        // that was already right into mojibake.
        //
        // It is also before the early return below, deliberately: the header
        // that needs this most has no encoded word in it. Non-conforming raw
        // 8-bit headers are routine from older German mail systems and from
        // mailing list software, they declare no charset anywhere, and webklex
        // passes them through untouched — so "Gr\xFC\xDFe von J\xF6rg" used to
        // reach $subject as invalid UTF-8 and be rejected by Postgres,
        // losing the message rather than mangling it.
        $value = CharsetHelper::ensureUtf8($value);

        if (false === str_contains($value, '=?')) {
            return $value;
        }

        // Adjacent encoded words are decoded as one run: RFC 2047 §6.2 makes
        // the whitespace between them a separator rather than content, so the
        // pieces are joined with nothing between them.
        $decoded = preg_replace_callback(
            '/=\?[^?]+\?[BbQq]\?[^?]*\?=(?:\s+=\?[^?]+\?[BbQq]\?[^?]*\?=)*/',
            static fn (array $match): string => self::run($match[0]),
            $value,
        );

        // No second guard on the way out, and that is checked rather than
        // assumed: every path through run() ends in CharsetHelper, which
        // cannot return invalid UTF-8, or returns the encoded word as it found
        // it — which is ASCII, and so already safe.
        return null === $decoded ? $value : $decoded;
    }

    /**
     * One or more adjacent encoded words, decoded and concatenated.
     *
     * Each word is decoded individually rather than by handing the run to
     * iconv_mime_decode(), because only the individual payload can be checked
     * against the charset the word claims. Senders mislabel these exactly as
     * they mislabel bodies — `=?ISO-8859-1?Q?` around UTF-8 bytes — and iconv
     * has no choice but to believe the label, which is what turned a subject
     * into "GrÃ¼ÃŸe". CharsetHelper::toUtf8() does have that choice.
     *
     * Anything that does not decode cleanly is left as it was found. A word
     * whose base64 is truncated, or whose charset nothing recognises, is not
     * worth guessing at, and the encoded form is at least readable ASCII.
     */
    private static function run(string $run): string
    {
        if (1 > preg_match_all('/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/', $run, $words, PREG_SET_ORDER)) {
            return $run;
        }

        $out = '';

        foreach ($words as $word) {
            [$whole, $charset, $encoding, $payload] = $word;

            $bytes = 'b' === strtolower($encoding)
                ? base64_decode($payload, true)
                // RFC 2047 §4.2: underscore is a space, everywhere in the word.
                : quoted_printable_decode(str_replace('_', ' ', $payload));

            if (false === $bytes) {
                $out .= $whole;

                continue;
            }

            $out .= CharsetHelper::toUtf8($bytes, $charset);
        }

        return $out;
    }
}
