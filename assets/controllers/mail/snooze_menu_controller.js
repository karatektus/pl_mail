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
export default class extends Controller {
    static targets = ["option"];

    connect() {
        this.refresh();
    }

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
            // Both are set unconditionally. Only one has a controller listening
            // on any given surface, and the unused attribute is inert — cheaper
            // than teaching this controller which surface it is on.
            element.setAttribute("data-mail--message-row-until-param", at.toISOString());
            element.setAttribute("data-mail--list-toolbar-until-param", at.toISOString());

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
        const parent = this.optionTargets[0]?.parentElement;

        if (!parent) {
            return;
        }

        for (const { key } of options) {
            const element = this.optionTargets.find((target) => target.dataset.snoozeKey === key);

            // appendChild MOVES an existing node, so this walks the sorted list
            // pushing each option to the end in turn — which leaves them in
            // exactly that order.
            if (element) {
                parent.appendChild(element);
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

        return date.toLocaleString(document.documentElement.lang || undefined, {
            weekday: "short",
            ...(date > soon ? { day: "numeric", month: "short" } : {}),
            hour12: prefersHour12(),
            hour: "2-digit",
            minute: "2-digit",
        });
    }
}
