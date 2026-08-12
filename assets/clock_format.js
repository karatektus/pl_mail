/**
 * The user's twelve-or-twenty-four-hour choice, on the browser side.
 *
 * Every time Twig prints goes through ClockGlobal, which resolves the setting
 * (`clock.time`, `clock.timeCompact`, `clock.hour`). Nothing in the browser
 * can reach it: `Intl.DateTimeFormat` asked for an hour and not told which
 * kind falls back to the LOCALE's default, so an en-GB user who had chosen a
 * 12-hour clock got 24-hour labels and an en-US user who had chosen 24 got
 * "8:00 AM" — in the send-later menu and the snooze menu, next to timestamps
 * the server had drawn the other way round.
 *
 * Its own module rather than a Stimulus value because the two menus that want
 * it share no template and no controller, and the list of surfaces that print
 * a time from JavaScript only grows. templates/_layout/app.html.twig stamps
 * the setting on <html> once per render; this reads it back.
 */

/**
 * @param {Document} [doc]
 * @returns {boolean|undefined} `undefined` — meaning "let the locale decide" —
 *   when there is no preference on the page, which is a document rendered
 *   outside the app layout or a unit test.
 */
export function prefersHour12(doc = document) {
    const flag = doc?.documentElement?.dataset?.clockHour12;

    if ("true" === flag) {
        return true;
    }

    if ("false" === flag) {
        return false;
    }

    return undefined;
}
