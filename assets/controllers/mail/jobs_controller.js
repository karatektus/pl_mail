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
/** The list of work inside the indicator, and the button that opens it. */
const MENU = "[data-ui--dropdown-target='menu']";
const TOGGLE = "[data-action~='click->ui--dropdown#toggle']";

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

        // Read before the frame is replaced: whether somebody has the list
        // open and is watching it.
        const wasOpen = null !== frame.querySelector(`${MENU}:not([hidden])`);

        if (true === wasOpen) {
            // The answer is a whole new frame, dropdown included, and a new
            // dropdown is a closed one. To somebody watching a count go up,
            // that was the list shutting in their face every second and a
            // half — which is the same as it not working. Opened again as soon
            // as the new one is in, on the next frame so its controller has
            // connected and there is something to hear the click.
            frame.addEventListener("turbo:frame-load", () => {
                requestAnimationFrame(() => {
                    const menu = frame.querySelector(MENU);

                    if (null !== menu && true === menu.hidden) {
                        // No entrance the second time: it never left.
                        menu.setAttribute("data-enter", "none");
                        frame.querySelector(TOGGLE)?.click();
                    }
                });
            }, { once: true });
        }

        // The frame carries no src — one on the page would fetch itself on
        // every load, for every user, to say nothing is happening. So the URL
        // is set here, at the one moment there is something to look at, and
        // Turbo fetches it because assigning src is what triggers that.
        //
        // ONCE. Assigning the same URL again changes nothing, and Turbo
        // fetches on a change: the first nudge of a page's life updated the
        // indicator and every one after it did nothing at all, so a job
        // showed whatever it had reached when it was first looked at and then
        // sat there. From the second nudge on the frame is told to reload.
        if (null === frame.getAttribute("src")) {
            frame.src = this.urlValue;

            return;
        }

        frame.reload();
    }
}
