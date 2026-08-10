<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * Canonical form for RFC 5322 Message-IDs: trimmed, angle brackets stripped.
 *
 * The two sync paths disagree at the source — webklex strips the brackets,
 * the raw Gmail API header keeps them — so every write and every comparison
 * (Gmailify dedup, IMAP claim, threading) must go through this or
 * "<x@host>" and "x@host" silently count as different messages.
 */
final class MessageIdHelper
{
    public static function normalise(string $raw): string
    {
        return trim(trim($raw), '<> ');
    }

    /**
     * A fresh RFC 5322 Message-ID for a message this installation is sending,
     * in canonical (bracket-less) form.
     *
     * Symfony mints one of these too, and that is exactly the problem this
     * exists to solve: it does it inside getPreparedHeaders(), which works on a
     * *clone* of the header set, so the id never reaches the Email object.
     * Serialising the same Email twice — once for the SMTP transport, once for
     * the IMAP APPEND into Sent — therefore produced two different ids for one
     * message, and the row we had already stored had no id at all. Minting it
     * here, up front, is what lets all three copies agree on one identity.
     *
     * @param string $sender the From address; its domain is the right-hand side,
     *                       so ids we emit are attributable to the sending host
     */
    public static function mint(string $sender): string
    {
        $at     = strrpos($sender, '@');
        $domain = false === $at ? '' : trim(substr($sender, $at + 1));

        // No usable domain is not an error worth refusing a send over — the
        // left-hand side is random enough to stay unique on its own.
        if ('' === $domain) {
            $domain = 'localhost';
        }

        return bin2hex(random_bytes(16)) . '@' . $domain;
    }

    /**
     * Splits a raw In-Reply-To / References header into canonical message-ids.
     *
     * Splits on any whitespace rather than a single space: these headers are
     * routinely folded across lines, so a CRLF sits between ids as often as a
     * space does. Empty results are dropped so callers never have to re-check.
     *
     * @param string|list<string>|null $raw the raw header, or an already-split list
     *
     * @return list<string>
     */
    public static function normaliseList(string|array|null $raw): array
    {
        if (null === $raw) {
            return [];
        }

        $parts = is_array($raw)
            ? $raw
            : (preg_split('/\s+/', trim($raw)) ?: []);

        $ids = [];

        foreach ($parts as $part) {
            if (false === is_string($part)) {
                continue;
            }

            $id = self::normalise($part);

            if ('' !== $id) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
