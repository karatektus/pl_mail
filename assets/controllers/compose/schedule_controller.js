import { Controller } from "@hotwired/stimulus";
import { formatWallClock, scheduleOptions, zoneHorizon, zoneNow } from "../../schedule_options.js";

/**
 * The send pill's chevron: send later.
 *
 * Its own controller rather than more methods on compose--compose, for the
 * usual reason and one specific one. The usual: nothing here touches the
 * draft, the recipients, the autosave or the body — it fills in a hidden field
 * and lets the form go. The specific one: the times on the menu are the only
 * thing in the compose window that has to be recomputed while the window is
 * open, and ui--dropdown already fires `opened` for exactly that (the snooze
 * menu was the first to need it). A menu built once on connect and left there
 * across midnight offers a "tomorrow morning" that is this morning.
 *
 * **The choice is submitted, not fetched.** Each item is a real submit button
 * carrying `formaction`, so picking one goes through the form exactly as Send
 * does — through compose--compose's own submit guards (no recipient, no
 * subject, no body), through Turbo, through the same POST body with the same
 * CSRF token. Scheduling a message is sending it, later; making it a separate
 * fetch would have meant a second, quieter path to the same act, with its own
 * ideas about what may go out.
 *
 * **The zone is the configured one, never the browser's.** See
 * assets/schedule_options.js — this posts a wall clock and lets the server
 * turn it into an instant.
 */
export default class extends Controller {
    static targets = ["option", "field", "custom", "input", "error", "toggle"];

    static values = {
        // IANA identifier from ClockGlobal — the zone the rest of the app draws
        // every timestamp in.
        timezone: { type: String, default: "UTC" },
        // Mirrors ScheduledSendResolver::MAX_SECONDS, itself the JMAP
        // maxDelayedSend. Bounds the picker so the refusal is local.
        maxDays: { type: Number, default: 30 },
        // Mirrors ScheduledSendResolver::MIN_SECONDS. Without it the whole band
        // between "now" and "a minute from now" passed the browser and died on
        // the server — see _floor().
        minSeconds: { type: Number, default: 60 },
        // Translated server-side, like every other string this app writes from
        // JavaScript. See _window.html.twig.
        i18n: { type: Object, default: {} },
    };

    connect() {
        this.refresh();
    }

    /**
     * Fill the presets in. Called on connect and on every open, because the
     * answer changes with the clock and a compose window can stay open for
     * hours.
     */
    refresh() {
        const locale  = document.documentElement.lang || undefined;
        const options = scheduleOptions(this.timezoneValue);
        const byKey   = new Map(options.map((option) => [option.key, option.at]));

        for (const element of this.optionTargets) {
            const at = byKey.get(element.dataset.scheduleKey);

            // "Monday morning" is dropped when tomorrow already is Monday —
            // an absent option is expected, not an error.
            if (undefined === at) {
                this._show(element, false);
                continue;
            }

            this._show(element, true);
            element.dataset.scheduleAt = at;

            const when = element.querySelector("[data-schedule-when]");

            if (null !== when) {
                when.textContent = formatWallClock(at, locale);
            }
        }

        if (true === this.hasInputTarget) {
            this.inputTarget.min = zoneNow(this.timezoneValue);
            this.inputTarget.max = zoneHorizon(this.timezoneValue, this.maxDaysValue);
        }
    }

    /** A preset. The click sets the field; the submit is the button's own. */
    choose(event) {
        this._arm(event.currentTarget.dataset.scheduleAt ?? "");
    }

    /**
     * "Pick date & time" opens the native input in place — no modal and no
     * picker library, matching the calendar editor. The menu stays open; the
     * dropdown's outside-click still closes the lot.
     */
    showCustom(event) {
        event?.preventDefault();

        this.customTarget.hidden = false;
        this.toggleTarget.hidden = true;
        this._clearError();

        // Seeded with the first preset so the field is never empty — an empty
        // datetime-local is a fiddly thing to fill from scratch, and the
        // common edit is "that hour, but on Wednesday".
        if ("" === this.inputTarget.value) {
            const [first] = scheduleOptions(this.timezoneValue);
            this.inputTarget.value = first?.at ?? "";
        }

        this.inputTarget.focus();
    }

    /**
     * Confirm a custom time. Refusing here is a courtesy, not the rule —
     * ScheduledSendResolver checks the same two bounds against real instants
     * and is the one that decides. This only spares the round trip.
     */
    confirm(event) {
        const chosen = this.inputTarget.value;

        if ("" === chosen) {
            this._refuse(event, this._t("scheduleUnreadable", "Pick a date and time first."));

            return;
        }

        // Wall clocks in one zone compare as strings; see zoneNow().
        if (chosen < zoneNow(this.timezoneValue)) {
            this._refuse(event, this._t("schedulePast", "That time has already passed."));

            return;
        }

        // Still to come, but too soon to be a schedule. This is the band the
        // whole feature used to fall down: a person testing "does send later
        // work" types the NEXT WHOLE MINUTE, which is almost always under
        // ScheduledSendResolver::MIN_SECONDS away. The old check only asked
        // whether the time had passed, so that click sailed through the browser,
        // was refused by the resolver, and came back as a root-level form error
        // the compose window had nowhere to render — no toast, no message, no
        // schedule. Refusing it here is what makes the common case legible.
        if (chosen < this._floor()) {
            this._refuse(event, this._t("scheduleTooSoon", "Pick a time at least a minute from now."));

            return;
        }

        if (chosen > zoneHorizon(this.timezoneValue, this.maxDaysValue)) {
            this._refuse(
                event,
                this._t("scheduleTooFar", "Mail can be held for at most %days% days.")
                    .replace("%days%", String(this.maxDaysValue)),
            );

            return;
        }

        this._clearError();
        this._arm(chosen);
    }

    /**
     * Call off a hold that is already in place.
     *
     * The same undo endpoint the ten-second send guard uses: it lowers the flag
     * SendMessageHandler reads and clears submissionSendAt, then answers with
     * the draft reopened. One cancel path for both, because they are one thing
     * — a send that has not happened yet being called off.
     */
    async cancel(event) {
        event?.preventDefault();

        const url = event.currentTarget.dataset.cancelUrl;

        if (undefined === url) {
            return;
        }

        const response = await fetch(url, {
            method: "POST",
            headers: { "X-Requested-With": "XMLHttpRequest" },
        });

        if (true === response.ok) {
            window.Turbo.renderStreamMessage(await response.text());
        }
    }

    // ── Private ───────────────────────────────────────────────────────────

    /**
     * The earliest wall-clock MINUTE the server will still accept.
     *
     * `zoneNow` already takes the instant to read, so the floor is just "now,
     * plus the minimum hold" read in the configured zone. The extra 59 seconds
     * are what makes a minute-granularity field comparable to a second-
     * granularity rule: at 11:43:20 the minimum instant is 11:44:20, and the
     * earliest *minute* every second of which clears it is 11:45. Rounding the
     * other way would put 11:44 back on the accepted side of a browser check
     * and on the refused side of the server's — which is the bug this exists to
     * close. Erring strict costs the user one minute; erring loose costs them
     * the whole feature, silently.
     */
    _floor() {
        return zoneNow(this.timezoneValue, new Date(Date.now() + (this.minSecondsValue + 59) * 1000));
    }

    /**
     * Write the chosen wall clock into the hidden field AND enable it.
     *
     * There are two of these controllers in a compose window now — the pill in
     * the header below md and the pill in the action bar above it — and their
     * hidden fields share one name, `schedule_at`. Both are inside the same
     * form, and CSS `display: none` does not keep a control out of a submission:
     * PHP would take the last `schedule_at` in document order, which is the
     * pill the user never touched, and a schedule set from the header would
     * arrive as an empty string.
     *
     * A DISABLED control is not submitted at all. So the template ships both
     * fields disabled and each instance arms only its own, as it writes to it.
     * The untouched pill stays silent.
     */
    _arm(value) {
        this.fieldTarget.value    = value;
        this.fieldTarget.disabled = false;
    }

    /**
     * Both halves of hiding, and both are needed.
     *
     * The `hidden` attribute is the semantic one — it is what takes the button
     * out of the accessibility tree and out of the tab order. But `[hidden]`
     * is a UA-stylesheet rule, so any author rule that sets `display` beats it,
     * and these buttons carry `flex`. Setting the attribute alone leaves a
     * visible button labelled "Monday morning" with no time on it and no
     * schedule behind it.
     */
    _show(element, visible) {
        element.hidden = false === visible;
        element.style.display = true === visible ? "" : "none";
    }

    _refuse(event, text) {
        // The button is a submit: stopping the form is the point of refusing.
        event?.preventDefault();

        this.errorTarget.textContent = text;
        this.errorTarget.hidden = false;
    }

    _clearError() {
        if (true === this.hasErrorTarget) {
            this.errorTarget.textContent = "";
            this.errorTarget.hidden = true;
        }
    }

    /** A translated string, falling back to English if the value is absent. */
    _t(key, fallback) {
        return this.i18nValue?.[key] ?? fallback;
    }
}
