/**
 * The times the send-options menu offers, as wall clocks in the user's own
 * timezone.
 *
 * Deliberately NOT the snooze menu's arrangement (assets/snooze_options.js),
 * and the difference is the whole point of this file. Snoozing computes
 * instants with `new Date()` and the browser's local zone, which is right for
 * that feature: a snooze is a private reminder, and the clock it should follow
 * is the one on the machine looking at it.
 *
 * A scheduled send is not private. It is a promise about when mail leaves,
 * shown afterwards in the drafts list, in the toast, and to every JMAP client
 * on every other device — all of which draw it in the timezone the user
 * *configured*, because that is the zone TwigTimezoneSubscriber renders the
 * whole application in. So "tomorrow morning" here means eight in the morning
 * in that zone, whatever the laptop happens to be set to. Someone in Berlin
 * working from New York schedules a mail for 08:00 Berlin, sees "08:00" on
 * screen, and it goes out at 08:00 Berlin.
 *
 * Which means the arithmetic is done on the wall clock and never on an
 * instant. What comes out is a "YYYY-MM-DDTHH:MM" string, which is exactly
 * what <input type="datetime-local"> produces, and the server turns it into an
 * instant with the tz database in hand (ScheduledSendResolver). Intl is asked
 * what time it is over there, and the rest is calendar arithmetic.
 *
 * That held for the VALUE and, for a while, was wrongly assumed to hold for the
 * BOUNDS as well — "the only place a zone rule can bite is the conversion, and
 * the conversion is PHP's". Comparing two wall clocks is a second place it can
 * bite, because wall clocks stop being monotonic for the hour a fall-back
 * repeats. instantOf() below is the one function here that computes an offset,
 * and it exists for the comparisons alone: the value this file produces is
 * still a wall clock, still resolved by PHP.
 */

import { prefersHour12 } from "./clock_format.js";

/** "Morning", for every preset that lands on a future day. */
const MORNING_HOUR = 8;

/** "Afternoon". Early enough to still be one in every reading of the word. */
const AFTERNOON_HOUR = 13;

/**
 * Today's date and weekday in a given IANA zone.
 *
 * en-GB with numeric parts, because the locale must not vary the *parsing* —
 * this reads its own output back. The user's locale governs what is shown, and
 * that happens in `label()` below, on a different formatter entirely.
 *
 * @param {string} timeZone
 * @param {Date} now
 * @returns {{ year: number, month: number, day: number }}
 */
export function zoneToday(timeZone, now = new Date()) {
    const parts = {};

    for (const part of new Intl.DateTimeFormat("en-GB", {
        timeZone,
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    }).formatToParts(now)) {
        parts[part.type] = part.value;
    }

    return {
        year: Number(parts.year),
        month: Number(parts.month),
        day: Number(parts.day),
    };
}

/**
 * Right now, in that zone, as a wall clock.
 *
 * The bounds the custom picker enforces are expressed in the same spelling as
 * the value it produces. They are no longer COMPARED as strings, though —
 * "YYYY-MM-DDTHH:MM" sorts chronologically only while the clock is monotonic,
 * which it is not through a fall-back; both sides go through instantOf() first.
 * The server checks both again against real instants; this is only so the
 * refusal arrives before the round trip.
 *
 * @param {string} timeZone
 * @param {Date} now
 */
export function zoneNow(timeZone, now = new Date()) {
    const parts = {};

    for (const part of new Intl.DateTimeFormat("en-GB", {
        timeZone,
        hour12: false,
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
    }).formatToParts(now)) {
        parts[part.type] = part.value;
    }

    return wallClock(
        { year: Number(parts.year), month: Number(parts.month), day: Number(parts.day) },
        // hour12:false still yields "24" for midnight in some engines.
        Number(parts.hour) % 24,
        Number(parts.minute),
    );
}

/**
 * The instant a wall clock in a given zone actually falls on.
 *
 * The one place in this file that computes an offset, and it exists because
 * the comment at the top of the file — "the only place a zone rule can bite is
 * the conversion, and the conversion is PHP's" — was true of the VALUE and
 * false of the BOUNDS. Producing "2026-10-25T02:30" and letting the server
 * resolve it is still right. Deciding whether that string is in the past by
 * comparing it to another string is not, because wall clocks are not monotonic
 * across a fall-back: Berlin runs 02:00–02:59 twice on 25 October 2026, so for
 * those 3600 seconds "later on the clock" and "later in time" disagree.
 *
 * What that cost, concretely: at 02:58 CEST the floor (now + the minimum hold)
 * lands at 02:00 CET — a LOWER string than the 02:59 the user typed — so
 * `chosen < floor` read false, the browser accepted, and ScheduledSendResolver
 * refused it against real instants. The compose window has nowhere to render a
 * root-level form error, so the click did nothing at all: exactly the silent
 * failure `_floor()` was written to prevent, returning for one hour a year.
 *
 * Ambiguity is resolved the way PHP resolves it, because PHP is the one that
 * decides: a wall clock that happens twice means the FIRST of the two, and one
 * that never happens (the spring-forward gap) shifts forward. Hence the two
 * candidates and the choice between them — the offset a day either side of the
 * guess brackets any single transition, and only a candidate that reads back as
 * the very wall clock asked for is a real answer.
 *
 * @param {string} at wall clock, "YYYY-MM-DDTHH:MM"
 * @param {string} timeZone
 * @returns {number} epoch milliseconds
 */
export function instantOf(at, timeZone) {
    const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(at);

    if (null === match) {
        return NaN;
    }

    const [, year, month, day, hour, minute] = match.map(Number);

    // The wall clock read as though it were UTC. Not an instant yet — it is the
    // left-hand side of "wall = instant + offset", and the offset is what the
    // two probes below supply.
    const guess = Date.UTC(year, month - 1, day, hour, minute);
    const DAY   = 86_400_000;

    const candidates = [
        guess - offsetAt(guess - DAY, timeZone),
        guess - offsetAt(guess + DAY, timeZone),
    ];

    const real = candidates.filter(
        (instant) => zoneNow(timeZone, new Date(instant)) === at,
    );

    // Ambiguous (both candidates are genuinely this wall clock): the earlier,
    // as PHP does. Nonexistent (neither is): the later, which is where PHP
    // pushes a time that the clock skipped over.
    return 0 === real.length
        ? Math.max(...candidates)
        : Math.min(...real);
}

/**
 * The zone's offset from UTC at a given instant, in milliseconds.
 *
 * Read rather than tabulated: formatting the instant in the zone and reading
 * the result back as UTC gives "instant + offset", so subtracting the instant
 * leaves the offset — no DST table, and correct for zones whose rules have
 * changed historically.
 */
function offsetAt(instant, timeZone) {
    const parts = {};

    for (const part of new Intl.DateTimeFormat("en-GB", {
        timeZone,
        hour12: false,
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
    }).formatToParts(new Date(instant))) {
        parts[part.type] = part.value;
    }

    return Date.UTC(
        Number(parts.year),
        Number(parts.month) - 1,
        Number(parts.day),
        Number(parts.hour) % 24,
        Number(parts.minute),
        Number(parts.second),
    ) - instant;
}

/**
 * The far end of the picker: `days` from now, at the same time of day.
 *
 * Which is the ceiling to within a DST hour, and deliberately on the strict
 * side of it — a picker that offers a minute the server will refuse is worse
 * than one that stops a minute early, because the refusal costs a round trip
 * and reads as a bug.
 */
export function zoneHorizon(timeZone, days, now = new Date()) {
    const [date, time] = zoneNow(timeZone, now).split("T");
    const [year, month, day] = date.split("-").map(Number);
    const [hour, minute] = time.split(":").map(Number);

    return wallClock(shiftDays({ year, month, day }, days), hour, minute);
}

/**
 * The same date shifted by whole days, still as a wall clock.
 *
 * Date.UTC does the month/year rollover and leap years; using UTC rather than
 * local keeps the browser's own offset out of a calculation it has no business
 * in — nothing here is an instant, these are just three numbers being added to.
 */
function shiftDays({ year, month, day }, days) {
    const shifted = new Date(Date.UTC(year, month - 1, day + days));

    return {
        year: shifted.getUTCFullYear(),
        month: shifted.getUTCMonth() + 1,
        day: shifted.getUTCDate(),
    };
}

/** 0 Sun … 6 Sat, for a wall-clock date. */
function weekday({ year, month, day }) {
    return new Date(Date.UTC(year, month - 1, day)).getUTCDay();
}

/**
 * Days until the coming Monday. Today being Monday means the NEXT one: "Monday
 * morning" offered on a Monday afternoon must not be this morning, which has
 * gone, and a Monday-morning schedule made on Monday at 07:00 would be six
 * days closer than the person clicking it expects.
 */
function daysUntilMonday(date) {
    const delta = (1 - weekday(date) + 7) % 7;

    return 0 === delta ? 7 : delta;
}

/** "2026-08-13T08:00" — the datetime-local spelling, zero-padded. */
export function wallClock({ year, month, day }, hour, minute = 0) {
    const pad = (value) => String(value).padStart(2, "0");

    return `${year}-${pad(month)}-${pad(day)}T${pad(hour)}:${pad(minute)}`;
}

/**
 * The menu's presets, in order.
 *
 * @param {string} timeZone IANA identifier, e.g. "Europe/Berlin"
 * @param {Date} [now]
 * @returns {{ key: string, at: string }[]} `at` is a wall clock in `timeZone`
 */
export function scheduleOptions(timeZone, now = new Date()) {
    const today    = zoneToday(timeZone, now);
    const tomorrow = shiftDays(today, 1);
    const monday   = shiftDays(today, daysUntilMonday(today));

    const options = [
        { key: "tomorrow_morning",   at: wallClock(tomorrow, MORNING_HOUR) },
        { key: "tomorrow_afternoon", at: wallClock(tomorrow, AFTERNOON_HOUR) },
        { key: "monday_morning",     at: wallClock(monday, MORNING_HOUR) },
    ];

    // Tomorrow is Monday: the third option is the second name for the first,
    // and a menu offering the same instant twice under two labels is a menu
    // that makes the user check. Gmail drops it too.
    if (options[0].at === options[2].at) {
        options.pop();
    }

    return options;
}

/**
 * A wall clock as the user reads it — "Thu, 08:00" — in their locale.
 *
 * Rendered through UTC on purpose: the string is already the time in the
 * user's zone, so it must be printed verbatim rather than converted a second
 * time. Building the Date in UTC and formatting in UTC is how you get Intl to
 * do the weekday and the 12/24-hour choice without also doing an offset.
 *
 * `hour12` is passed EXPLICITLY. Leaving it out is what made this the one
 * clock in the app that ignored the user's setting; the default now comes from
 * their choice, and only falls back to the locale's when there is no choice on
 * the page to read.
 *
 * @param {string} at wall clock, "YYYY-MM-DDTHH:MM"
 * @param {string} locale
 * @param {boolean} [hour12] overrides the document-level preference
 */
export function formatWallClock(at, locale, hour12 = prefersHour12()) {
    const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(at);

    if (null === match) {
        return "";
    }

    const [, year, month, day, hour, minute] = match.map(Number);

    return new Intl.DateTimeFormat(locale || undefined, {
        timeZone: "UTC",
        weekday: "short",
        // `hourCycle` is not set alongside it on purpose: the two conflict, and
        // hour12 alone lets the locale keep its own idea of whether 24-hour
        // means h23 or h24.
        hour12: hour12,
        hour: "2-digit",
        minute: "2-digit",
    }).format(new Date(Date.UTC(year, month - 1, day, hour, minute)));
}
