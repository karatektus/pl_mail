<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * Canonical form for the two halves of an RFC 5322 address: the display name
 * and the addr-spec.
 *
 * Every backend hands them over differently. webklex exposes `personal`
 * verbatim, so a quoted-string display name arrives as `"Doe, John"` — quotes
 * and all — and lands in the UI and in `contact.display_name` that way. The
 * Gmail path reads the raw `From:`/`To:` headers, where the quoting is ours to
 * undo, and Graph hands back a JSON `name` that is quoted whenever the mailbox
 * stored it quoted. Normalising in one place is what keeps the same person from
 * reading as three.
 */
final class AddressHelper
{
    /** RFC 5321 §4.5.3.1: 64-octet local part + "@" + 255-octet domain. */
    public const int MAX_EMAIL_LENGTH = 320;

    /**
     * Display name as a human would write it: encoded words decoded, the
     * RFC 5322 quoted-string wrapper removed and its escapes undone.
     *
     * Only a wrapper is stripped — `John "Johnny" Doe` keeps its inner quotes,
     * because those are content rather than syntax.
     */
    public static function name(?string $raw): string
    {
        $name = MimeHeaderHelper::decode(trim((string) $raw));

        // A name can arrive quoted twice over: the encoded word itself may hold
        // the quoted string, so unwrap until there is nothing left to unwrap.
        while (mb_strlen($name) >= 2 && str_starts_with($name, '"') && str_ends_with($name, '"')) {
            $inner = mb_substr($name, 1, -1);

            // A trailing escaped quote (…\") means the closing quote belongs to
            // the content, not to a wrapper. Leave it alone.
            if (str_ends_with($inner, '\\')) {
                break;
            }

            $name = trim(str_replace(['\\"', '\\\\'], ['"', '\\'], $inner));
        }

        // Folded headers leave newlines and runs of spaces mid-name.
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * Canonical addr-spec: angle brackets and stray quoting removed, lowercased.
     *
     * Lowercasing the local part is technically lossy — RFC 5321 §2.3.11 leaves
     * its case to the receiving host — but every dedup key in the app (contact
     * uniqueness, own-address detection, alias matching) already compares
     * case-insensitively, so a mixed-case spelling only ever produced a
     * duplicate row.
     */
    public static function email(?string $raw): string
    {
        $email = trim((string) $raw);

        // "Name <a@b>" reaches this from paths that never split the parts.
        if (1 === preg_match('/<([^<>]*)>\s*$/', $email, $match)) {
            $email = $match[1];
        }

        return mb_strtolower(trim($email, " \t\r\n\"'<>"));
    }

    /**
     * Whether the canonical form is an address worth storing.
     *
     * `filter_var` is deliberately stricter than the sync paths are: a header
     * that failed to parse yields fragments like `"Doe` or an empty local part,
     * and those must not become contacts.
     */
    public static function isValidEmail(?string $raw): bool
    {
        $email = self::email($raw);

        return '' !== $email
            && strlen($email) <= self::MAX_EMAIL_LENGTH
            && false !== filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    /**
     * Splits an address list header on the commas that actually separate
     * addresses.
     *
     * A comma inside a quoted display name (`"Doe, John" <j@example.com>`) or
     * inside angle brackets is content. Splitting on every comma turned that
     * one address into `"Doe` and `John" <j@example.com>`, which is where the
     * stray-quote addresses came from.
     *
     * @return list<string> non-empty, trimmed address fragments
     */
    public static function splitList(string $raw): array
    {
        $parts    = [];
        $current  = '';
        $inQuotes = false;
        $depth    = 0;
        $length   = strlen($raw);

        for ($i = 0; $i < $length; $i++) {
            $char = $raw[$i];

            if ('\\' === $char && true === $inQuotes && $i + 1 < $length) {
                $current .= $char . $raw[$i + 1];
                $i++;
                continue;
            }

            if ('"' === $char) {
                $inQuotes = false === $inQuotes;
            } elseif (false === $inQuotes && '<' === $char) {
                $depth++;
            } elseif (false === $inQuotes && '>' === $char) {
                $depth = max(0, $depth - 1);
            } elseif (',' === $char && false === $inQuotes && 0 === $depth) {
                $parts[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $parts[] = $current;

        return array_values(array_filter(
            array_map('trim', $parts),
            static fn (string $part): bool => '' !== $part,
        ));
    }
}
