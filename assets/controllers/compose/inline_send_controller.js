import { Controller } from "@hotwired/stimulus";

/**
 * The "Sending… Ns — click to cancel" bar that replaces the reply buttons
 * after an inline send. Mirrors the fetch shape of undo_send_controller, but
 * lives in the thread instead of a toast: the countdown matches the send
 * delay, and when it runs out the message is on its way, so the bar simply
 * gives the reply buttons back.
 */
export default class extends Controller {
    static targets = ["countdown"];

    static values = {
        url: String,
        delay: { type: Number, default: 10000 },
    };

    connect() {
        this._remaining = Math.round(this.delayValue / 1000);
        this._render();

        this._interval = setInterval(() => {
            this._remaining--;
            this._render();

            if (this._remaining <= 0) {
                this._expire();
            }
        }, 1000);
    }

    disconnect() {
        clearInterval(this._interval);
    }

    async cancel(event) {
        event.preventDefault();
        clearInterval(this._interval);

        this.element.style.pointerEvents = "none";

        const response = await fetch(this.urlValue, {
            method: "POST",
            headers: { "X-Requested-With": "XMLHttpRequest" },
        });

        if (!response.ok) {
            console.error("[inline-send] cancel failed", response.status);
            this.element.style.pointerEvents = "";
            return;
        }

        // The stream removes the message from the thread and re-opens the
        // draft in the inline editor, replacing this bar along the way.
        Turbo.renderStreamMessage(await response.text());
    }

    // ── Private ───────────────────────────────────────────────────────────

    _render() {
        if (this.hasCountdownTarget) {
            this.countdownTarget.textContent = Math.max(0, this._remaining);
        }
    }

    /** Send window closed: drop the bar, restore the reply buttons. */
    _expire() {
        clearInterval(this._interval);

        const zone = this.element.closest("[data-reply-zone]");
        zone?.querySelector("[data-reply-actions]")?.classList.remove("hidden");

        this.element.remove();
    }
}
