<?php

declare(strict_types=1);

namespace App\Infrastructure\ImapClient;

use App\Infrastructure\ImapClient\Exception\ImapCommandException;
use App\Infrastructure\ImapClient\Exception\ImapProtocolException;

/**
 * The IMAP commands plMail uses, as typed calls on one logged-in connection.
 *
 * ImapConnection speaks the protocol; this says what the commands mean and
 * reads their answers into values, so no caller builds a command line or
 * picks a FETCH response apart. Each method is one command, or the short
 * fixed sequence a server without an extension needs in its place (MOVE).
 *
 * THE SELECTED FOLDER
 * ───────────────────
 * Every UID command works on whichever folder is selected, so the folder is
 * a parameter of those methods rather than a state the caller has to keep
 * track of. It is selected when it is not the one selected already; a caller
 * working through one folder pays for one SELECT.
 *
 * UID SETS
 * ────────
 * Passed as strings in IMAP's own syntax (`5:*`, `12,14:20`); uidSet() makes
 * one from a list. They are written as they are, so they must come from
 * numbers, never from outside — which a list of ints guarantees.
 */
final class ImapSession
{
    private ?string $selected = null;

    private bool $selectedReadOnly = false;

    public function __construct(
        public readonly ImapConnection $connection,
    ) {
    }

    /**
     * Every folder, as `LIST "" "*"` reports them.
     *
     * @return list<ImapFolder>
     */
    public function folders(): array
    {
        $result  = $this->connection->command('LIST', ImapArgument::string(''), ImapArgument::string('*'));
        $folders = [];

        foreach ($result->untagged('LIST') as $response) {
            $folder = ImapFolder::fromList($response);

            if (null !== $folder) {
                $folders[] = $folder;
            }
        }

        return $folders;
    }

    /**
     * STATUS of a folder that need not be selected.
     *
     * @param list<string> $items `UIDVALIDITY`, `UIDNEXT`, `MESSAGES`, `UNSEEN`
     *
     * @return array<string, int> by upper-cased item, only those the server answered
     */
    public function status(string $path, array $items): array
    {
        $result = $this->connection->command(
            'STATUS',
            ImapArgument::string($path),
            ImapArgument::list(array_map(ImapArgument::atom(...), $items)),
        );

        $values = [];

        foreach ($result->untagged('STATUS') as $response) {
            $pairs = $response->data[1] ?? null;

            if (false === is_array($pairs)) {
                continue;
            }

            for ($i = 0; $i + 1 < count($pairs); $i += 2) {
                if (true === is_string($pairs[$i]) && true === is_string($pairs[$i + 1]) && 1 === preg_match('~^\d+$~', $pairs[$i + 1])) {
                    $values[strtoupper($pairs[$i])] = (int) $pairs[$i + 1];
                }
            }
        }

        return $values;
    }

    /** Open a folder read-write. Always sends SELECT: the answer is the point. */
    public function select(string $path): ImapMailboxState
    {
        return $this->open($path, false);
    }

    /**
     * Open a folder read-only. EXAMINE never clears \Recent, so measuring a
     * folder does not change it.
     */
    public function examine(string $path): ImapMailboxState
    {
        return $this->open($path, true);
    }

    /**
     * UID SEARCH.
     *
     * @param string $criteria search keys as IMAP writes them: `ALL`, `UID 5:*`
     *
     * @return list<int> ascending
     */
    public function uidSearch(string $path, string $criteria): array
    {
        return $this->search($path, ImapArgument::atom($criteria));
    }

    /**
     * UID SEARCH HEADER: messages whose header field contains the value.
     *
     * The value is sent as a string — quoted, or a literal if it must be —
     * because it usually comes from mail somebody else wrote, and a quote or
     * a line break in it must stay part of the value. It is a substring
     * match (RFC 9051 §6.4.4), so a caller after an exact match checks what
     * it found.
     *
     * @return list<int> ascending
     */
    public function uidSearchHeader(string $path, string $field, string $value): array
    {
        if (1 !== preg_match('~^[A-Za-z0-9-]+$~', $field)) {
            throw new \InvalidArgumentException(sprintf('Not a header field name: %s', $field));
        }

        return $this->search($path, ImapArgument::atom('HEADER'), ImapArgument::atom($field), ImapArgument::string($value));
    }

    /**
     * One header field of each message, unfolded: `Message-ID` gives
     * `<abc@example.test>`. Fetched with BODY.PEEK, so \Seen is left alone.
     *
     * @return array<int, string|null> UID => the field's value, null where the message has none
     */
    public function uidFetchHeader(string $path, string $set, string $field): array
    {
        if (1 !== preg_match('~^[A-Za-z0-9-]+$~', $field)) {
            throw new \InvalidArgumentException(sprintf('Not a header field name: %s', $field));
        }

        $item    = sprintf('BODY[HEADER.FIELDS (%s)]', strtoupper($field));
        $fetched = $this->uidFetch($path, $set, [sprintf('BODY.PEEK[HEADER.FIELDS (%s)]', strtoupper($field))]);
        $values  = [];

        foreach ($fetched as $uid => $items) {
            $block = $items[$item] ?? null;
            $value = null;

            if (true === is_string($block)) {
                // Line ends as LF only, so `$` below cannot leave a CR on the value.
                $unfolded = (string) preg_replace('~\n[ \t]+~', ' ', str_replace("\r\n", "\n", $block));

                if (1 === preg_match(sprintf('~^%s:[ \t]*(.*?)[ \t]*$~mi', preg_quote($field, '~')), $unfolded, $match)) {
                    $value = $match[1];
                }
            }

            $values[$uid] = $value;
        }

        return $values;
    }

    /**
     * @return list<int> ascending
     */
    private function search(string $path, ImapArgument ...$criteria): array
    {
        $this->ensureSelected($path, true);

        $result = $this->connection->command('UID SEARCH', ...$criteria);
        $uids   = [];

        foreach ($result->untagged('SEARCH') as $response) {
            foreach ($response->data as $token) {
                if (true === is_string($token) && 1 === preg_match('~^\d+$~', $token)) {
                    $uids[] = (int) $token;
                }
            }
        }

        $uids = array_values(array_unique($uids));
        sort($uids);

        return $uids;
    }

    /**
     * UID FETCH, the answers keyed by UID.
     *
     * Only responses that carry a UID in the set are kept: a server may tell
     * this connection about other messages in passing — a flag another
     * session changed — and those are not answers to this command.
     *
     * @param list<string> $items fetch items as IMAP writes them: `FLAGS`, `BODY.PEEK[]`
     *
     * @return array<int, array<string, mixed>> UID => items by upper-cased name;
     *                                          `BODY.PEEK[]` comes back as `BODY[]`
     */
    public function uidFetch(string $path, string $set, array $items, bool $readOnly = true): array
    {
        $this->ensureSelected($path, $readOnly);

        $result = $this->connection->command(
            'UID FETCH',
            ImapArgument::atom($set),
            ImapArgument::list(array_map(ImapArgument::atom(...), ['UID', ...$items])),
        );

        $wanted  = self::setContains($set);
        $fetched = [];

        foreach ($result->untagged('FETCH') as $response) {
            $fields = $response->fetchItems();
            $uid    = $fields['UID'] ?? null;

            if (false === is_string($uid) || 1 !== preg_match('~^\d+$~', $uid) || false === $wanted((int) $uid)) {
                continue;
            }

            $fetched[(int) $uid] = ($fetched[(int) $uid] ?? []) + $fields;
        }

        ksort($fetched);

        return $fetched;
    }

    /**
     * Add, remove or replace flags, without the server echoing them back.
     *
     * @param '+'|'-'|''   $mode  add, remove, or replace
     * @param list<string> $flags `\Seen`, `\Flagged`, keywords
     */
    public function uidStore(string $path, string $set, string $mode, array $flags): void
    {
        $this->ensureSelected($path, false);

        $this->connection->command(
            'UID STORE',
            ImapArgument::atom($set),
            ImapArgument::atom($mode . 'FLAGS.SILENT'),
            ImapArgument::list(array_map(ImapArgument::atom(...), $flags)),
        );
    }

    /**
     * Copy messages to another folder.
     *
     * @return array<int, int> source UID => new UID, from COPYUID (RFC 4315);
     *                         empty when the server does not say
     */
    public function uidCopy(string $path, string $set, string $destination): array
    {
        $this->ensureSelected($path, true);

        return self::copyUids($this->connection->command('UID COPY', ImapArgument::atom($set), ImapArgument::string($destination)));
    }

    /**
     * Move messages to another folder: UID MOVE where the server has it
     * (RFC 6851), and the sequence it replaces where it does not — copy, mark
     * the originals \Deleted, and expunge them. The fallback expunges only
     * those UIDs when the server can (UIDPLUS); without it, EXPUNGE removes
     * every \Deleted message in the folder, which is what a client asking for
     * a move on such a server has always got.
     *
     * @return array<int, int> source UID => new UID, where the server says
     */
    public function uidMove(string $path, string $set, string $destination): array
    {
        if (true === $this->connection->supports('MOVE')) {
            $this->ensureSelected($path, false);

            return self::copyUids($this->connection->command('UID MOVE', ImapArgument::atom($set), ImapArgument::string($destination)));
        }

        $this->ensureSelected($path, false);

        $copied = self::copyUids($this->connection->command('UID COPY', ImapArgument::atom($set), ImapArgument::string($destination)));

        $this->uidStore($path, $set, '+', ['\\Deleted']);
        $this->expunge($path, $set);

        return $copied;
    }

    /**
     * Remove \Deleted messages: only those in $set where the server allows
     * it (UID EXPUNGE, RFC 4315), every one in the folder otherwise.
     */
    public function expunge(string $path, ?string $set = null): void
    {
        $this->ensureSelected($path, false);

        if (null !== $set && true === $this->connection->supports('UIDPLUS')) {
            $this->connection->command('UID EXPUNGE', ImapArgument::atom($set));

            return;
        }

        $this->connection->command('EXPUNGE');
    }

    /**
     * Put a message into a folder.
     *
     * @param list<string> $flags
     *
     * @return int|null its UID, from APPENDUID (RFC 4315), or null when the server does not say
     */
    public function append(string $path, string $message, array $flags = [], ?\DateTimeInterface $internalDate = null): ?int
    {
        $arguments = [ImapArgument::string($path)];

        if ([] !== $flags) {
            $arguments[] = ImapArgument::list(array_map(ImapArgument::atom(...), $flags));
        }

        if (null !== $internalDate) {
            $arguments[] = ImapArgument::string($internalDate->format('d-M-Y H:i:s O'));
        }

        // Always a literal, whatever it holds: a message is lines.
        $arguments[] = ImapArgument::literal($message);

        $result = $this->connection->command('APPEND', ...$arguments);
        $uid    = $result->code('APPENDUID')[1] ?? null;

        return true === is_string($uid) && 1 === preg_match('~^\d+$~', $uid) ? (int) $uid : null;
    }

    public function create(string $path): void
    {
        $this->connection->command('CREATE', ImapArgument::string($path));
    }

    public function delete(string $path): void
    {
        if ($path === $this->selected) {
            $this->selected = null;
        }

        $this->connection->command('DELETE', ImapArgument::string($path));
    }

    public function rename(string $from, string $to): void
    {
        if ($from === $this->selected) {
            $this->selected = null;
        }

        $this->connection->command('RENAME', ImapArgument::string($from), ImapArgument::string($to));
    }

    public function subscribe(string $path): void
    {
        $this->connection->command('SUBSCRIBE', ImapArgument::string($path));
    }

    public function unsubscribe(string $path): void
    {
        $this->connection->command('UNSUBSCRIBE', ImapArgument::string($path));
    }

    public function logout(): void
    {
        $this->selected = null;
        $this->connection->logout();
    }

    /**
     * A list of UIDs as the shortest set that names them: [3,4,5,9] is `3:5,9`.
     *
     * @param list<int> $uids
     */
    public static function uidSet(array $uids): string
    {
        $uids = array_values(array_unique(array_filter($uids, static fn (int $uid): bool => 0 < $uid)));

        if ([] === $uids) {
            throw new \InvalidArgumentException('An empty set of UIDs names no message.');
        }

        sort($uids);

        $ranges = [];
        $start  = $previous = $uids[0];

        foreach (array_slice($uids, 1) as $uid) {
            if ($uid === $previous + 1) {
                $previous = $uid;

                continue;
            }

            $ranges[] = $start === $previous ? (string) $start : sprintf('%d:%d', $start, $previous);
            $start    = $previous = $uid;
        }

        $ranges[] = $start === $previous ? (string) $start : sprintf('%d:%d', $start, $previous);

        return implode(',', $ranges);
    }

    // ---------------------------------------------------------------- internals

    private function open(string $path, bool $readOnly): ImapMailboxState
    {
        $this->selected = null;

        $result = $this->connection->command(true === $readOnly ? 'EXAMINE' : 'SELECT', ImapArgument::string($path));

        $this->selected         = $path;
        $this->selectedReadOnly = $readOnly;

        return ImapMailboxState::fromResult($path, $result);
    }

    /**
     * Select the folder unless it is selected already in a mode that will do:
     * read-write does for everything, read-only only for reading.
     */
    private function ensureSelected(string $path, bool $readOnly): void
    {
        if ($path === $this->selected && (true === $readOnly || false === $this->selectedReadOnly)) {
            return;
        }

        $this->open($path, $readOnly);
    }

    /**
     * Whether a UID falls in a set as written: `5:*`, `12,14:20`.
     *
     * A range with a star in it is taken as reaching every UID. The star is
     * the highest UID in the folder, and `5:*` on a folder whose highest is
     * 3 is the range 3:5 — so which UIDs it covers depends on a number this
     * does not know, and the server's answer is the one to believe.
     *
     * @return \Closure(int): bool
     */
    private static function setContains(string $set): \Closure
    {
        $ranges = [];

        foreach (explode(',', $set) as $part) {
            if (1 !== preg_match('~^(\d+|\*)(?::(\d+|\*))?$~', trim($part), $match)) {
                throw new ImapProtocolException(sprintf('Not a UID set: %s', $set));
            }

            $ends = [$match[1], $match[2] ?? $match[1]];

            $ranges[] = true === in_array('*', $ends, true)
                ? [1, PHP_INT_MAX]
                : [min((int) $ends[0], (int) $ends[1]), max((int) $ends[0], (int) $ends[1])];
        }

        return static function (int $uid) use ($ranges): bool {
            foreach ($ranges as [$low, $high]) {
                if ($uid >= $low && $uid <= $high) {
                    return true;
                }
            }

            return false;
        };
    }

    /**
     * The COPYUID code of a COPY or MOVE, as source UID => destination UID.
     *
     * @return array<int, int>
     */
    private static function copyUids(ImapResult $result): array
    {
        $code = $result->code('COPYUID');

        if (null === $code || false === is_string($code[1] ?? null) || false === is_string($code[2] ?? null)) {
            return [];
        }

        $from = self::expand($code[1]);
        $to   = self::expand($code[2]);

        return count($from) === count($to) ? array_combine($from, $to) : [];
    }

    /** @return list<int> */
    private static function expand(string $set): array
    {
        $uids = [];

        foreach (explode(',', $set) as $part) {
            if (1 === preg_match('~^(\d+)(?::(\d+))?$~', $part, $match)) {
                $low  = (int) $match[1];
                $high = (int) ($match[2] ?? $match[1]);

                // A server may write a range either way round (RFC 9051 §9).
                $range = $low <= $high ? range($low, $high) : range($low, $high, -1);
                array_push($uids, ...$range);
            }
        }

        return $uids;
    }
}
