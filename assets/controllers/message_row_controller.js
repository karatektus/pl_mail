import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static values = { id: Number };

    stop(event) {
        event.stopPropagation();
    }

    toggleSelect(event) {
        event.stopPropagation();

        // Notify the toolbar controller so it can sync the master checkbox
        // and show/hide the action bar.  We bubble up through the DOM until
        // we find the element that hosts list-toolbar, then dispatch there.
        const toolbar = document.querySelector("[data-controller~='list-toolbar']");

        if (toolbar) {
            toolbar.dispatchEvent(
                new CustomEvent("list-toolbar:row-changed", { bubbles: false }),
            );
        }
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

        const html = await response.text();
        Turbo.renderStreamMessage(html);
    }
}
