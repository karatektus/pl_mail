import { Controller } from "@hotwired/stimulus";
import { snoozeOptions } from "../../snooze_options.js";
import { prefersHour12 } from "../../clock_format.js";

/**
 * Fills a snooze menu with concrete wake times.
 *
 * The times cannot come from Twig: the server has no timezone for the session,
 * so "tomorrow morning" rendered server-side would mean tomorrow morning in
 * whatever zone the container thinks it is in. The markup therefore ships the
 * labels — which are translated, and must be — and this fills in the instants.
 *
 * It only sets params; the click itself is handled by mail--message-row#snooze
 * or mail--list-toolbar#snoozeSelected, so the posting, the CSRF header and the
 * Turbo Stream handling all stay in one place per surface.
 *
 * Options are recomputed on every open rather than once on connect. A list left
 * open across midnight would otherwise offer a "tomorrow" that is today.
 */
/**
 * One formatter per shape, kept: `toLocaleString` with options builds a new
 * Intl.DateTimeFormat on every call, which is the expensive part of it, and a
 * menu formats four times on every open. Keyed on the inputs that can change
 * while the page is open — the language and the clock preference are both on
 * <html> and are read each time, so a change to either is picked up.
 */
const formatters = new Map();

function formatter(withDate) {
    const lang   = document.documentElement.lang || undefined;
    const hour12 = prefersHour12();
    const key    = `${lang}|${hour12}|${withDate}`;

    if (false === formatters.has(key)) {
        formatters.set(key, new Intl.DateTimeFormat(lang, {
            weekday: "short",
            ...(withDate ? { day: "numeric", month: "short" } : {}),
            hour12,
            hour: "2-digit",
            minute: "2-digit",
        }));
    }

    return formatters.get(key);
}

export default class extends Controller {
    static targets = ["option"];

    // No connect(). It used to refresh here as well as on every open, and the
    // menu is never seen until it is opened — every surface opens it through
    // ui--dropdown, whose `opened` event the partial wires to refresh(), or
    // through mail--row-menus, which calls refresh() itself. While a row
    // carried its own copy, that was fifty refreshes on every list render for
    // menus nobody had asked for (#496).

    refresh() {
        const options = snoozeOptions();
        const byKey = new Map(options.map((option) => [option.key, option.at]));

        this.#reorder(options);

        for (const element of this.optionTargets) {
            const at = byKey.get(element.dataset.snoozeKey);

            // "Later today" disappears once the evening has passed, so a target
            // with no matching option is expected rather than an error.
            if (undefined === at) {
                element.hidden = true;
                continue;
            }

            element.hidden = false;

            // setAttribute, not dataset: the identifiers contain a double dash
            // ("mail--message-row"), which has no faithful camelCase form and
            // so cannot be round-tripped through the dataset API.
            //
            // All are set unconditionally. Only one has a controller listening
            // on any given surface, and the unused attribute is inert — cheaper
            // than teaching this controller which surface it is on. The last one
            // but one is the long-press sheet's, which posts its own captured
            // URL, and the last is the list rows' shared menu (mail--row-menus).
            element.setAttribute("data-mail--message-row-until-param", at.toISOString());
            element.setAttribute("data-mail--list-toolbar-until-param", at.toISOString());
            element.setAttribute("data-mail--message-actions-until-param", at.toISOString());
            element.setAttribute("data-mail--action-sheet-until-param", at.toISOString());
            element.setAttribute("data-mail--row-menus-until-param", at.toISOString());

            const when = element.querySelector("[data-snooze-when]");

            if (null !== when) {
                when.textContent = this.#format(at);
            }
        }
    }

    // ── Private ───────────────────────────────────────────────────────────

    /**
     * Put the options on screen in the order they will actually happen.
     *
     * The template renders the four in a fixed order because it is the order
     * they are usually in — but "this weekend" rolls to the NEXT weekend once
     * one has started, so on a Saturday or a Sunday it falls after "next week"
     * and the menu was listing a later time above an earlier one. Somebody
     * reading down and taking the first acceptable option got the furthest
     * away, and the reviewer who hit it read "Sa., 08:00" as today and
     * reported it as a snooze into the past.
     *
     * Reordering here rather than in the template, because only the browser
     * knows what these resolve to — that is the whole reason the times are
     * computed here at all.
     */
    #reorder(options) {
        const targets = this.optionTargets;
        const parent  = targets[0]?.parentElement;

        if (!parent) {
            return;
        }

        // Already in that order, which is every day but the weekend: nothing to
        // move. Moving them anyway is a DOM mutation per option, which every
        // observer on the page then gets to look at.
        const current = targets.map((target) => target.dataset.snoozeKey);
        const wanted  = options.map(({ key }) => key).filter((key) => current.includes(key));

        if (wanted.join() === current.filter((key) => wanted.includes(key)).join()) {
            return;
        }

        // Before whatever follows the options rather than at the end of the
        // menu. Appending them to the end, as this used to, put them below
        // "Unsnooze" and its divider in the one menu that has those — the
        // options are a block, and they are sorted within it.
        const after = targets[targets.length - 1].nextElementSibling;

        for (const { key } of options) {
            const element = targets.find((target) => target.dataset.snoozeKey === key);

            if (element) {
                parent.insertBefore(element, after);
            }
        }
    }

    /**
     * "Sat 08:00" near at hand, "Sat 29 Aug, 08:00" further out.
     *
     * The weekday alone is only unambiguous inside the coming week. "This
     * weekend" on a Saturday means the Saturday after next, and rendering that
     * as "Sa., 08:00" reads as this morning — which is precisely how it was
     * reported, as a snooze to a time four hours in the past. It was not; the
     * label was.
     *
     * The threshold is "not within the next two days", so today and tomorrow
     * keep the short form they are clearest in and everything else says which
     * date it means.
     *
     * `hour12` explicitly, for the same reason formatWallClock() passes it: an
     * hour asked for without it follows the LOCALE's default rather than the
     * user's clock setting, so this menu printed "8:00 AM" at someone who had
     * chosen a 24-hour clock everywhere else in the app.
     */
    #format(date) {
        const soon = new Date();
        soon.setDate(soon.getDate() + 2);

        return formatter(date > soon).format(date);
    }
}
