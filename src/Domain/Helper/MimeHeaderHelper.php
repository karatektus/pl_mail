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

        return null === $decoded ? $value : $decoded;
    }
}
