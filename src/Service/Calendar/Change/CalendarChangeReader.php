<?php

declare(strict_types=1);

namespace App\Service\Calendar\Change;

use App\Domain\DTO\Calendar\CalendarDelta;
use App\Domain\Enum\Calendar\CalendarChangeKind;
use App\Domain\Exception\CalendarStateTokenException;
use App\Entity\Calendar\CalendarChangeLog;
use App\Repository\Calendar\CalendarChangeLogRepository;

/**
 * Reading calendar_change_log as a state token and a delta, for both protocols.
 *
 * The arithmetic is StateManager's, deliberately: the same token rules, the same
 * refusals, the same collapse. What differs is that a calendar delta is asked
 * for in two scopes — CalDAV about one collection, JMAP about every calendar a
 * user has — and that the answer stays protocol-neutral, because a JMAP method
 * and a CalDAV REPORT dress the same three partitions differently.
 *
 * Not an extension of StateManager and not a call into it. That class is bound
 * to (accountId, JmapObjectType) at every signature, and a calendar has neither
 * — see CalendarChangeLog on why account_id cannot key a calendar. The shared
 * thing is a handful of rules about integers, which is cheaper to state twice
 * than to abstract over two different keys.
 */
final class CalendarChangeReader
{
    /** Matches StateManager: a page a client can hold, not a whole history. */
    private const int DEFAULT_MAX_CHANGES = 256;

    public function __construct(
        private readonly CalendarChangeLogRepository $log,
    ) {
    }

    /** The CalDAV sync-token for one collection. */
    public function stateForCalendar(int $calendarId): string
    {
        return (string) $this->log->latestSequenceForCalendar($calendarId);
    }

    /** The JMAP state string for a user's calendars. */
    public function stateForUser(int $userId): string
    {
        return (string) $this->log->latestSequenceForUser($userId);
    }

    public function sinceForCalendar(int $calendarId, ?string $sinceState, ?int $maxChanges = null): CalendarDelta
    {
        return $this->delta(
            $sinceState,
            $maxChanges,
            $this->log->latestSequenceForCalendar($calendarId),
            $this->log->oldestSequenceForCalendar($calendarId),
            fn (int $since, int $limit): array => $this->log->changesSinceForCalendar($calendarId, $since, $limit),
        );
    }

    public function sinceForUser(int $userId, ?string $sinceState, ?int $maxChanges = null): CalendarDelta
    {
        return $this->delta(
            $sinceState,
            $maxChanges,
            $this->log->latestSequenceForUser($userId),
            $this->log->oldestSequenceForUser($userId),
            fn (int $since, int $limit): array => $this->log->changesSinceForUser($userId, $since, $limit),
        );
    }

    /**
     * @param callable(int,int):list<CalendarChangeLog> $fetch
     */
    private function delta(
        ?string $sinceState,
        ?int $maxChanges,
        int $latest,
        int $oldest,
        callable $fetch,
    ): CalendarDelta {
        if (null === $sinceState) {
            throw new CalendarStateTokenException('A state token is required.');
        }

        if (false === ctype_digit($sinceState)) {
            throw new CalendarStateTokenException('Unrecognised state token.');
        }

        $limit = $maxChanges ?? self::DEFAULT_MAX_CHANGES;

        if ($limit < 1) {
            $limit = self::DEFAULT_MAX_CHANGES;
        }

        $since = (int) $sinceState;

        if ($since === $latest) {
            return new CalendarDelta((string) $since, (string) $latest, false, [], [], []);
        }

        // A token ahead of the log cannot have come from this server — a
        // restored backup, a client bug, a token copied between collections.
        // Answering "nothing changed" would leave that client stale forever.
        if ($since > $latest) {
            throw new CalendarStateTokenException('State token is ahead of the change log.');
        }

        // "0" is every client's first call and is always answerable — it is not
        // a token that could have predated a prune.
        //
        // The guard past that is sound only because the sequence is global while
        // the scopes are not: one calendar's first row can sit at any number, so
        // "below the oldest row in this scope" would wrongly accuse a fresh
        // client. A non-zero token, though, was minted by stateFor*() and so is
        // a sequence that belonged to this scope — below the oldest retained one
        // means the history really was pruned.
        if (0 !== $since && 0 !== $oldest && $since < $oldest - 1) {
            throw new CalendarStateTokenException('State token predates retained history.');
        }

        $rows    = $fetch($since, $limit);
        $hasMore = count($rows) > $limit;

        if (true === $hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        return $this->collapse($since, $rows, $hasMore);
    }

    /**
     * Many rows about one event become one verdict about it.
     *
     * An event created and destroyed inside the same window is dropped entirely:
     * a client that never saw it has nothing to forget, and reporting a
     * destruction for an id it does not hold is noise at best. Anything else
     * that was created reads as created however many times it was then edited,
     * and everything remaining is an update.
     *
     * @param list<CalendarChangeLog> $rows
     */
    private function collapse(int $since, array $rows, bool $hasMore): CalendarDelta
    {
        $newState = $since;

        /** @var array<string,array{uid:string,created:bool,destroyed:bool}> $seen */
        $seen = [];

        foreach ($rows as $row) {
            $newState = (int) $row->sequence;
            $id       = (string) $row->eventId;

            if (false === array_key_exists($id, $seen)) {
                $seen[$id] = ['uid' => $row->eventUid, 'created' => false, 'destroyed' => false];
            }

            // The uid of the newest row wins: an event whose uid was rewritten
            // is, to a CalDAV client, a different resource, and the later name
            // is the one the href has to carry.
            $seen[$id]['uid'] = $row->eventUid;

            if (CalendarChangeKind::Created === $row->changeKind) {
                $seen[$id]['created'] = true;
            }

            if (CalendarChangeKind::Destroyed === $row->changeKind) {
                $seen[$id]['destroyed'] = true;
            }
        }

        $created   = [];
        $updated   = [];
        $destroyed = [];

        foreach ($seen as $id => $flags) {
            if (true === $flags['created'] && true === $flags['destroyed']) {
                continue;
            }

            if (true === $flags['created']) {
                $created[$id] = $flags['uid'];
                continue;
            }

            if (true === $flags['destroyed']) {
                $destroyed[$id] = $flags['uid'];
                continue;
            }

            $updated[$id] = $flags['uid'];
        }

        return new CalendarDelta(
            (string) $since,
            (string) $newState,
            $hasMore,
            $created,
            $updated,
            $destroyed,
        );
    }
}
