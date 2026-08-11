<?php

namespace App\Domain\Enum\Mail;

enum MessageFlag: string
{
    case ANSWERED = '\\Answered';
    case FLAGGED = '\\Flagged';
    case DELETED = '\\Deleted';
    case SEEN = '\\Seen';
    case DRAFT = '\\Draft';
    case RECENT = '\\Recent';

    /**
     * One flag list in the spelling this enum uses, so that two lists can be
     * compared for meaning rather than for punctuation.
     *
     * The backslash on a system flag is IMAP wire syntax and not part of the
     * name, and servers differ on whether it survives being parsed — webklex
     * hands back `Seen` from some and `\Seen` from others. Without this, a row
     * whose mirror was captured from one server would read as changed against
     * every listing forever, and inbound flag sync would rewrite the whole
     * folder on every pass and log a JMAP change for each row.
     *
     * Unknown flags are kept verbatim: a keyword another client sets is not
     * plMail's to discard, and this enum is the list of flags that mean
     * something here, not the list of flags allowed to exist.
     *
     * \Recent is dropped. It is not a property of a message but of this
     * session's relationship to it — no client can set it, and it is false the
     * moment anybody else looks — so storing it guarantees a spurious
     * difference on the next comparison.
     *
     * Sorted, because two lists that differ only in order say the same thing.
     *
     * @param list<string> $flags
     *
     * @return list<string>
     */
    public static function canonicalList(array $flags): array
    {
        $canonical = [];

        foreach ($flags as $flag) {
            $bare = ltrim($flag, '\\');

            if ('' === $bare) {
                continue;
            }

            $system = self::tryFrom('\\' . ucfirst(strtolower($bare)));

            if (self::RECENT === $system) {
                continue;
            }

            $canonical[] = $system->value ?? $flag;
        }

        $canonical = array_values(array_unique($canonical));

        sort($canonical);

        return $canonical;
    }
}
