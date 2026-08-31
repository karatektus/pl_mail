<?php

declare(strict_types=1);

namespace App\Jmap\Calendar;

/**
 * The state string Calendar/get returns, and why it alone is still a constant.
 *
 * This class used to explain why *every* calendar method answered with a fixed
 * string: events are written from the sync engine, from extraction, from the
 * web editor and from CalendarEvent/set, only the last of which lives in
 * src/Jmap/, and a log recording a quarter of the writes "is not a weaker
 * version of it, it is a lie with a number on it".
 *
 * That is fixed. `calendar_change_log` records every event write, and it is
 * written by a Doctrine listener rather than by the writers — completeness by
 * construction rather than by asking four callers to remember, which is what
 * makes it trustworthy where a recorder would not have been. CalendarEvent/get,
 * /query and /set now return real sequences, and CalendarEvent/changes exists.
 *
 * ── What is still fixed, and why that is honest ───────────────────────────
 *
 * Calendars themselves. The log records events, keyed by the collection they
 * are in; a calendar being renamed, recoloured, reordered or removed writes
 * nothing. So Calendar/get keeps a constant state and there is no
 * Calendar/changes — the same refusal as before, now covering one object type
 * instead of two.
 *
 * That is a smaller gap than it sounds. A user has a handful of calendars and
 * Calendar/get returns all of them by default, so re-running it is cheap and is
 * what a client does anyway; there is no /query to page through. CalDAV, the
 * other reader of this data, does not need it at all — a client discovers the
 * collection set with a PROPFIND on the calendar-home-set, which is a live read
 * and holds no token.
 *
 * ── If calendars later join the log ───────────────────────────────────────
 *
 * The shape is already there: give CalendarChangeLog a nullable event_id, or a
 * sibling table, and record collection writes from the same listener. Clients
 * holding this string then fail ctype_digit in CalendarChangeReader and are
 * told to resync, which is the correct degradation and is free.
 */
final class CalendarState
{
    public const string FIXED = 'fixed';
}
