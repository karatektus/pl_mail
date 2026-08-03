<?php

declare(strict_types=1);

namespace App\Jmap\State;

use App\Entity\User\User;
use App\Jmap\Protocol\Exception\MethodException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The single façade the rest of the app uses for JMAP state.
 *
 * Write side: sync handlers and /set methods record mutations here. record()
 * only persist()s the log row; it deliberately does NOT flush() so it commits
 * inside the caller's existing unit of work (e.g. a Gmail batch flush).
 *
 * Read side: /get returns stateFor(); /changes calls changesSince().
 */
final class StateManager
{
    /**
     * How many change rows a single /changes call may return before it sets
     * hasMoreChanges and asks the client to page. Kept modest for mobile.
     */
    private const int DEFAULT_MAX_CHANGES = 256;

    /**
     * (account, type) pairs mutated during this request, pending a push.
     *
     * @var array<int,array<string,bool>>
     */
    private array $dirty = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ChangeLogRepository $changeLogRepository,
    ) {
    }

    public function recordCreated(int $accountId, JmapObjectType $type, string $entityId): void
    {
        $this->record($accountId, $type, ChangeType::Created, $entityId);
    }

    public function recordUpdated(int $accountId, JmapObjectType $type, string $entityId): void
    {
        $this->record($accountId, $type, ChangeType::Updated, $entityId);
    }

    public function recordDestroyed(int $accountId, JmapObjectType $type, string $entityId): void
    {
        $this->record($accountId, $type, ChangeType::Destroyed, $entityId);
    }

    /**
     * Record that a set of Threads was touched, de-duplicated.
     *
     * A Thread has no mutations of its own — it changes because one of its
     * Emails did — so callers that touch Emails in a batch collect the affected
     * thread ids and pass them here once, rather than logging a row per message.
     *
     * Everything is reported as "updated" rather than "created": distinguishing
     * a brand-new thread from a grown one would mean asking whether every one
     * of its messages is also new, and RFC 8620 §5.2 already requires clients
     * to fetch an id in "updated" that they do not yet hold.
     *
     * Takes ids, not entities, to keep this class free of entity-class coupling
     * (see the class docblock) — callers already hold the ids.
     *
     * @param iterable<int|string> $threadIds
     */
    public function recordThreadsTouched(int $accountId, iterable $threadIds): void
    {
        $seen = [];

        foreach ($threadIds as $threadId) {
            $threadId = (string) $threadId;

            if ('' === $threadId || true === array_key_exists($threadId, $seen)) {
                continue;
            }

            $seen[$threadId] = true;
            $this->recordUpdated($accountId, JmapObjectType::Thread, $threadId);
        }
    }

    private function record(int $accountId, JmapObjectType $type, ChangeType $changeType, string $entityId): void
    {
        $this->entityManager->persist(new ChangeLog($accountId, $type, $entityId, $changeType));

        // Remember WHAT moved, not how often. A Gmail batch calls this
        // hundreds of times; push wants one notification per (account, type),
        // so the set is collapsed here and drained once at the end of the
        // request or handler by JmapPushSubscriber.
        $this->dirty[$accountId][$type->value] = true;
    }

    /**
     * The (account, type) pairs touched since the last drain, with their
     * current state tokens, emptying the buffer.
     *
     * Tokens are read AFTER the caller's flush, so they are the values a
     * client will actually see from /changes — reading them at record() time
     * would push a state that does not exist yet.
     *
     * @return array<int,array<string,string>> accountId => type => state
     */
    public function drainDirty(): array
    {
        $dirty = $this->dirty;
        $this->dirty = [];

        $changed = [];

        foreach ($dirty as $accountId => $types) {
            foreach (array_keys($types) as $type) {
                $objectType = JmapObjectType::tryFrom((string) $type);

                if (null === $objectType) {
                    continue;
                }

                $changed[$accountId][(string) $type] = $this->stateFor($accountId, $objectType);
            }
        }

        return $changed;
    }

    /**
     * The opaque state token for an account+objectType, as returned by /get.
     */
    public function stateFor(int $accountId, JmapObjectType $type): string
    {
        return (string) $this->changeLogRepository->latestSequence($accountId, $type);
    }

    /**
     * Compute the created/updated/destroyed partition since a client's token.
     *
     * @throws MethodException "cannotCalculateChanges" when the token predates
     *                         retained history, or is not a recognised token.
     */
    public function changesSince(
        int $accountId,
        JmapObjectType $type,
        ?string $sinceState,
        ?int $maxChanges = null,
    ): ChangeSet {
        if (null === $sinceState) {
            throw new MethodException('invalidArguments', 'sinceState is required.');
        }

        if (false === ctype_digit($sinceState)) {
            throw new MethodException('cannotCalculateChanges', 'Unrecognised state token.');
        }

        $limit = $maxChanges ?? self::DEFAULT_MAX_CHANGES;

        if ($limit < 1) {
            $limit = self::DEFAULT_MAX_CHANGES;
        }

        $since = (int) $sinceState;
        $latest = $this->changeLogRepository->latestSequence($accountId, $type);

        if ($since === $latest) {
            return new ChangeSet((string) $since, (string) $latest, false, [], [], []);
        }

        // A token ahead of the log cannot have come from this server (a restored
        // backup, a client bug, a token from another account). Reporting "no
        // changes" would leave that client permanently stale, so make it resync.
        if ($since > $latest) {
            throw new MethodException('cannotCalculateChanges', 'State token is ahead of the change log.');
        }

        $oldest = $this->changeLogRepository->oldestSequence($accountId, $type);

        // "0" means the client holds nothing yet, which is always answerable —
        // it is not a token that could have predated a prune.
        //
        // The guard MUST skip it: the change log's PK is one sequence shared by
        // every (account, objectType), so the first row of a given type can sit
        // at any number. Mailbox's first row here is 93 and Thread's is 181, so
        // without this every freshly-connected client — which by definition
        // starts at "0" — was told to resync instead of getting its changes.
        //
        // For a non-zero token the guard is sound: stateFor() only ever hands
        // out sequences belonging to this type, so a token below the oldest
        // retained row genuinely means the history was pruned.
        if (0 !== $since && 0 !== $oldest && $since < $oldest - 1) {
            throw new MethodException('cannotCalculateChanges', 'State token predates retained history.');
        }

        $rows = $this->changeLogRepository->changesSince($accountId, $type, $since, $limit);
        $hasMore = count($rows) > $limit;

        if (true === $hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        return $this->collapse($since, $rows, $hasMore);
    }

    /**
     * Fold a run of log rows into the three JMAP id partitions. An id created
     * and destroyed within the window is omitted (the client never saw it).
     *
     * @param list<ChangeLog> $rows
     */
    private function collapse(int $since, array $rows, bool $hasMore): ChangeSet
    {
        $newState = $since;

        /** @var array<string,array{created:bool,destroyed:bool}> $seen */
        $seen = [];

        foreach ($rows as $row) {
            $newState = (int) $row->sequence;
            $id = $row->entityId;

            if (false === array_key_exists($id, $seen)) {
                $seen[$id] = ['created' => false, 'destroyed' => false];
            }

            if (ChangeType::Created === $row->changeType) {
                $seen[$id]['created'] = true;
            }

            if (ChangeType::Destroyed === $row->changeType) {
                $seen[$id]['destroyed'] = true;
            }
        }

        $created = [];
        $updated = [];
        $destroyed = [];

        foreach ($seen as $id => $flags) {
            if (true === $flags['created'] && true === $flags['destroyed']) {
                continue;
            }

            if (true === $flags['created']) {
                $created[] = (string) $id;
                continue;
            }

            if (true === $flags['destroyed']) {
                $destroyed[] = (string) $id;
                continue;
            }

            $updated[] = (string) $id;
        }

        return new ChangeSet(
            (string) $since,
            (string) $newState,
            $hasMore,
            array_values($created),
            array_values($updated),
            array_values($destroyed),
        );
    }

    /**
     * The session-level state string (RFC 8620 §2): a digest of the account
     * configuration. It changes when accounts are added/removed/renamed, which
     * is the signal for a client to refetch the Session object.
     */
    public function sessionState(User $user): string
    {
        $parts = [];

        foreach ($user->accounts as $account) {
            $parts[] = (string) $account->id;
        }

        sort($parts);

        return substr(hash('xxh128', implode('|', $parts)), 0, 16);
    }
}
