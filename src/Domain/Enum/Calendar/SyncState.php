<?php

declare(strict_types=1);

namespace App\Domain\Enum\Calendar;

/**
 * Whether a local event owes the remote a write, and which one.
 *
 * Two-way sync needs an answer to "has this row changed since the remote last
 * saw it?", and there are only two ways to get one: compare the stored object
 * against a shadow copy of what was last pushed, or record the intent at the
 * moment of the edit. The shadow copy doubles the storage for every event and
 * still answers wrongly whenever the comparison and the serialiser disagree
 * about key order or a null. This is the other way, and it is the cheap one: a
 * writer says what it did, and the pusher believes it.
 *
 * The cost is that a write which forgets to mark the row is a change that never
 * leaves — which is why marking lives on CalendarEventWriter, the one class
 * every local write already goes through, rather than being a field callers set
 * by hand.
 *
 * PendingDelete is the case worth reading twice. A local delete of a synced
 * event cannot remove the row, because the row is the only record that the
 * remote still holds a copy — so the row survives in this state until the
 * remote confirms, and CalendarEventWriter drops its occurrences at the same
 * time so it disappears from every view immediately. Views read occurrences,
 * never events, so nothing else has to learn about this state.
 */
enum SyncState: string
{
    /** In step with the remote, or belonging to no remote at all. */
    case Clean = 'clean';

    /** Made here and never sent; has no remoteId yet. */
    case PendingCreate = 'pendingCreate';

    /** Changed here since the last successful push. */
    case PendingUpdate = 'pendingUpdate';

    /** Deleted here; the row is waiting for the remote to be told. */
    case PendingDelete = 'pendingDelete';

    /**
     * Whether the pusher has work to do for a row in this state.
     */
    public function isPending(): bool
    {
        return match ($this) {
            self::Clean                                              => false,
            self::PendingCreate, self::PendingUpdate, self::PendingDelete => true,
        };
    }

    /**
     * The states the pusher sweeps for, as a repository criterion.
     *
     * Derived from isPending() rather than written out, so a fifth case cannot
     * be added to the enum and silently left out of the query that finds work
     * — which would present exactly as "some edits never sync", the failure
     * this whole enum exists to prevent.
     *
     * @return list<self>
     */
    public static function pendingCases(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $state): bool => true === $state->isPending(),
        ));
    }

    /**
     * The state after a local edit.
     *
     * A create that has not been sent stays a create however many times it is
     * edited — promoting it to PendingUpdate would send an update for a
     * resource the remote has never heard of. A pending delete is not undone by
     * an edit either: nothing in the application edits a row it has just
     * deleted, and if something did, resurrecting it silently is a worse answer
     * than leaving the deletion to go out.
     */
    public function afterLocalEdit(): self
    {
        return match ($this) {
            self::Clean         => self::PendingUpdate,
            self::PendingCreate => self::PendingCreate,
            self::PendingUpdate => self::PendingUpdate,
            self::PendingDelete => self::PendingDelete,
        };
    }

    /**
     * Whether a remote change arriving now would overwrite an unsent local one.
     *
     * The question the conflict rule is written in terms of — see
     * CalendarSyncService. PendingDelete answers false: the row is on its way
     * out, so a remote edit to it is not a loss worth logging.
     */
    public function wouldLoseALocalEdit(): bool
    {
        return match ($this) {
            self::Clean, self::PendingDelete       => false,
            self::PendingCreate, self::PendingUpdate => true,
        };
    }
}
