<?php

declare(strict_types=1);

namespace App\Jmap\State;

use App\Entity\User;
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

    private function record(int $accountId, JmapObjectType $type, ChangeType $changeType, string $entityId): void
    {
        $this->entityManager->persist(new ChangeLog($accountId, $type, $entityId, $changeType));
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

        $oldest = $this->changeLogRepository->oldestSequence($accountId, $type);

        if (0 !== $oldest && $since < $oldest - 1) {
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

        foreach ($user->getAccounts() as $account) {
            $parts[] = (string) $account->getId();
        }

        sort($parts);

        return substr(hash('xxh128', implode('|', $parts)), 0, 16);
    }
}
