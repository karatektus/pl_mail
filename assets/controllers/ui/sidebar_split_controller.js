import { Controller } from "@hotwired/stimulus";

/*
 * The boundary between the sidebar and the workspace beside it (#699): a
 * drag, the arrow keys, a double-click back to the default, and a coalesced
 * write of the width it was left at.
 *
 * It moves one custom property, `variable` (--sidebar-width), on its own
 * element; the sidebar's pane takes its width from that and the workspace is
 * flex-1 beside it, so whatever one gains the other loses.
 *
 * This was ui--split, registered a third time. That class carried the
 * calendar switch's three positions, its rubber band and its handoff between
 * pages, and the address book's and the tasks' boundaries besides; #755
 * replaced all of those with the workspace's own dividers (layout--workspace),
 * which left the sidebar as its only user. What the sidebar used of it is all
 * that is here.
 *
 * Values:
 *   stateUrl, token  — where the width is remembered (POST, `width`)
 *   variable         — the custom property it writes
 *   mainSelector     — the workspace, which is never squeezed below mainMin
 *   mainMin          — a phone's width: the list has a layout for exactly that
 *   min, max         — the sidebar's own range, as the server clamps it
 *   default          — what a double-click puts it back to
 *   step             — what an arrow key moves it by
 *   inertWhen        — while this selector matches, the width is somebody
 *                      else's: the icon rail (`.sidebar-rail` on <html>)
 *
 * Targets:
 *   pane    — the box that carries the width, and the one measured
 *   handle  — the separator
 */
export default class extends Controller {
    static targets = ["pane", "handle"];
    static values = {
        stateUrl: String,
        token: String,
        variable: { type: String, default: "--sidebar-width" },
        mainSelector: { type: String, default: "" },
        mainMin: { type: Number, default: 340 },
        min: { type: Number, default: 200 },
        max: { type: Number, default: 480 },
        default: { type: Number, default: 256 },
        step: { type: Number, default: 16 },
        inertWhen: { type: String, default: "" },
    };

    connect() {
        this._onMove = this._onMove.bind(this);
        this._onUp = this._onUp.bind(this);

        // The bounds are a function of the window and of what the workspace
        // beside it holds; the width is a stored number of pixels. When either
        // changes the two stop agreeing, so they are checked again.
        this._onResize = () => this._reclamp();
        window.addEventListener("resize", this._onResize);
        window.addEventListener("side-pane:changed", this._onResize);

        this._reclamp();
    }

    disconnect() {
        window.removeEventListener("resize", this._onResize);
        window.removeEventListener("side-pane:changed", this._onResize);
        this._stopListening();
        this._dragging = false;
        document.body.classList.remove("select-none", "cursor-col-resize");
    }

    start(event) {
        if (!this.hasPaneTarget || true === this._inert()) {
            return;
        }

        event.preventDefault();

        this._startX = event.clientX;
        this._startWidth = this.paneTarget.getBoundingClientRect().width;
        this._dragging = true;

        // Capture on the handle so the drag survives the pointer leaving it.
        this.handleTarget.setPointerCapture?.(event.pointerId);
        this._pointerId = event.pointerId;

        window.addEventListener("pointermove", this._onMove);
        window.addEventListener("pointerup", this._onUp);
        window.addEventListener("pointercancel", this._onUp);
        // A handle that loses the pointer mid-drag gets no pointerup; without
        // this the resize cursor stayed on the whole page (#755).
        this.handleTarget.addEventListener("lostpointercapture", this._onUp);

        document.body.classList.add("select-none", "cursor-col-resize");
    }

    /* Keyboard equivalent, so the separator is not mouse-only. Right is wider. */
    nudge(event) {
        const travel = { ArrowLeft: -1, ArrowRight: 1 }[event.key];

        if (travel === undefined || true === this._inert()) {
            return;
        }

        event.preventDefault();
        this._apply(this._settledWidth() + travel * this.stepValue, { persist: true });
    }

    reset(event) {
        if (true === this._inert()) {
            return;
        }

        event.preventDefault();
        this._apply(this.defaultValue, { persist: true });
    }

    // ── Private ───────────────────────────────────────────────────────────

    /**
     * The width this boundary last WROTE, rather than the one on screen. The
     * sidebar's pane carries a transition (for the rail), so five arrow presses
     * in a row each measured a box still animating towards the previous
     * press's target: five presses moved one step.
     */
    _settledWidth() {
        return this._written ?? this._currentWidth();
    }

    /** Whether something else owns the pane's width right now — see `inertWhen`. */
    _inert() {
        return "" !== this.inertWhenValue && null !== document.querySelector(this.inertWhenValue);
    }

    _onMove(event) {
        this._apply(this._startWidth + (event.clientX - this._startX));
    }

    _onUp() {
        if (true !== this._dragging) {
            return;
        }

        this._stopListening();
        this._dragging = false;
        document.body.classList.remove("select-none", "cursor-col-resize");
        this._apply(this._currentWidth(), { persist: true });
    }

    _stopListening() {
        window.removeEventListener("pointermove", this._onMove);
        window.removeEventListener("pointerup", this._onUp);
        window.removeEventListener("pointercancel", this._onUp);
        this.handleTarget?.removeEventListener("lostpointercapture", this._onUp);

        if (this._pointerId !== undefined) {
            this.handleTarget?.releasePointerCapture?.(this._pointerId);
            this._pointerId = undefined;
        }
    }

    /**
     * The stored width checked against the window it is drawn in now. Twice,
     * and only ever twice: the first pass measures a row that may be
     * overflowing with a width from a bigger window, the second a row that
     * fits — see _apply.
     */
    _reclamp() {
        if (true === this._dragging || false === this.hasPaneTarget || true === this._inert()) {
            return;
        }

        // A pane that is not laid out (below md, where the sidebar is the
        // drawer) has no width to re-clamp, and clamping its zero up to the
        // minimum would throw away the stored width for the session.
        if (null === this.paneTarget.offsetParent) {
            return;
        }

        this._apply(this.paneTarget.getBoundingClientRect().width);
        this._apply(this.paneTarget.getBoundingClientRect().width);
    }

    /*
     * Bounded from both sides, against what the sidebar and the workspace
     * share. Where both minimums cannot be met the workspace wins: it is the
     * thing the app is for.
     */
    _apply(width, { persist = false } = {}) {
        const ceiling = Math.min(this.maxValue, this._combinedWidth() - this.mainMinValue);
        const bounded = ceiling < this.minValue
            ? Math.max(0, ceiling)
            : Math.max(this.minValue, Math.min(ceiling, width));

        this._written = bounded;
        this.element.style.setProperty(this.variableValue, `${Math.round(bounded)}px`);

        if (persist) {
            this._persist({ width: String(Math.round(bounded)) });
        }
    }

    _combinedWidth() {
        const pane = this.hasPaneTarget ? this.paneTarget.getBoundingClientRect().width : 0;
        const main = "" !== this.mainSelectorValue ? document.querySelector(this.mainSelectorValue) : null;
        const mainWidth = null !== main ? main.getBoundingClientRect().width : 0;

        // Nothing beside it to protect (a page whose content is hidden): the
        // only bound left is the maximum.
        return mainWidth > 0 ? mainWidth + pane : Infinity;
    }

    _currentWidth() {
        return this.hasPaneTarget ? this.paneTarget.getBoundingClientRect().width : this.minValue;
    }

    /*
     * Coalesced: only the latest width is ever in flight or pending. Sent
     * concurrently, writes race and the server keeps whichever finished last
     * rather than whichever the user did last. Best-effort about failure: a
     * write that does not land costs the memory, never the layout.
     */
    _persist(fields) {
        if (!this.hasStateUrlValue) {
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
            keepalive: true,
        })
            .catch(() => {})
            .finally(() => {
                this._inFlight = null;
                this._flush();
            });
    }
}
