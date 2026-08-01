import { Controller } from "@hotwired/stimulus";

/*
 * A draggable boundary between two panes that share a row.
 *
 * Lives in ui/ rather than calendar/ because nothing here knows what a calendar
 * is — the same reasoning that puts sidebar_drawer here. It moves one number,
 * --calendar-pane-width, and the layout does the rest: the docked pane takes
 * its width from that variable and .main-pane is flex-1, so whatever one gains
 * the other loses without a second number to keep in step.
 *
 * Scoped to the row containing the sidebar, the mail panes and the calendar
 * pane, so the sidebar's toggle is inside it too.
 *
 * Persistence is server-side (see User::SETTING_CALENDAR_PANE_WIDTH) and only
 * on release. Writing per pointermove would be a request per frame, and the
 * width is already correct locally — the round trip is for the next page load
 * and the user's other devices, not for this one.
 */
export default class extends Controller {
    static targets = ["wrapper", "pane", "handle", "main"];
    static values = {
        stateUrl: String,
        token: String,
        min: { type: Number, default: 320 },
        max: { type: Number, default: 900 },
        // What the mail side is never dragged below — a phone's width, not a
        // comfortable one. The list has a stacked layout for exactly this size
        // and it should be reachable by dragging: a floor set at "still looks
        // like a desktop list" meant the handle stopped before the layout it
        // was heading for, and on a 1024px screen it barely moved at all.
        // The pane's own minimum still stops it going further.
        mainMin: { type: Number, default: 340 },
        open: { type: Boolean, default: false },
        step: { type: Number, default: 24 },
        default: { type: Number, default: 380 },
    };

    connect() {
        this._onMove = this._onMove.bind(this);
        this._onUp = this._onUp.bind(this);
    }

    disconnect() {
        this._stopListening();
    }

    /*
     * Open or close the pane. The href on the trigger stays a real URL so
     * middle-click still opens the page, which means preventing the default
     * navigation is this method's job.
     */
    /*
     * One behaviour at every width. The pane opens beside the mail where there
     * is room and takes the row where there is not — see the
     * [data-calendar-open] rule in app.css — rather than the trigger meaning
     * two different things depending on the window. Closing it puts the mail
     * back exactly as it was, which navigating away could not.
     *
     * The href stays a real URL so middle-click still opens the page, which
     * means suppressing the navigation is this method's job.
     */
    toggle(event) {
        event.preventDefault();

        this.openValue = !this.openValue;

        if (!this.hasWrapperTarget) {
            return;
        }

        this.element.dataset.calendarOpen = this.openValue ? "true" : "false";

        // The trigger is a toggle, so it says so. aria-pressed drives both the
        // styling and what a screen reader announces — one attribute rather
        // than a class plus a separate state nobody would keep in step.
        this.element
            .querySelectorAll("[data-calendar-toggle]")
            .forEach((trigger) => trigger.setAttribute("aria-pressed", String(this.openValue)));

        this.wrapperTarget.classList.toggle("hidden", !this.openValue);
        this.wrapperTarget.classList.toggle("flex", this.openValue);
        this.wrapperTarget.classList.toggle("w-full", this.openValue);
        this.wrapperTarget.classList.toggle("lg:w-auto", this.openValue);

        // The frame is lazy and has no src until the pane is first opened, so
        // opening it is what loads the calendar. Turbo takes it from there.
        if (this.openValue) {
            const frame = this.wrapperTarget.querySelector("turbo-frame#calendar-pane-frame");

            if (frame && !frame.getAttribute("src")) {
                frame.setAttribute("src", "/calendar/pane");
            }
        }

        this._persist({ open: this.openValue ? "1" : "0" });
    }

    start(event) {
        if (!this.hasPaneTarget) {
            return;
        }

        event.preventDefault();

        this._startX = event.clientX;
        this._startWidth = this.paneTarget.getBoundingClientRect().width;

        // Capture on the handle so the drag survives the pointer leaving it —
        // without this, moving faster than the layout reflows drops the drag.
        this.handleTarget.setPointerCapture?.(event.pointerId);
        this._pointerId = event.pointerId;

        window.addEventListener("pointermove", this._onMove);
        window.addEventListener("pointerup", this._onUp);
        window.addEventListener("pointercancel", this._onUp);

        document.body.classList.add("select-none", "cursor-col-resize");
    }

    /* Keyboard equivalent, so the separator is not mouse-only. */
    nudge(event) {
        const direction = { ArrowLeft: 1, ArrowRight: -1 }[event.key];

        if (direction === undefined) {
            return;
        }

        event.preventDefault();
        this._apply(this._currentWidth() + direction * this.stepValue, { persist: true });
    }

    reset(event) {
        event.preventDefault();
        this._apply(this.defaultValue, { persist: true });
    }

    _onMove(event) {
        // The pane is on the right, so dragging left makes it wider.
        this._apply(this._startWidth + (this._startX - event.clientX));
    }

    _onUp() {
        this._stopListening();
        document.body.classList.remove("select-none", "cursor-col-resize");

        this._persist({ width: String(Math.round(this._currentWidth())) });
    }

    _stopListening() {
        window.removeEventListener("pointermove", this._onMove);
        window.removeEventListener("pointerup", this._onUp);
        window.removeEventListener("pointercancel", this._onUp);

        if (this._pointerId !== undefined) {
            this.handleTarget?.releasePointerCapture?.(this._pointerId);
            this._pointerId = undefined;
        }
    }

    /*
     * Bounded from both sides, against what the two panes actually share.
     *
     * The pane is a fixed width and the mail side is flex-1, so their combined
     * width is whatever the row has left after the sidebar and stays constant
     * as the boundary moves — which makes it the right thing to measure. The
     * earlier version bounded the pane at half the ROW, which counted the
     * sidebar as space the panes had and let the mail side be squeezed to a
     * column of truncated subjects on a narrower screen.
     *
     * Where the two minimums cannot both be met, the mail side wins: it is the
     * thing the app is for, and the pane can be closed.
     */
    _apply(width, { persist = false } = {}) {
        const combined = this._combinedWidth();
        const ceiling = Math.min(this.maxValue, combined - this.mainMinValue);
        const clamped = Math.max(this.minValue, Math.min(ceiling, width));

        // Only true on a viewport too narrow to hold both, where clamping up to
        // the pane's minimum would eat into the mail side's.
        const bounded = ceiling < this.minValue ? Math.max(0, ceiling) : clamped;

        this.element.style.setProperty("--calendar-pane-width", `${Math.round(bounded)}px`);

        if (persist) {
            this._persist({ width: String(Math.round(bounded)) });
        }
    }

    _combinedWidth() {
        const pane = this.hasPaneTarget ? this.paneTarget.getBoundingClientRect().width : 0;
        const main = this.hasMainTarget ? this.mainTarget.getBoundingClientRect().width : 0;

        // No main pane to measure (the calendar page itself) — fall back to the
        // row, which is the same thing minus a sidebar.
        return main > 0
            ? main + pane
            : this.element.getBoundingClientRect().width;
    }

    _currentWidth() {
        return this.hasPaneTarget
            ? this.paneTarget.getBoundingClientRect().width
            : this.minValue;
    }

    /*
     * Coalesced, not fired-and-forgotten and not queued one behind another.
     *
     * Several of these fire in quick succession — a double-click to reset emits
     * pointerup twice before the reset itself, and a drag right after emits
     * another. Sent concurrently they race, and the server keeps whichever
     * finished last rather than whichever the user did last, so the pane comes
     * back at a width nobody chose.
     *
     * Chaining fixes the order but makes it worse in the case that matters: the
     * newest write, the one the user actually made, waits behind three stale
     * ones and is the first thing lost if they navigate. So intermediate states
     * are dropped instead — only the latest is ever in flight or pending, which
     * is both correct and at most two requests.
     *
     * Still best-effort about failure: a write that does not land costs the
     * memory, never the layout, which is already right locally.
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
        })
            .catch(() => {})
            .finally(() => {
                this._inFlight = null;
                this._flush();
            });
    }
}
