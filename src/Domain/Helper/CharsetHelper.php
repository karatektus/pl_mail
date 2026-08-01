<?php

declare(strict_types=1);

namespace App\Domain\Helper;

use ValueError;

/**
 * Gets bytes off the wire and into a UTF-8 column without losing the message.
 *
 * Every text column in this database is UTF-8, and Postgres does not coerce:
 * a single 0xFC in a subject or a body does not arrive mangled, it fails the
 * INSERT — `invalid byte sequence for encoding "UTF8"` — and takes whatever
 * else was in that unit of work with it. So the failure mode this class exists
 * for is not mojibake, it is mail that silently never appeared.
 *
 * Mail is full of 8-bit bytes that are not UTF-8: a part declaring
 * `charset=ISO-8859-1`, a header that never bothered with RFC 2047 at all, a
 * filename copied out of a Windows client. Each of those is a different route
 * to the same rejected INSERT, and each ends here.
 */
final class CharsetHelper
{
    /**
     * The encoding anything 8-bit is read as when nothing usable was declared
     * — deliberately windows-1252, and never iso-8859-1.
     *
     * The two agree on every byte except 0x80–0x9F, which latin-1 defines as
     * C1 control characters that nothing sends on purpose and cp1252 fills
     * with the punctuation Windows mail clients actually emit: curly quotes,
     * en and em dashes, the ellipsis. Mail carrying bytes in that range is
     * therefore cp1252 whatever its label says, so reading it as cp1252 is
     * right for the mislabelled case and byte-identical for the honest one.
     * Choosing true latin-1 is only ever worse: it turns a quotation mark
     * into an invisible control character.
     */
    private const string FALLBACK = 'windows-1252';

    /**
     * Charset labels that are not taken at their word, for the reason above.
     *
     * us-ascii is in here on the same principle. A part that declares ASCII
     * and carries 8-bit bytes is lying, and read literally mbstring replaces
     * every one of those bytes with '?' — so the label costs data it did not
     * have to. Read as cp1252 it is unchanged for genuine ASCII, which is
     * what the declaration promised anyway.
     *
     * @var list<string>
     */
    private const array READ_AS_FALLBACK = [
        'iso-8859-1', 'iso8859-1', 'iso_8859-1', 'iso-ir-100', 'latin1', 'latin-1', 'l1', 'cp819', '8859-1',
        'us-ascii', 'ascii', 'ansi_x3.4-1968', 'iso646-us',
    ];

    /**
     * Pull the charset parameter out of a Content-Type header value.
     *
     * Returns null rather than a default when there is none: "undeclared" and
     * "declared as X" are different situations and toUtf8() treats them
     * differently.
     */
    public static function charsetFromContentType(?string $contentType): ?string
    {
        if (null === $contentType) {
            return null;
        }

        // The quotes are optional in RFC 2045 and both spellings turn up.
        if (1 !== preg_match('/;\s*charset\s*=\s*"?([^";\s]+)"?/i', $contentType, $match)) {
            return null;
        }

        $charset = trim($match[1]);

        return '' !== $charset ? $charset : null;
    }

    /**
     * Convert a part's bytes to UTF-8 using the charset it declared.
     *
     * The declaration is honoured rather than second-guessed — a body that
     * says iso-8859-1 and contains UTF-8 does exist, but guessing costs more
     * than it saves and is unfixable once wrong. What is not honoured is a
     * label mbstring cannot use: an unknown charset name makes
     * mb_convert_encoding() throw a ValueError, and a bogus label is no reason
     * to lose a message, so it degrades to the same treatment as no label at
     * all.
     */
    public static function toUtf8(string $bytes, ?string $charset): string
    {
        $charset = strtolower(trim((string) $charset, " \t\n\r\0\x0B\"'"));

        if ('' === $charset) {
            return self::ensureUtf8($bytes);
        }

        if (true === in_array($charset, self::READ_AS_FALLBACK, true)) {
            $charset = self::FALLBACK;
        }

        // Overwhelmingly the common case, and converting UTF-8 to itself is
        // work for nothing — but a part that says UTF-8 is not always telling
        // the truth either, so it still goes past the guard.
        if ('utf-8' === $charset || 'utf8' === $charset) {
            return self::ensureUtf8($bytes);
        }

        try {
            $converted = mb_convert_encoding($bytes, 'UTF-8', $charset);
        } catch (ValueError) {
            return self::ensureUtf8($bytes);
        }

        return self::ensureUtf8((string) $converted);
    }

    /**
     * The last thing between a string and a UTF-8 column.
     *
     * Valid UTF-8 is returned untouched, which is nearly everything and costs
     * one scan. Anything else is read as cp1252, the only 8-bit encoding a
     * value with no declaration is likely to be. That conversion cannot fail
     * and cannot drop anything: mbstring gives all 256 byte values a mapping,
     * filling the five positions the standard leaves undefined with their C1
     * control characters. So the return is unconditionally storable, which is
     * the entire contract.
     */
    public static function ensureUtf8(string $value): string
    {
        if (true === mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return (string) mb_convert_encoding($value, 'UTF-8', self::FALLBACK);
    }
}
