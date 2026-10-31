<?php

declare(strict_types=1);

namespace App\Service\JmapClient;

use App\Domain\Enum\Mail\MessageFlag;

/**
 * JMAP keywords and IMAP flags, which are the same four things spelled twice.
 *
 * RFC 8621 §4.1.1 defines `$seen`, `$flagged`, `$answered` and `$draft` as the
 * keyword forms of `\Seen`, `\Flagged`, `\Answered` and `\Draft`, and a server
 * that speaks both protocols (Stalwart, Cyrus) stores one set and shows it
 * both ways. So the row's flags mirror is kept in IMAP's spelling here as for
 * every other provider — ThreadStatusUpdater and MessageFlag::canonicalList()
 * compare against it — and translated at the edge in both directions.
 *
 * A keyword with no IMAP twin (`$forwarded`, `$junk`, a user's own tag) is
 * kept verbatim in the mirror, as an IMAP server's custom flags are: the
 * mirror is a copy of what the server says, not of what plMail understands.
 */
final class JmapKeywords
{
    /** @var array<string, MessageFlag> */
    private const array SYSTEM = [
        '$seen'     => MessageFlag::SEEN,
        '$flagged'  => MessageFlag::FLAGGED,
        '$answered' => MessageFlag::ANSWERED,
        '$draft'    => MessageFlag::DRAFT,
    ];

    /**
     * The flags a keyword set reads as, in IMAP's spelling.
     *
     * @param array<string, mixed> $keywords the Email's `keywords`: keyword => true
     *
     * @return list<string>
     */
    public static function toFlags(array $keywords): array
    {
        $flags = [];

        foreach ($keywords as $keyword => $set) {
            if (true !== $set) {
                continue;
            }

            $lower   = strtolower((string) $keyword);
            $flags[] = true === array_key_exists($lower, self::SYSTEM) ? self::SYSTEM[$lower]->value : (string) $keyword;
        }

        return MessageFlag::canonicalList($flags);
    }

    /**
     * The keyword an IMAP flag is written as, or null for one JMAP has no
     * keyword for — `\Recent` and `\Deleted` are session state in IMAP and
     * have no place on a JMAP Email.
     */
    public static function fromFlag(MessageFlag $flag): ?string
    {
        foreach (self::SYSTEM as $keyword => $system) {
            if ($system === $flag) {
                return $keyword;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $keywords
     */
    public static function has(array $keywords, MessageFlag $flag): bool
    {
        $keyword = self::fromFlag($flag);

        if (null === $keyword) {
            return false;
        }

        foreach ($keywords as $name => $set) {
            if (true === $set && $keyword === strtolower((string) $name)) {
                return true;
            }
        }

        return false;
    }
}
