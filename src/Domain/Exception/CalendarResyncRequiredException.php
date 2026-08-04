<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * The stored sync token can no longer be resumed from, and the calendar has to
 * be read from scratch.
 *
 * Exists alongside CalendarChangeSet::$requiresFullResync rather than instead
 * of it, and the two are not redundant. The flag is how pull() reports it,
 * because that is a normal outcome of a poll and not a failure — tokens expire
 * on a schedule, Google's after about a week of silence. This exception is for
 * every *other* entry point that can meet the same condition and has no
 * changeset to answer with: push() and delete() against a calendar the remote
 * has since re-keyed, and any driver-internal helper that discovers it below
 * the level that returns one.
 *
 * Neither Messenger marker, and that is the point of it being its own class:
 * the engine catches it by type, clears Calendar::$syncToken, and re-runs the
 * pull. Marking it unrecoverable would dead-letter a calendar that is one
 * cheap full read away from being correct; marking it recoverable would retry
 * the identical failing request with the identical dead token.
 */
final class CalendarResyncRequiredException extends CalendarSyncException
{
    public function __construct(string $message = 'The calendar sync token has expired.', int $status = 410)
    {
        parent::__construct($message, $status);
    }
}
