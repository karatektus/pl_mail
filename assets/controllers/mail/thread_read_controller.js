import { Controller } from "@hotwired/stimulus";
import { jsonCsrfHeaders } from "../../csrf.js";

export default class extends Controller {
    static values = {
        id: Number,
        delay: { type: Number, default: 2000 },
        markUrl: String,
    };

    #timer = null;

    connect() {
        this.#timer = setTimeout(() => this.#markRead(), this.delayValue);
    }

    disconnect() {
        // User navigated away before the delay elapsed — cancel.
        clearTimeout(this.#timer);
    }

    async #markRead() {
        const response = await fetch(this.markUrlValue, {
            method: "POST",
            headers: jsonCsrfHeaders(),
            body: JSON.stringify({ read: true }),
        });

        if (!response.ok) return;

        const html = await response.text();
        Turbo.renderStreamMessage(html);
    }
}
