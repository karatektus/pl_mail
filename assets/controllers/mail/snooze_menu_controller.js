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
     * "Sat 08:00" — weekday and time, no date. The label above it already says
     * which day in words; this is the confirmation, not the primary reading.
     *
     * `hour12` explicitly, for the same reason formatWallClock() passes it: an
     * hour asked for without it follows the LOCALE's default rather than the
     * user's clock setting, so this menu printed "8:00 AM" at someone who had
     * chosen a 24-hour clock everywhere else in the app.
     */
    #format(date) {
        return date.toLocaleString(document.documentElement.lang || undefined, {
            weekday: "short",
            hour12: prefersHour12(),
            hour: "2-digit",
            minute: "2-digit",
        });
    }
}
