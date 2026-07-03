import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static values = { id: Number, delay: { type: Number, default: 2000 } };

    #timer = null;

    connect() {
        this.#timer = setTimeout(() => this.#markRead(), this.delayValue);
    }

    disconnect() {
        // User navigated away before the delay elapsed — cancel.
        clearTimeout(this.#timer);
    }

    async #markRead() {
        const response = await fetch(`/thread/${this.idValue}/status/read`, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": document.querySelector('meta[name="csrf-token"]')?.content ?? "",
            },
            body: JSON.stringify({ read: true }),
        });

        if (!response.ok) return;

        const html = await response.text();
        Turbo.renderStreamMessage(html);
    }
}
