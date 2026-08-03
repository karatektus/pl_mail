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
        // the whitespace between them a separator rather than content, and
        // that only holds if iconv sees them together.
        $decoded = preg_replace_callback(
            '/=\?[^?]+\?[BbQq]\?[^?]*\?=(?:\s+=\?[^?]+\?[BbQq]\?[^?]*\?=)*/',
            static function (array $match): string {
                $run = iconv_mime_decode($match[0], ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

                return false === $run ? $match[0] : $run;
            },
            $value,
        );

        // No second guard on the way out, and that is checked rather than
        // assumed: iconv_mime_decode() will not emit invalid UTF-8. Given a
        // charset it does not know, or bytes that do not match the charset the
        // word claims (a mislabelled `=?UTF-8?B?` carrying latin-1, a truncated
        // multi-byte sequence), it declines and returns the encoded word as it
        // found it — which is ASCII, and so already safe.
        return null === $decoded ? $value : $decoded;
    }
}
