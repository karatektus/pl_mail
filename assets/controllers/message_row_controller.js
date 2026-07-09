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

    async delete(event) {
        event.stopPropagation();
        await this.#post(this.#url("delete"));
    }

    async snooze(event, until = null) {
        event.stopPropagation();

        // Snooze is always thread-level.
        const url = this.typeValue === "thread"
            ? this.#url("snooze")
            : `/thread/${this.idValue}/status/snooze`;

        await this.#post(url, { until });
    }

    async markRead(event) {
        event.stopPropagation();
        const { read } = event.params;
        await this.#post(this.#url("read"), { read });
    }

    async trash(event) {
        event.stopPropagation();

        // Separate from delete: moves to trash rather than permanently removing.
        // For thread rows this calls the delete route (which internally decides
        // trash vs. permanent based on current mailbox).
        // For message rows it calls the dedicated trash route.
        const url = this.typeValue === "message"
            ? this.#url("trash")
            : this.#url("delete");

        await this.#post(url);
    }

    // ── Private ───────────────────────────────────────────────────────────

    #url(action) {
        const base = this.typeValue === "message" ? "message" : "thread";
        return `/${base}/${this.idValue}/status/${action}`;
    }

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
