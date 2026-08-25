import { Controller } from "@hotwired/stimulus";

/**
 * Keeps the topbar's background-work indicator current.
 *
 * All it does is re-read the frame. The job row is the record and the frame
 * renders it, so this never has to know what a job is, how far along it got, or
 * what happens when one fails — a nudge arrives, the frame is asked again, and
 * whatever the server says is what the user sees.
 *
 * THROTTLED, because a bulk action publishes on every chunk. A run over five
 * thousand conversations is fifty of those, and reloading a frame fifty times
 * in a few seconds is a lot of requests to say something that changed by two
 * percent. A trailing reload keeps the last one, so the FINAL state — the one
 * that says done, or failed — always lands.
 */
export default class extends Controller {
    static values = { url: String };

    /** Milliseconds between reloads while a job is running. */
    static THROTTLE = 1500;

    connect() {
        this._lastAt = 0;
        this._pending = null;
    }

    disconnect() {
        clearTimeout(this._pending);
    }

    refresh() {
        const since = Date.now() - this._lastAt;

        if (since >= this.constructor.THROTTLE) {
            this.#reload();

            return;
        }

        // Trailing edge: the last nudge in a burst is the one that carries
        // "finished", and dropping it would leave the indicator spinning for
        // ever over work that is done.
        clearTimeout(this._pending);
        this._pending = setTimeout(() => this.#reload(), this.constructor.THROTTLE - since);
    }

    #reload() {
        this._lastAt = Date.now();

        const frame = document.getElementById("jobs-indicator");

        if (null === frame) {
            return;
        }

        // The frame carries no src — one on the page would fetch itself on
        // every load, for every user, to say nothing is happening. So the URL
        // is set here, at the one moment there is something to look at, and
        // Turbo fetches it because assigning src is what triggers that.
        frame.src = this.urlValue;
    }
}
