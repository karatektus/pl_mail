import { Controller } from "@hotwired/stimulus";

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

        const toolbar = document.querySelector("[data-controller~='list-toolbar']");

        if (toolbar) {
            toolbar.dispatchEvent(
                new CustomEvent("list-toolbar:row-changed", { bubbles: false }),
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

    async snooze(event, until = null) {
        const { snoozeUrl } = event.params;
        event.stopPropagation();

        await this.#post(snoozeUrl, { until });
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
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": document.querySelector('meta[name="csrf-token"]')?.content ?? "",
            },
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
