import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static values = { id: Number };

    stop(event) {
        event.stopPropagation();
    }

    toggleSelect(event) {
        event.stopPropagation();
        // TODO: bulk selection
    }

    async toggleStar(event) {
        event.stopPropagation();
        await this.#post(this.#url("star"));
    }

    async archive(event) {
        event.stopPropagation();
        await this.#post(this.#url("archive"));
    }

    async delete(event) {
        event.stopPropagation();
        await this.#post(this.#url("delete"));
    }

    async snooze(event, until = null) {
        event.stopPropagation();
        // Call el.dispatchEvent or pass `until` from a date picker before calling.
        // e.g. this.snooze(event, "2026-07-10T08:00:00Z")
        await this.#post(this.#url("snooze"), { until });
    }

    async markRead(event, read = true) {
        event.stopPropagation();
        await this.#post(this.#url("read"), { read });
    }

    // ---------------------------------------------------------------- private

    #url(action) {
        return `/thread/${this.idValue}/status/${action}`;
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
            console.error(`Thread status update failed: ${url}`, response.status);
            return;
        }

        // Turbo handles the stream response automatically when the
        // Content-Type is text/vnd.turbo-stream.html.
        const html = await response.text();
        Turbo.renderStreamMessage(html);
    }
}
