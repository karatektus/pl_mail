<?php

declare(strict_types=1);

namespace App\Service\Contacts\Change;

use App\Domain\DTO\Contacts\ContactDelta;
use App\Domain\Enum\Contacts\ContactChangeKind;
use App\Domain\Exception\ContactStateTokenException;
use App\Entity\Contacts\ContactChangeLog;
use App\Repository\Contacts\ContactChangeLogRepository;

/**
 * contact_change_log as JMAP states and deltas.
 *
 * CalendarChangeReader's rules, restated rather than shared, for the reason it
 * gives about StateManager: the shared thing is a handful of rules about
 * integers, cheaper to state twice than to abstract over two different logs.
 * The same refusals (malformed, ahead, pruned) and the same collapse — created
 * then destroyed inside the window is nothing, created then edited is created.
 */
final class ContactChangeReader
{
    private const int DEFAULT_MAX_CHANGES = 256;

    public function __construct(
        private readonly ContactChangeLogRepository $log,
    ) {
    }

    /** The ContactCard state for a user's contacts. */
    public function cardState(int $userId): string
    {
        return (string) $this->log->latestCardSequenceForUser($userId);
    }

    /** The AddressBook state; a number from the same sequence, and opaque all the same. */
    public function bookState(int $userId): string
    {
        return (string) $this->log->latestBookSequenceForUser($userId);
    }

    public function cardsSince(int $userId, ?string $sinceState, ?int $maxChanges = null): ContactDelta
    {
        return $this->delta(
            $sinceState,
            $maxChanges,
            $this->log->latestCardSequenceForUser($userId),
            $this->log->oldestCardSequenceForUser($userId),
            fn (int $since, int $limit): array => $this->log->cardChangesSinceForUser($userId, $since, $limit),
            static fn (ContactChangeLog $row): string => (string) $row->contactId,
        );
    }

    public function booksSince(int $userId, ?string $sinceState, ?int $maxChanges = null): ContactDelta
    {
        return $this->delta(
            $sinceState,
            $maxChanges,
            $this->log->latestBookSequenceForUser($userId),
            $this->log->oldestBookSequenceForUser($userId),
            fn (int $since, int $limit): array => $this->log->bookChangesSinceForUser($userId, $since, $limit),
            static fn (ContactChangeLog $row): string => (string) $row->addressBookId,
        );
    }

    /**
     * @param callable(int,int):list<ContactChangeLog> $fetch
     * @param callable(ContactChangeLog):string         $idOf
     */
    private function delta(?string $sinceState, ?int $maxChanges, int $latest, int $oldest, callable $fetch, callable $idOf): ContactDelta
    {
        if (null === $sinceState || false === ctype_digit($sinceState)) {
            throw new ContactStateTokenException('Unrecognised state token.');
        }

        $limit = null === $maxChanges || $maxChanges < 1 ? self::DEFAULT_MAX_CHANGES : $maxChanges;
        $since = (int) $sinceState;

        if ($since === $latest) {
            return new ContactDelta((string) $since, (string) $latest, false, [], [], []);
        }

        if ($since > $latest) {
            throw new ContactStateTokenException('State token is ahead of the change log.');
        }

        // As in CalendarChangeReader: "0" is every client's first call, and a
        // non-zero token below the oldest kept row has lost its history.
        if (0 !== $since && 0 !== $oldest && $since < $oldest - 1) {
            throw new ContactStateTokenException('State token predates retained history.');
        }

        $rows    = $fetch($since, $limit);
        $hasMore = count($rows) > $limit;

        if (true === $hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        $newState = $since;
        /** @var array<string,array{created:bool,destroyed:bool}> $seen */
        $seen = [];

        foreach ($rows as $row) {
            $newState = (int) $row->sequence;
            $id       = $idOf($row);

            $seen[$id] ??= ['created' => false, 'destroyed' => false];

            if (ContactChangeKind::Created === $row->changeKind) {
                $seen[$id]['created'] = true;
            }

            // A destroy after a create is the end of it; a create after a
            // destroy — restored from the bin — is a contact the client must
            // fetch again, so the later row wins.
            if (ContactChangeKind::Destroyed === $row->changeKind) {
                $seen[$id]['destroyed'] = true;
            } elseif (ContactChangeKind::Created === $row->changeKind) {
                $seen[$id]['destroyed'] = false;
            }
        }

        $created = $updated = $destroyed = [];

        foreach ($seen as $id => $flags) {
            $id = (string) $id;

            match (true) {
                $flags['created'] && $flags['destroyed'] => null,
                $flags['created']                        => $created[] = $id,
                $flags['destroyed']                      => $destroyed[] = $id,
                default                                  => $updated[] = $id,
            };
        }

        return new ContactDelta((string) $since, (string) $newState, $hasMore, $created, $updated, $destroyed);
    }
}
