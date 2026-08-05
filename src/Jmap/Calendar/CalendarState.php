<?php

declare(strict_types=1);

namespace App\Jmap\Calendar;

/**
 * The state string every calendar method returns, and why it is a constant.
 *
 * Mail's state is a real token: `StateManager` writes one `jmap_change_log` row
 * per mutation and the autoincrement PK *is* the token, so `Email/get` can hand
 * a client a number that `Email/changes` will honour. That works because every
 * path that changes a JMAP-visible mail property calls `StateManager::record*`
 * — through `MailChangeRecorder`, which exists exactly so five callers cannot
 * each forget the same two things.
 *
 * Calendars have no such recorder, and could not be given one from inside this
 * directory. An event changes from four places: the sync engine pulling a
 * remote calendar, extraction reading a message, the web editor, and
 * `CalendarEvent/set` here. Only the last is in `src/Jmap/`. **A log that
 * recorded a quarter of the writes would be worse than none**: the token would
 * sit still while a pull replaced the whole day, and a client comparing states
 * would conclude nothing had changed and never refetch. Mail's log is
 * trustworthy because it is complete; a partial one is not a weaker version of
 * it, it is a lie with a number on it.
 *
 * So the state is fixed and the methods say so in the only other way the
 * protocol offers: `canCalculateChanges` is false, there is no
 * `Calendar/changes` or `CalendarEvent/changes`, and a client re-runs its query
 * — which is what `Email/query` already asks for and is spec-legal.
 *
 * The value is deliberately not a number. Should calendars later join the
 * change log — a `CalendarChangeRecorder` beside `MailChangeRecorder`, called by
 * all four writers, and `JmapObjectType` cases to match — tokens become
 * sequences, and a client still holding this one fails `ctype_digit` in
 * `StateManager::changesSince()` and is told to resync. That is the correct
 * degradation, and it is free.
 */
final class CalendarState
{
    public const string FIXED = 'fixed';
}
