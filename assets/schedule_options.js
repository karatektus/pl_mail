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
 * instant with the tz database in hand (ScheduledSendResolver). No offsets are
 * computed here at all — Intl is asked what time it is over there, and the
 * rest is calendar arithmetic. That is why there is no DST special case: the
 * only place a zone rule can bite is the conversion, and the conversion is
 * PHP's.
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
 * the value it produces, so "is this in the past" and "is this beyond the
 * ceiling" are string comparisons — "YYYY-MM-DDTHH:MM" sorts
 * chronologically, which is the one thing ISO 8601 was designed for. The
 * server checks both again against real instants; this is only so the refusal
 * arrives before the round trip.
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
