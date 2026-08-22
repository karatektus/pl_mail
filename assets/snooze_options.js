/**
 * The wake times offered by the snooze menus.
 *
 * Shared by the per-row menu and the list toolbar so the two cannot drift —
 * "Tomorrow" meaning 8am in one place and 9am in the other is the kind of
 * difference nobody reports and everybody notices.
 *
 * Computed in the browser, deliberately. The server never sees a timezone for
 * the session, so "tomorrow morning" resolved server-side would mean tomorrow
 * morning wherever the container happens to think it is. The endpoint takes an
 * absolute instant and stays ignorant of what the user called it.
 */

/** Morning, for every option that lands on a future day. */
const MORNING_HOUR = 8;

/** "Later today" is a nudge, not a date — it only exists while it still fits. */
const LATER_TODAY_HOUR = 18;

function at(date, hour) {
    const result = new Date(date);
    result.setHours(hour, 0, 0, 0);
    return result;
}

function addDays(date, days) {
    const result = new Date(date);
    result.setDate(result.getDate() + days);
    return result;
}

/**
 * Days until the coming Saturday. Today counts as "this weekend" only if it is
 * not already Saturday or Sunday — on those, "this weekend" is now, which is
 * not a snooze, so it rolls to the next one.
 */
function daysUntilSaturday(now) {
    const day = now.getDay(); // 0 Sun … 6 Sat
    const delta = (6 - day + 7) % 7;
    return 0 === delta ? 7 : delta;
}

/** Days until the coming Monday. */
function daysUntilMonday(now) {
    const delta = (1 - now.getDay() + 7) % 7;
    return 0 === delta ? 7 : delta;
}

/**
 * @param {Date} [now]
 * @returns {{ key: string, at: Date }[]} in menu order, soonest first
 */
export function snoozeOptions(now = new Date()) {
    const options = [];

    // Dropped rather than pushed to tomorrow once the evening has passed: an
    // option labelled "later today" that resolves to a time already gone would
    // fire on the very next sweep.
    const laterToday = at(now, LATER_TODAY_HOUR);

    if (laterToday > now) {
        options.push({ key: "later_today", at: laterToday });
    }

    options.push({ key: "tomorrow", at: at(addDays(now, 1), MORNING_HOUR) });
    options.push({ key: "this_weekend", at: at(addDays(now, daysUntilSaturday(now)), MORNING_HOUR) });
    options.push({ key: "next_week", at: at(addDays(now, daysUntilMonday(now)), MORNING_HOUR) });

    // Sorted, because the declaration order above is only the order these are
    // usually in. On a Saturday or a Sunday "this weekend" has already rolled
    // to the NEXT one — a snooze to a weekend that has started is not a snooze
    // — so it lands after "next week", and the menu listed a later time above
    // an earlier one. A person reading down the list and picking the first
    // acceptable option would have got the furthest away.
    //
    // This is also what makes the "soonest first" in the docblock true rather
    // than aspirational, which is what it was.
    options.sort((a, b) => a.at - b.at);

    return options;
}
