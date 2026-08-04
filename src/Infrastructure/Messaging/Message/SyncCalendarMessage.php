<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Bring one connected calendar and its remote back into agreement.
 *
 * Dispatched by the scheduled sweep (`app:calendar:sync`, every fifteen
 * minutes), by the same command run by hand, and by the subscribe flow the
 * moment a calendar is first mirrored — a user who has just ticked a calendar
 * should not wait a quarter of an hour to see anything in it.
 *
 * One calendar per message, not one account. Calendars fail independently: a
 * shared calendar whose permission was revoked must not stop the user's own
 * from syncing, and a whole-account job would retry the working ones every time
 * the broken one failed. It is also what makes the read-only rule enforceable
 * per calendar rather than per connection.
 *
 * An id, so the handler resolves the row itself. A calendar deleted or
 * unsubscribed between the dispatch and the run is a normal outcome — the sweep
 * queues work while the user keeps clicking — and the handler treats a missing
 * one as nothing to do rather than an error.
 */
final readonly class SyncCalendarMessage
{
    public function __construct(
        public int $calendarId,
    ) {}
}
