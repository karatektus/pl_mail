<?php

declare(strict_types=1);

namespace App\Service\Imap;

/**
 * A label's name as an IMAP keyword, and back (#221 step 2).
 *
 * A keyword is an atom (RFC 9051 §9, `flag-keyword`): printable ASCII without
 * space, parentheses, braces, `%`, `*`, `"`, `\` or `]`. A label is any text a
 * person typed — "Follow up", "Rechnungen 2025", "Café". So the mapping has to
 * be exact both ways, or a label read back from the server would come back as a
 * different label and the next write would add a second keyword for it.
 *
 * ## The encoding
 *
 * The one IMAP already uses for names that are not atoms: modified UTF-7
 * (RFC 3501 §5.1.3), with one change — every character an atom cannot hold is
 * encoded, not only non-ASCII. Printable ASCII that an atom can hold goes
 * through as it is, so a plain label ("Work", "Invoices/2025") is the same word
 * on the server that Thunderbird or Roundcube would have written, and a keyword
 * those clients set ("Work") reads back as the label "Work". `&` becomes `&-`;
 * anything else becomes a `&…-` run of modified base64 over UTF-16, exactly as
 * in a folder name. Nested labels keep their `/`.
 *
 * Decoding is written here rather than handed to mbstring: mbstring's decoder
 * refuses encoded printable ASCII (RFC 3501 says a sender must not produce it),
 * and producing it is the whole point for a space.
 *
 * ## What is never a label
 *
 * A keyword starting with `$` is a registered or client keyword — `$Junk`,
 * `$NotJunk`, `$Forwarded`, `$MDNSent`, `$Phishing`, `$Important`, Thunderbird's
 * `$label1`…`$label5` — and so are a few spelt without the `$` by older clients
 * (`Junk`, `NonJunk`, `Forwarded`, …). decode() answers null for all of them, so
 * none ever turns into a user label. A label whose own name starts with `$` is
 * encoded with that `$` in a `&…-` run, so the two can never collide.
 */
final class ImapKeyword
{
    /** Keywords other clients set without the `$`, compared case-insensitively. */
    private const array UNPREFIXED_SYSTEM = [
        'junk', 'nonjunk', 'notjunk', 'forwarded', 'mdnsent', 'phishing', 'redirected', 'submitpending', 'submitted',
    ];

    /**
     * The system flags without their backslash, as some stored rows carry them:
     * flags written before plMail's own IMAP client came through webklex, which
     * dropped it on some servers.
     */
    private const array BARE_SYSTEM = ['seen', 'answered', 'flagged', 'deleted', 'draft', 'recent'];

    /** atom-specials (RFC 9051) plus `&`, the shift character. */
    private const string NOT_IN_ATOM = '(){%*"\\]&';

    /**
     * The keyword for a label's full name ("Parent/Child").
     */
    public static function encode(string $labelFullName): string
    {
        $out = '';
        $run = '';

        // A label that is spelt like a system keyword or a bare system flag
        // ("Junk", "Seen") has its first letter encoded, so it reads back as
        // itself and never as the flag.
        $lower      = strtolower($labelFullName);
        $guardFirst = in_array($lower, self::UNPREFIXED_SYSTEM, true) || in_array($lower, self::BARE_SYSTEM, true);

        foreach (mb_str_split($labelFullName) as $index => $char) {
            $direct = 1 === strlen($char)
                && ord($char) > 0x20 && ord($char) < 0x7F
                && false === str_contains(self::NOT_IN_ATOM, $char)
                // A leading `$` would read as a system keyword.
                && !(0 === $index && ('$' === $char || true === $guardFirst));

            if (true === $direct) {
                $out .= self::flush($run) . $char;
                $run  = '';

                continue;
            }

            if ('&' === $char) {
                $out .= self::flush($run) . '&-';
                $run  = '';

                continue;
            }

            $run .= $char;
        }

        return $out . self::flush($run);
    }

    /**
     * The label full name a keyword stands for, or null when it is not one of
     * ours to read as a label: a system keyword, or not well-formed.
     */
    public static function decode(string $keyword): ?string
    {
        if ('' === $keyword || '$' === $keyword[0] || '\\' === $keyword[0]) {
            return null;
        }

        if (true === in_array(strtolower($keyword), self::UNPREFIXED_SYSTEM, true)) {
            return null;
        }

        $out    = '';
        $length = strlen($keyword);

        for ($i = 0; $i < $length; $i++) {
            $char = $keyword[$i];

            if ('&' !== $char) {
                $out .= $char;

                continue;
            }

            $end = strpos($keyword, '-', $i + 1);

            if (false === $end) {
                return null;
            }

            $chunk = substr($keyword, $i + 1, $end - $i - 1);
            $i     = $end;

            if ('' === $chunk) {
                $out .= '&';

                continue;
            }

            $bytes = base64_decode(strtr($chunk, ',', '/'), true);

            if (false === $bytes || 0 !== strlen($bytes) % 2) {
                return null;
            }

            $decoded = mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');

            if (false === mb_check_encoding($decoded, 'UTF-8')) {
                return null;
            }

            $out .= $decoded;
        }

        return '' === trim($out) ? null : $out;
    }

    /**
     * Whether a flag as the server listed it is a keyword at all, rather than
     * a system flag (`\Seen`) — rows from before plMail's own IMAP client may
     * carry them without the backslash, so the known system flags are named.
     */
    public static function isKeyword(string $flag): bool
    {
        if ('' === $flag || '\\' === $flag[0]) {
            return false;
        }

        return false === in_array(strtolower($flag), self::BARE_SYSTEM, true);
    }

    private static function flush(string $run): string
    {
        if ('' === $run) {
            return '';
        }

        $utf16 = mb_convert_encoding($run, 'UTF-16BE', 'UTF-8');

        return '&' . strtr(rtrim(base64_encode($utf16), '='), '/', ',') . '-';
    }
}
