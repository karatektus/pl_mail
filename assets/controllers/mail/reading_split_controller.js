import { Controller } from "@hotwired/stimulus";

/**
 * The divider between the message list and the message beside it, and the one
 * number it moves: the MESSAGE's share of the mail card, in whole percent.
 *
 * Mounted on the mail card (`.main-pane`), because that is the box the share is
 * of — the list and the message together, without the sidebar or a docked
 * calendar. Both of those change the card's width, which is the reason this is
 * a percentage: a share keeps its meaning when the calendar opens, and a
 * remembered pixel width would be right in one of those states and wrong in the
 * next.
 *
 * It moves one custom property, --reading-pane-pct, and everything else follows
 * from app.css: the message takes that share of the card and the list takes
 * what is left. Whether the two are beside each other at all is a container
 * query there, not a decision made here — this controller only ever has
 * something to do while the divider is drawn.
 *
 * Why not ui--split. That controller is the calendar's boundary: it works in
 * pixels, and carries the three-position switch, the rubber band past either
 * end and the first-paint handoff between pages. None of that applies to this
 * divider and all of it would have to be switched off, in a file that has to
 * keep working for the calendar. What the two share — the drag, the arrow
 * keys, the double-click reset, the coalesced write — is small enough to say
 * again here in the units this one needs.
 *
 * Values:
 *   state-url — where the share is remembered (POST, `width`)
 *   token     — the CSRF token for that endpoint
 *   min, max  — the range in percent; the server clamps to the same one
 *   default   — what a double-click puts it back to
 *   width     — the share the page was rendered with
 *   step      — what an arrow key moves it by, in percent
 *
 * Targets:
 *   handle    — the divider
 *   list      — the message list, read for its minimum width
 *   reading   — the message pane, read for its minimum width
 *
 * Routes used:
 *   POST app_appearance_reading_pane_state
 *
 * Events:
 *   reading-split:layout (on document) — the divider appeared or went: the card
 *   became wide enough to hold both panes, or stopped being. mail--mail-pane
 *   listens, because a message opened by its address has no list until it does.
 */
export default class extends Controller {
    static targets = ["handle", "list", "reading"];
    static values = {
        stateUrl: String,
        token: String,
        min: { type: Number, default: 25 },
        max: { type: Number, default: 75 },
        default: { type: Number, default: 55 },
        width: { type: Number, default: 55 },
        step: { type: Number, default: 2 },
    };

    connect() {
        this._pct = this.widthValue;
        this._beside = this._isBeside();

        // The container query decides when the divider exists, and a resize is
        // the only thing that can change it — a window drag, the calendar pane
        // opening, the sidebar collapsing. Observed on the card itself rather
        // than listened for on the window, because the card is the thing the
        // query measures.
        this._observer = new ResizeObserver(() => this._announce());
        this._observer.observe(this.element);

        this._onMove = this._onMove.bind(this);
        this._onUp = this._onUp.bind(this);
    }

    disconnect() {
        this._observer?.disconnect();
        this._stopListening();
    }

    // ── Dragging ──────────────────────────────────────────────────────────

    start(event) {
        if (0 !== event.button) {
            return;
        }

        event.preventDefault();

        this._startX = event.clientX;
        this._startPct = this._pct;
        this._card = this.element.getBoundingClientRect().width;

        // Captured, so the drag keeps hearing the pointer when it crosses the
        // message body. That body is rendered into an iframe, which swallows
        // pointer events the moment the pointer is over it and would otherwise
        // end the drag at the divider's own edge.
        this.handleTarget.setPointerCapture(event.pointerId);
        this.handleTarget.addEventListener("pointermove", this._onMove);
        this.handleTarget.addEventListener("pointerup", this._onUp);
        this.handleTarget.addEventListener("pointercancel", this._onUp);

        this.element.dataset.readingResizing = "";
    }

    _onMove(event) {
        // The handle moves LEFT to give the message more room, so the share
        // grows as the pointer's x falls.
        const moved = ((this._startX - event.clientX) / this._card) * 100;

        this._apply(this._startPct + moved);
    }

    _onUp(event) {
        this._stopListening();
        this.handleTarget.releasePointerCapture?.(event.pointerId);

        this._persist({ width: String(this._pct) });
    }

    _stopListening() {
        if (false === this.hasHandleTarget) {
            return;
        }

        this.handleTarget.removeEventListener("pointermove", this._onMove);
        this.handleTarget.removeEventListener("pointerup", this._onUp);
        this.handleTarget.removeEventListener("pointercancel", this._onUp);

        delete this.element.dataset.readingResizing;
    }

    // ── Keyboard and reset ────────────────────────────────────────────────

    /**
     * The splitter keys: arrows by a step, Home and End to the two ends.
     *
     * Left grows the message, because that is the way the divider moves.
     */
    nudge(event) {
        const to = {
            ArrowLeft: () => this._pct + this.stepValue,
            ArrowRight: () => this._pct - this.stepValue,
            Home: () => this.minValue,
            End: () => this.maxValue,
        }[event.key];

        if (undefined === to) {
            return;
        }

        event.preventDefault();

        this._apply(to());
        this._persist({ width: String(this._pct) });
    }

    reset(event) {
        event.preventDefault();

        this._apply(this.defaultValue);
        this._persist({ width: String(this._pct) });
    }

    // ── Private ───────────────────────────────────────────────────────────

    /**
     * Put the share on screen, within what the card can actually hold.
     *
     * Bounded twice: by the range the server will accept, and by the two
     * panes' own minimum widths, which on a card near the threshold are the
     * tighter limit. Without the second, the divider kept following the
     * pointer after the panes stopped giving way, and what was stored was a
     * share the screen had never shown.
     *
     * Rounded to whole percent here rather than on the way out, so what the
     * drag showed is exactly what a reload draws; the server stores an integer.
     */
    _apply(pct) {
        const card = this.element.getBoundingClientRect().width;
        let low = this.minValue;
        let high = this.maxValue;

        if (0 < card) {
            const reading = this._pixels(this.hasReadingTarget ? this.readingTarget : null, "minWidth");
            const list = this._pixels(this.hasListTarget ? this.listTarget : null, "minWidth");
            const handle = this.hasHandleTarget ? this.handleTarget.getBoundingClientRect().width : 0;

            low = Math.max(low, Math.ceil((reading / card) * 100));
            high = Math.min(high, Math.floor(((card - list - handle) / card) * 100));
        }

        // A card too narrow for both minimums has no room to drag in. The
        // layout will have fallen back to one pane by then; leave the share as
        // it was rather than inventing one.
        if (high < low) {
            return;
        }

        this._pct = Math.round(Math.max(low, Math.min(high, pct)));

        this.element.style.setProperty("--reading-pane-pct", `${this._pct}%`);

        if (this.hasHandleTarget) {
            this.handleTarget.setAttribute("aria-valuenow", String(this._pct));
        }
    }

    /** A computed length in pixels, which is what `min-width: 24rem` resolves to. */
    _pixels(element, property) {
        if (null === element) {
            return 0;
        }

        const value = Number.parseFloat(getComputedStyle(element)[property]);

        return Number.isNaN(value) ? 0 : value;
    }

    /** Whether the divider is drawn, which is the container query's answer. */
    _isBeside() {
        return this.hasHandleTarget && "none" !== getComputedStyle(this.handleTarget).display;
    }

    _announce() {
        const beside = this._isBeside();

        if (beside === this._beside) {
            return;
        }

        this._beside = beside;

        document.dispatchEvent(new CustomEvent("reading-split:layout", { detail: { beside } }));
    }

    /**
     * Coalesced, for the reason ui--split's _persist gives: a double-click
     * resets and then a drag can follow it, and writes sent concurrently race —
     * the server keeps whichever finished last, not whichever the user did last.
     * Only the latest is ever in flight or pending.
     *
     * Best-effort about failure. A write that does not land costs the memory,
     * never the layout, which is already right locally.
     */
    _persist(fields) {
        if ("" === this.stateUrlValue) {
            return;
        }

        this._pending = { ...(this._pending ?? {}), ...fields };

        if (!this._inFlight) {
            this._flush();
        }
    }

    _flush() {
        const fields = this._pending;
        this._pending = null;

        if (!fields) {
            this._inFlight = null;

            return;
        }

        const body = new FormData();
        body.append("_token", this.tokenValue);

        Object.entries(fields).forEach(([key, value]) => body.append(key, value));

        this._inFlight = fetch(this.stateUrlValue, {
            method: "POST",
            body,
            headers: { "X-Requested-With": "fetch" },
            // Survives a page visit that starts in the same tick — the first
            // click on a row after a drag is a navigation on some paths.
            keepalive: true,
        })
            .catch(() => {})
            .finally(() => {
                this._inFlight = null;
                this._flush();
            });
    }
}
