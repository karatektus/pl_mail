<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * Keeps the first of each header a message may carry only once, for the
 * library that cannot read a message that carries one twice.
 *
 * ── The shape ────────────────────────────────────────────────────────────────
 * RFC 5322 allows one Date, one Message-ID, one In-Reply-To and one References
 * per message. Mail that breaks that rule exists — a gateway that adds its own
 * Date beside the sender's, a forwarder that copies a header block in (#219) —
 * and webklex's header parser does not survive it: it collects a repeated
 * header into a list, joins two values with a space and keeps three or more as
 * an array, and then hands that to string functions. Two Date lines become one
 * date with trailing data, which Carbon refuses; three become an array, which
 * preg_match() refuses with a TypeError; repeated References go to explode()
 * the same way. None of those is an exception the library catches for a
 * single message, so one such mail failed its whole page of the sync.
 *
 * ── Why the first ────────────────────────────────────────────────────────────
 * Which of two Dates the sender wrote cannot be told from the header itself,
 * so this does not try: it keeps the first, a choice that is at least the same
 * every time the mail is read. The alternative is not "the right one" — it is
 * the mail not arriving at all. The raw header is not changed by this:
 * ImapQuery parses a repaired copy and puts the original back on the message,
 * so the stored .eml still shows every line the server sent.
 *
 * Static and free of webklex, so a test can state it without a server.
 */
final class RepeatedSingletonHeaders
{
    /**
     * The headers whose repeat the parser cannot take. Lower case, as compared.
     *
     * Only these: the parser reads every other repeated header as a list or a
     * joined string without failing, and a header kept here that the parser
     * could have read would be a line of the message thrown away for nothing.
     */
    private const array SINGLETONS = ['date', 'message-id', 'in-reply-to', 'references'];

    /**
     * The raw header with the second and later occurrences of each singleton
     * removed, continuation lines and all; unchanged when there are none.
     */
    public static function keepFirst(string $rawHeader): string
    {
        // Split after every line break, so each line keeps its own ending and
        // the pieces join back to exactly what came in.
        $lines = preg_split('~(?<=\n)~', $rawHeader, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $seen = [];
        $dropping = false;
        $kept = [];

        foreach ($lines as $line) {
            // A line that starts with whitespace continues the header above it
            // (RFC 5322 folding), and goes wherever that header went.
            if (1 === preg_match('~^[ \t]~', $line)) {
                if (false === $dropping) {
                    $kept[] = $line;
                }

                continue;
            }

            $colon = strpos($line, ':');
            $name = false === $colon ? '' : strtolower(trim(substr($line, 0, $colon)));

            $dropping = in_array($name, self::SINGLETONS, true) && isset($seen[$name]);
            $seen[$name] = true;

            if (false === $dropping) {
                $kept[] = $line;
            }
        }

        return implode('', $kept);
    }
}
