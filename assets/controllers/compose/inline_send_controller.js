import { Controller } from "@hotwired/stimulus";

/**
 * **UNWIRED.** Nothing renders the bar this drives any more: the composer now
 * stays open through the cancel window and its own Send pill is the cancel, in
 * the thread and in the dock alike. See compose--compose#armSendHold and
 * compose/_sent.stream.html.twig. Kept while that is reviewed.
 *
 * The "Sending… Ns — click to cancel" bar that replaces the reply buttons
 * after an inline send. Mirrors the fetch shape of undo_send_controller, but
 * lives in the thread instead of a toast.
 *
 * `delay` is the CANCEL WINDOW, not the send delay, and the difference is a
 * bug. This countdown used to be handed the full ten-second hold, so the last
 * click it accepted was at the moment the worker took the message — a cancel
 * sent at 9.9s reached the server after the send had started, and the reply
 * bar had already been swapped for "cancelled". The server now refuses to
 * confirm a cancel it lost (see MessageRepository::cancelSend), and this
 * window closes early enough that losing is rare rather than routine.
 *
 * When it runs out the message is not gone yet — it goes a couple of seconds
 * later — but it can no longer be called off, so the bar gives the reply
 * buttons back.
 */
export default class extends Controller {
    static targets = ["countdown"];

    static values = {
        url: String,
        // The fallback is the cancel window, not the send delay — a default
        // that outlived the hold would reintroduce the very gap this closes.
        delay: { type: Number, default: 8000 },
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
