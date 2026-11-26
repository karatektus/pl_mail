<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

use App\Domain\Helper\AddressHelper;

/**
 * A `mailto:` link, read as the message it asks the composer to open on.
 *
 * The grammar is RFC 6068's and is smaller than it looks: everything between
 * the scheme and a `?` is a comma-separated list of addresses, everything after
 * it is `name=value` pairs joined by `&`. Of the header names a link may carry,
 * five are acted on — `to`, `cc`, `bcc`, `subject` and `body` — and the rest are
 * ignored, for the reason every other mail client ignores them: a link that can
 * set `From`, `Reply-To` or `In-Reply-To` is a link that can compose mail in
 * somebody else's name, or into somebody else's conversation, from a page the
 * reader merely visited.
 *
 * The same rules as the Android and iOS parsers (Mailto.kt, Mailto.swift), so
 * one link opens the same draft on every client. In particular:
 *
 * - **`+` is a plus sign, never a space.** RFC 6068 percent-encodes and says
 *   nothing about form encoding. `anna+lists@example.org` is how a good part
 *   of this app's audience filters its own mail, and urldecode() would hand
 *   back an address with a space in it, which is then dropped — a recipient
 *   silently gone. rawurldecode() leaves `+` alone, and keeps a `%` that is not
 *   an escape as itself rather than failing.
 * - **Split, then decode.** A `%2C` inside a quoted display name is content;
 *   decoding first would turn it into a separator and cut one recipient in two.
 * - **Header names are case-insensitive.** Links are written by hand, and
 *   `?Subject=` is at least as common as `?subject=`.
 *
 * Addresses that are not addresses are dropped rather than refused: a link is
 * somebody else's HTML, and a composer that opens with the good half of it is
 * more use than one that does not open.
 */
final readonly class MailtoLink
{
    private const string SCHEME = 'mailto:';

    /**
     * Per field. Far beyond any real link, and what stops a crafted one from
     * filling the composer — and the contact rows DraftAddressFields creates
     * for each recipient — with thousands of entries.
     */
    private const int MAX_RECIPIENTS = 50;

    /** RFC 5322's line limit; a subject longer than that is not one. */
    private const int MAX_SUBJECT_LENGTH = 998;

    /** A long letter. Enough for any template a link is used to carry. */
    private const int MAX_BODY_LENGTH = 100_000;

    /**
     * @param list<array{name: ?string, address: string}> $to
     * @param list<array{name: ?string, address: string}> $cc
     * @param list<array{name: ?string, address: string}> $bcc
     */
    public function __construct(
        public array  $to,
        public array  $cc,
        public array  $bcc,
        public string $subject,
        public string $body,
    ) {}

    /** The message this link asks for, or null when it is not a `mailto:` at all. */
    public static function parse(?string $uri): ?self
    {
        $text = trim((string) $uri);

        if (0 !== strncasecmp($text, self::SCHEME, strlen(self::SCHEME))) {
            return null;
        }

        $rest  = substr($text, strlen(self::SCHEME));
        $mark  = strpos($rest, '?');
        $head  = false === $mark ? $rest : substr($rest, 0, $mark);
        $query = false === $mark ? '' : substr($rest, $mark + 1);

        // `mailto:` with nothing after it is a legal link and means "a blank
        // message", which is what empty lists produce here.
        $to      = self::addresses($head);
        $cc      = [];
        $bcc     = [];
        $subject = '';
        $body    = '';

        foreach (explode('&', $query) as $field) {
            if ('' === $field) {
                continue;
            }

            $equals = strpos($field, '=');
            $name   = mb_strtolower(trim(self::decode(false === $equals ? $field : substr($field, 0, $equals))));
            $value  = false === $equals ? '' : substr($field, $equals + 1);

            // Added to the ones before the `?` rather than replacing them:
            // RFC 6068 allows both spellings, and a link may use either or both.
            match ($name) {
                'to'      => $to = [...$to, ...self::addresses($value)],
                'cc'      => $cc = [...$cc, ...self::addresses($value)],
                'bcc'     => $bcc = [...$bcc, ...self::addresses($value)],
                'subject' => $subject = self::decode($value),
                'body'    => $body = self::decode($value),
                default   => null,
            };
        }

        return new self(
            array_slice(self::unique($to), 0, self::MAX_RECIPIENTS),
            array_slice(self::unique($cc), 0, self::MAX_RECIPIENTS),
            array_slice(self::unique($bcc), 0, self::MAX_RECIPIENTS),
            // A subject is one line; a link that folds one in would otherwise
            // put a newline into a header field the form renders as an input.
            mb_substr(trim((string) preg_replace('/\s+/u', ' ', $subject)), 0, self::MAX_SUBJECT_LENGTH),
            mb_substr(str_replace(["\r\n", "\r"], "\n", $body), 0, self::MAX_BODY_LENGTH),
        );
    }

    /**
     * The body as the editor's HTML: one paragraph per line, escaped, with an
     * empty paragraph standing for an empty line — the same `<p><br></p>` the
     * editor writes itself, so the caret can land in it.
     */
    public function bodyHtml(): string
    {
        if ('' === trim($this->body)) {
            return '';
        }

        $paragraphs = array_map(
            static fn (string $line): string => '' === trim($line)
                ? '<p><br></p>'
                : '<p>' . htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '</p>',
            explode("\n", rtrim($this->body, "\n")),
        );

        return implode('', $paragraphs);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A recipient list, split before it is decoded (see the class docblock).
     *
     * @return list<array{name: ?string, address: string}>
     */
    private static function addresses(string $raw): array
    {
        $found = [];

        foreach (explode(',', $raw) as $piece) {
            $decoded = trim(self::decode($piece));

            if ('' === $decoded || false === AddressHelper::isValidEmail($decoded)) {
                continue;
            }

            // `Anna <anna@example.org>` is not RFC 6068 — the addr-spec alone
            // is — but links carry it, and the name is worth keeping.
            $name = 1 === preg_match('/^(.*)<[^<>]*>\s*$/', $decoded, $match)
                ? AddressHelper::name($match[1])
                : '';

            $found[] = [
                'name'    => '' === $name ? null : mb_substr($name, 0, 255),
                'address' => AddressHelper::email($decoded),
            ];
        }

        return $found;
    }

    /**
     * The same address twice is one recipient.
     *
     * @param list<array{name: ?string, address: string}> $addresses
     *
     * @return list<array{name: ?string, address: string}>
     */
    private static function unique(array $addresses): array
    {
        $seen = [];

        foreach ($addresses as $address) {
            $seen[$address['address']] ??= $address;
        }

        return array_values($seen);
    }

    /**
     * Percent-decoding as RFC 3986 means it.
     *
     * Malformed UTF-8 becomes a substitute character rather than an exception,
     * as in the native parsers: a link from a broken encoder still opens a
     * composer, with one visibly wrong character in it. Not scrubbed, it would
     * reach the form as bytes Twig and the JSON draft column both refuse.
     */
    private static function decode(string $value): string
    {
        return mb_scrub(rawurldecode($value), 'UTF-8');
    }
}
