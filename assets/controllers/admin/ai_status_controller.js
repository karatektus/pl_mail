import { Controller } from "@hotwired/stimulus";
import { jsonCsrfHeaders } from "../../csrf.js";

/**
 * Polls the model host's status and swaps the rendered panel in.
 *
 * WHY THE SERVER SENDS HTML AND NOT JUST NUMBERS
 * ──────────────────────────────────────────────
 * The panel has six states to tell apart — off, unreachable, nothing resident,
 * warm, split across CPU and GPU, no history yet — and every one is a sentence
 * in three languages. Building those here would put the copy in a file no
 * translator ever opens. So the endpoint answers with both: the reading, for
 * anything that wants to reason about it, and the fragment, which is what goes
 * on screen. This file decides WHEN to ask, and nothing about what it says.
 *
 * WHAT IT DOES NOT DO
 * ───────────────────
 * It never talks to the model host. It cannot: different origin, an address
 * usually only the server can route to, and `connect-src 'self'` refuses it in
 * production anyway. Everything goes through /admin/ai/status.
 */
export default class extends Controller {
    static targets = ["panel"];

    static values = {
        url: String,
        startUrl: String,
        pauseUrl: String,
        resumeUrl: String,
        interval: { type: Number, default: 5000 },
        /**
         * Whether there is anything to watch. False while the master switch is
         * off — the panel still renders once, to say so, and then goes quiet
         * rather than asking the same question every five seconds for as long
         * as somebody leaves the tab open.
         */
        live: { type: Boolean, default: false },
        window: { type: String, default: "day" },
    };

    /**
     * Consecutive failures, for the backoff. Reset by any answer at all,
     * including one that says the host is down — that is the endpoint working.
     */
    #failures = 0;

    /**
     * Depth of the request in flight. A poll that overlaps its predecessor is
     * the classic way to turn one slow host into a queue of requests that
     * outlives the page, and this endpoint is slow exactly when the host is.
     */
    #busy = false;

    #timer = null;

    /** The last markup rendered, so an unchanged poll does not touch the DOM. */
    #rendered = "";

    /**
     * Whether the last answer said the model host is not there.
     *
     * Polling slows down while it is, and that is not cosmetic: each poll spends
     * a real timeout on the server waiting for a machine that is switched off.
     * The panel still says the same calm thing; it just stops asking as often.
     */
    #quiet = false;

    connect() {
        this.onVisibilityChange = this.onVisibilityChange.bind(this);
        document.addEventListener("visibilitychange", this.onVisibilityChange);

        // Always once, even when nothing is live: the first answer is what
        // replaces the placeholder with "AI is off", and a spinner that never
        // resolves reads as a broken page.
        this.refresh();
        this.#schedule();
    }

    disconnect() {
        document.removeEventListener("visibilitychange", this.onVisibilityChange);
        this.#stop();
    }

    /** The hour / 24 hours / 7 days buttons. */
    selectWindow(event) {
        const chosen = event.currentTarget.dataset.window;

        if (!chosen || chosen === this.windowValue) {
            return;
        }

        this.windowValue = chosen;
        this.refresh();
    }

    start() {
        this.#command(this.startUrlValue);
    }

    pause() {
        this.#command(this.pauseUrlValue);
    }

    resume() {
        this.#command(this.resumeUrlValue);
    }

    async refresh() {
        if (true === this.#busy) {
            return;
        }

        this.#busy = true;

        try {
            const response = await fetch(`${this.urlValue}?window=${encodeURIComponent(this.windowValue)}`, {
                headers: { Accept: "application/json" },
            });

            if (false === response.ok) {
                throw new Error(`Request failed (${response.status}).`);
            }

            this.#render(await response.json());
            this.#failures = 0;
        } catch {
            // Quiet on purpose. A failed poll is almost always a session that
            // expired or a container restarting, and the panel already on
            // screen is still the last thing that was true — replacing it with
            // an error would throw away the reading somebody is looking at.
            this.#failures++;
        } finally {
            this.#busy = false;
        }
    }

    onVisibilityChange() {
        if (document.hidden) {
            // A backgrounded tab polling a model host is a request every five
            // seconds to a machine that is doing the work this panel exists to
            // keep out of the way of.
            this.#stop();

            return;
        }

        this.refresh();
        this.#schedule();
    }

    // ── Private ───────────────────────────────────────────────────────────

    async #command(url) {
        if (!url) {
            return;
        }

        try {
            const response = await fetch(url, {
                method: "POST",
                headers: jsonCsrfHeaders(),
            });

            if (false === response.ok) {
                throw new Error(`Request failed (${response.status}).`);
            }

            // The answer carries the panel re-rendered from state read AFTER
            // the click, so a start that was refused because somebody else got
            // there first shows their run rather than the one this page
            // expected.
            this.#render(await response.json());

            // The controls change what is worth watching: a backfill that has
            // just been started needs the poll running even if the page was
            // loaded before anything was.
            this.#schedule();
        } catch {
            // Same reasoning as a failed poll — and the next poll re-reads the
            // truth anyway, so a refusal that was not rendered corrects itself
            // within one interval.
            this.#failures++;
        }
    }

    #render(payload) {
        // Read before the early returns below: an unchanged fragment still
        // carries a fresh answer about the host, and that is what the poll
        // interval is chosen from.
        this.#quiet = true === payload?.ready && false === payload?.host?.reachable;

        if (false === this.hasPanelTarget || "string" !== typeof payload.html) {
            return;
        }

        // Only when it actually differs. Rewriting identical markup every five
        // seconds collapses an open <details>, drops focus out of a button and
        // restarts every transition on the page.
        if (payload.html === this.#rendered) {
            return;
        }

        this.#rendered = payload.html;
        this.panelTarget.innerHTML = payload.html;
    }

    #schedule() {
        this.#stop();

        if (false === this.liveValue) {
            return;
        }

        this.#timer = setTimeout(() => {
            this.refresh().finally(() => this.#schedule());
        }, this.#delay());
    }

    /**
     * The interval, stretched while the endpoint keeps failing.
     *
     * The shape core/mercure_controller.js uses, and for the same reason:
     * capped so a long outage settles into a slow poll rather than an
     * ever-growing wait, and jittered so several admin tabs coming back do not
     * arrive together.
     */
    #delay() {
        if (0 === this.#failures) {
            return this.#quiet ? this.intervalValue * 4 : this.intervalValue;
        }

        const capped = Math.min(this.intervalValue * 12, this.intervalValue * 2 ** this.#failures);

        return capped / 2 + Math.random() * (capped / 2);
    }

    #stop() {
        if (null !== this.#timer) {
            clearTimeout(this.#timer);
            this.#timer = null;
        }
    }
}
