<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Ask the provider to push this calendar's changes, rather than waiting for the
 * next sweep to ask on the calendar's behalf.
 *
 * Dispatched by the subscribe flow the moment a calendar is first mirrored, and
 * by nothing else. `app:calendar:push` still walks every mirrored calendar every
 * hour and does the same work; that sweep is what eventually opens a channel on
 * an install that could not open one yet, and this message does not replace it.
 * It only removes the first hour, which is the hour somebody is watching.
 *
 * **The whole point of the indirection is that the click neither waits on the
 * answer nor fails on it.** Registration is best-effort by contract: it needs a
 * public HTTPS address, and on Google a callback domain verified in the Cloud
 * project that owns the OAuth client. Neither is knowable from the request that
 * ticked the box, and neither is anything the user can act on. Registering
 * inline would put a live call to Google or Microsoft inside the HTTP response
 * that renders the calendar list, and would let a pending domain verification
 * surface as an error on "mirror this calendar". Queued, the worst outcome is a
 * worker deciding the calendar stays on polling — the documented degraded state,
 * and exactly where the hourly sweep goes looking.
 *
 * An id, so the handler resolves the row: the rule SyncCalendarMessage states,
 * for the same reason. A calendar can be unsubscribed between the dispatch and
 * the run, and the handler treats a missing one as nothing to do.
 */
final readonly class RegisterCalendarPushMessage
{
    public function __construct(
        public int $calendarId,
    ) {}
}
