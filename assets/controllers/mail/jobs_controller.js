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
 *
 * AND ON A TIMER WHILE AN IMPORT IS SHOWN. A job publishes as it goes; an
 * import publishes when a page of its listing is done, and a Gmail import
 * spends most of its hours fetching what the listing named, saying nothing.
 * Its line also carries figures that move by themselves — messages a minute,
 * the seconds until Google lets it continue — and a countdown that only
 * changes when something else happens is not one. So while the indicator has
 * an import line in it, it is re-read every few seconds: often with the list
 * open, where somebody is watching, and seldom with it shut. Not at all in a
 * tab nobody is looking at, and not at all when nothing is importing, which
 * is nearly always.
 */
/** The list of work inside the indicator, and the button that opens it. */
const MENU = "[data-ui--dropdown-target='menu']";
const TOGGLE = "[data-action~='click->ui--dropdown#toggle']";

export default class extends Controller {
    static values = { url: String };

    /** Milliseconds between reloads while a job is running. */
    static THROTTLE = 1500;

    /** Milliseconds between looks at an import: list open, and list shut. */
    static WATCHED = 5000;
    static UNWATCHED = 30000;

    connect() {
        this._lastAt = 0;
        this._pending = null;
        this._watch = setInterval(() => this.#watchImport(), this.constructor.WATCHED);
    }

    disconnect() {
        clearTimeout(this._pending);
        clearInterval(this._watch);
    }

    #watchImport() {
        const frame = document.getElementById("jobs-indicator");

        if (true === document.hidden || null === frame || null === frame.querySelector("[data-import-line]")) {
            return;
        }

        const open = null !== frame.querySelector(`${MENU}:not([hidden])`);
        const due = true === open ? this.constructor.WATCHED : this.constructor.UNWATCHED;

        // A little under, so a timer that fires a few milliseconds early does
        // not wait a whole round more.
        if (Date.now() - this._lastAt >= due - 250) {
            this.refresh();
        }
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

        // Whether somebody has the list open and is watching it.
        const wasOpen = null !== frame.querySelector(`${MENU}:not([hidden])`);

        if (false === wasOpen) {
            this.#replace(frame, false);

            return;
        }

        // OPEN, SO CHANGED WHERE IT STANDS.
        //
        // The answer to a frame reload is a whole new frame, dropdown
        // included, and a new dropdown is a closed one. It used to be opened
        // again as soon as the new one was in — which works, and which
        // somebody watching sees as the list going away and coming back.
        // Once per chunk of a bulk action that was a flicker now and then;
        // re-read every five seconds for the hours an import takes, it was
        // the list flashing for as long as it was looked at.
        //
        // So the new rows are fetched and put into the list that is already
        // open, and the button's label with them. The list itself is never
        // taken out of the page, and has nothing to flash with.
        this.#update(frame).then((updated) => {
            if (false === updated) {
                this.#replace(frame, true);
            }
        });
    }

    /**
     * Put the server's current rows into the open list.
     *
     * @returns {Promise<boolean>} false when that could not be done — nothing
     *     is running any more, the request failed — and the frame should be
     *     replaced the ordinary way, which is also what takes it away.
     */
    async #update(frame) {
        let fresh;

        try {
            const response = await fetch(this.urlValue, {
                headers: { Accept: "text/html", "X-Requested-With": "XMLHttpRequest" },
                credentials: "same-origin",
            });

            if (false === response.ok) {
                return false;
            }

            fresh = new DOMParser()
                .parseFromString(await response.text(), "text/html")
                .getElementById("jobs-indicator");
        } catch {
            return false;
        }

        const rows = frame.querySelector(`${MENU} ul`);
        const freshRows = fresh?.querySelector(`${MENU} ul`);

        if (null === rows || null === freshRows || undefined === freshRows) {
            return false;
        }

        rows.innerHTML = freshRows.innerHTML;

        const toggle = frame.querySelector(TOGGLE);
        const freshToggle = fresh.querySelector(TOGGLE);

        if (null !== toggle && null !== freshToggle) {
            // Piece by piece and only what differs: writing the button's
            // markup afresh starts its turning icon again from the top, which
            // is a twitch every five seconds in the corner of the eye.
            if (toggle.className !== freshToggle.className) {
                toggle.className = freshToggle.className;
            }

            const icon = toggle.querySelector("i");
            const freshIcon = freshToggle.querySelector("i");

            if (null !== icon && null !== freshIcon && icon.className !== freshIcon.className) {
                icon.className = freshIcon.className;
            }

            const label = toggle.querySelector("span");
            const freshLabel = freshToggle.querySelector("span");

            if (null !== label && null !== freshLabel && label.textContent !== freshLabel.textContent) {
                label.textContent = freshLabel.textContent;
            }
        }

        return true;
    }

    #replace(frame, wasOpen) {
        if (true === wasOpen) {
            // Opened again as soon as the new one is in, on the next frame so
            // its controller has connected and there is something to hear the
            // click.
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
