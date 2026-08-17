import { Controller } from "@hotwired/stimulus";
import { jsonCsrfHeaders } from "../../csrf.js";

/**
 * Handles per-row status actions in the message list.
 *
 * Values:
 *   id       — entity ID (thread or message)
 *   type     — "thread" (default) | "message"
 *
 * Routes used:
 *   thread  → /thread/{id}/status/{action}
 *   message → /message/{id}/status/{action}
 */
export default class extends Controller {
    static values = {
        id:   Number,
        type: { type: String, default: "thread" },
    };

    stop(event) {
        event.stopPropagation();
    }

    toggleSelect(event) {
        event.stopPropagation();

        const toolbar = document.querySelector("[data-controller~='mail--list-toolbar']");

        if (toolbar) {
            toolbar.dispatchEvent(
                new CustomEvent("mail--list-toolbar:row-changed", { bubbles: false }),
            );
        }
    }

    async toggleStar(event) {
        const { starUrl } = event.params;
        event.stopPropagation();
        await this.#post(starUrl);
    }

    async archive(event) {
        const { archiveUrl } = event.params;
        event.stopPropagation();
        await this.#post(archiveUrl);
    }

    async trash(event) {
        const { trashUrl } = event.params;
        event.stopPropagation();
        await this.#post(trashUrl);
    }

    /**
     * The wake time arrives as a param, set by the snooze menu — see
     * mail--snooze-menu, which computes it in the browser because the server
     * has no timezone for the session.
     *
     * Absent, this clears the snooze. That used to be the *only* behaviour:
     * the signature took `until = null` as a second argument, and Stimulus
     * calls actions with the event alone, so the row's snooze button silently
     * unsnoozed instead of snoozing.
     */
    async snooze(event) {
        const { snoozeUrl, until = null } = event.params;
        event.stopPropagation();

        await this.#post(snoozeUrl, { until });
    }

    /**
     * Call off a scheduled send from the row.
     *
     * The row is an <li> with an absolutely-positioned overlay <a> across it —
     * so this stops the event the way every other row action does, and the
     * button sits above the anchor rather than inside a nested form. The answer
     * is a Turbo Stream that replaces this very row, which is why nothing here
     * touches the badge itself: the server decides what the row now says.
     *
     * `preventDefault` as well as `stopPropagation`, unlike its neighbours: in
     * the Drafts list the whole row is a link, and a click that reaches the
     * document's default action opens the composer over the toast that just
     * said the hold was lifted.
     */
    async cancelSchedule(event) {
        const { cancelScheduleUrl } = event.params;

        event.preventDefault();
        event.stopPropagation();

        await this.#post(cancelScheduleUrl);
    }

    async markRead(event) {
        const { read, readUrl } = event.params;

        event.stopPropagation();
        await this.#post(readUrl, { read });
    }

    // ── Private ───────────────────────────────────────────────────────────

    async #post(url, body = {}) {
        const response = await fetch(url, {
            method: "POST",
            headers: jsonCsrfHeaders(),
            body: JSON.stringify(body),
        });

        if (!response.ok) {
            console.error(`[message-row] status update failed: ${url}`, response.status);
            return;
        }

        const html = await response.text();

        if (html.trim() !== "") {
            Turbo.renderStreamMessage(html);
        }
    }
}
