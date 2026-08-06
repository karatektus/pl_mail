import { Controller } from "@hotwired/stimulus";

/*
 * A draggable boundary between two panes that share a row, and the three-way
 * switch that decides whether there is a boundary at all.
 *
 * Lives in ui/ rather than calendar/ because nothing here knows what a calendar
 * is — the same reasoning that puts sidebar_drawer here. It moves one number,
 * --calendar-pane-width, and sets one attribute, data-calendar-mode; the layout
 * does the rest. The docked pane takes its width from that variable and
 * .main-pane is flex-1, so whatever one gains the other loses without a second
 * number to keep in step, and every rule for the three positions lives in
 * app.css beside the attribute that selects it.
 *
 * Scoped to the row containing the sidebar, the mail panes and the calendar
 * pane, so the sidebar's toggle is inside it too.
 *
 * ── Three positions, reachable two ways ───────────────────────────────────
 *
 *   mail      no calendar
 *   split     both, divided by the handle (above lg; below it, like calendar)
 *   calendar  the calendar has the row, the mail is still in the DOM behind it
 *
 * The topbar switch cycles them. The HANDLE also reaches the two ends, which is
 * the point of the rubber band: dragging past the pane's maximum keeps moving,
 * with resistance, and letting go past a threshold puts you in `calendar`;
 * dragging past its minimum the same way puts you in `mail`. Short of the
 * threshold it settles back. A hard stop at the limit would say "this is as far
 * as it goes", which is now untrue — and a handle that simply jumped modes at
 * the limit would fire on every overshoot of a fast drag.
 *
 * Persistence is server-side (see User::SETTING_CALENDAR_PANE_MODE and
 * _WIDTH) and only on release. Writing per pointermove would be a request per
 * frame, and the width is already correct locally — the round trip is for the
 * next page load and the user's other devices, not for this one.
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
        mode: { type: String, default: "mail" },
        step: { type: Number, default: 24 },
        default: { type: Number, default: 380 },
    };

    /*
     * How far past a limit the handle can be dragged, and how far it has to go
     * before letting go changes position.
     *
     * The travel is asymptotic, so the band never runs out and never lets the
     * pane reach an impossible width: RUBBER_TRAVEL is the limit it approaches,
     * not a distance it stops at. The threshold is read off the RAW pointer
     * distance rather than off the rubbered result, because the rubbered one
     * compresses — a threshold expressed in it would be almost impossible to
     * reach deliberately and trivial to hit by accident on a fast drag, which
     * is the wrong way round for a control that hides your mail.
     */
    static RUBBER_TRAVEL = 90;
    static RUBBER_THRESHOLD = 140;

    /** The positions, in the order the switch cycles through them. */
    static MODES = ["mail", "split", "calendar"];

    connect() {
        this._onMove = this._onMove.bind(this);
        this._onUp = this._onUp.bind(this);

        // The server renders `mail` whatever the preference says — see
        // _mailbox.html.twig — so this is where the remembered position is
        // applied, and where it is refused on a window too narrow to mean it.
        // No fetch either way: this puts on screen what is already stored, it
        // does not record a choice the user has not made.
        this._render(this._isNarrow() && this.modeValue !== "mail" ? "mail" : this.modeValue);

        // The bounds are a function of the window; the width is a stored
        // number of pixels. Resize the window and the two stop agreeing — the
        // pane keeps a width the row can no longer afford, and the next drag
        // starts from limits computed for a viewport that is gone, which is
        // what made the handle stop at odd places. Re-clamp as the window
        // changes and they stay in step.
        this._onResize = () => this._reclamp();
        window.addEventListener("resize", this._onResize);
    }

    /** Below lg the pane replaces the mail rather than sitting beside it. */
    _isNarrow() {
        return window.matchMedia("(max-width: 1023px)").matches;
    }

    disconnect() {
        window.removeEventListener("resize", this._onResize);
        this._stopListening();
    }

    /**
     * Put the current width back through the clamp against the new bounds.
     *
     * Not persisted: making the window narrower is not the user asking for a
     * narrower pane, and widening it again should give back the width they did
     * ask for. Skipped while dragging, where _apply already runs per move, and
     * outside `split`, where the pane's width is not the stored one.
     */
    _reclamp() {
        if (true === this._dragging || this.modeValue !== "split" || true === this._isNarrow()) {
            return;
        }

        if (false === this.hasPaneTarget) {
            return;
        }

        this._apply(this.paneTarget.getBoundingClientRect().width);
    }

    /*
     * The switch: one press, one position along, wrapping at the end.
     *
     * The href on the trigger stays a real URL so middle-click still opens the
     * calendar page, which means preventing the default navigation is this
     * method's job.
     */
    toggle(event) {
        event.preventDefault();

        this._setMode(this._nextMode());
    }

    _nextMode() {
        const modes = this.constructor.MODES;

        return modes[(modes.indexOf(this.modeValue) + 1) % modes.length];
    }

    /**
     * Move to a position and remember it.
     *
     * The DOM change and the write are separate on purpose: _render is also
     * used on connect, where nothing has been chosen and nothing may be
     * written.
     */
    _setMode(mode) {
        this._render(mode);
        this._persist({ mode });
    }

    _render(mode) {
        this.modeValue = mode;
        this.element.dataset.calendarMode = mode;

        const showsCalendar = mode !== "mail";

        // The switch, entirely from the markup: the icon for this position is
        // shown and the other two carry `hidden`, and the accent is two utility
        // classes. Deliberately not a stylesheet rule keyed on the shell's mode
        // — a first version did that, and on a stack whose Tailwind build had
        // not been rerun all three icons drew on top of each other. A control
        // that says which mode you are in must be right without new CSS.
        this.element.querySelectorAll("[data-calendar-mode-icon]").forEach((icon) => {
            icon.toggleAttribute("hidden", icon.dataset.calendarModeIcon !== mode);
        });

        this.element.querySelectorAll("[data-calendar-toggle]").forEach((trigger) => {
            trigger.classList.toggle("bg-accent-soft", showsCalendar);
            trigger.classList.toggle("text-accent", showsCalendar);
            trigger.classList.toggle("text-ink-muted", !showsCalendar);
        });

        // The label says what pressing it does NEXT, which is the only part of
        // the control a screen reader cannot get from the icon.
        this.element.querySelectorAll("[data-calendar-mode-label]").forEach((label) => {
            label.textContent = this._labelFor(this._nextMode());
        });

        if (false === this.hasWrapperTarget) {
            return;
        }

        this.wrapperTarget.classList.toggle("hidden", !showsCalendar);
        this.wrapperTarget.classList.toggle("flex", showsCalendar);
        this.wrapperTarget.classList.toggle("w-full", showsCalendar);
        this.wrapperTarget.classList.toggle("lg:w-auto", showsCalendar);
        // lg:flex is part of the closed-below-lg state the server renders, so it
        // has to come off on close: left on, it would out-specify `hidden` at lg
        // and the pane would refuse to shut on a desktop.
        this.wrapperTarget.classList.toggle("lg:flex", showsCalendar);

        // The frame is lazy and has no src until the pane is first shown, so
        // this is what loads the calendar. Turbo takes it from there.
        if (showsCalendar) {
            const frame = this.wrapperTarget.querySelector("turbo-frame#calendar-pane-frame");

            if (frame && !frame.getAttribute("src")) {
                frame.setAttribute("src", "/calendar/pane");
            }
        }
    }

    /*
     * Read off the markup the server rendered rather than held as strings here,
     * so the three labels are translated once and in one place.
     */
    _labelFor(mode) {
        return this.element.querySelector(`[data-calendar-mode-name="${mode}"]`)?.textContent ?? "";
    }

    /**
     * The pane loaded a view, which may need more room than it has.
     *
     * Seven columns in 380px are seven slivers, so choosing Week or Month
     * widens the pane to fit rather than drawing them badly — see
     * CalendarView::minimumPaneWidth(), which is where the numbers live and
     * which renders them onto the frame.
     *
     * Only ever upward, and only in `split`: a pane somebody has dragged wider
     * than the minimum keeps its width, and switching back to Day does not take
     * it away again. A width nobody asked for is annoying; one that shrinks
     * back the moment you look at a day is worse.
     */
    paneLoaded(event) {
        const frame = event.target;

        if (frame?.id !== "calendar-pane-frame" || this.modeValue !== "split" || this._isNarrow()) {
            return;
        }

        // Read from INSIDE the frame: Turbo swaps a frame's contents and leaves
        // its own attributes alone, so a value on the <turbo-frame> tag would
        // be whatever the first load put there forever.
        const wanted = Number(frame.querySelector("[data-pane-min-width]")?.dataset.paneMinWidth ?? 0);

        if (!Number.isFinite(wanted) || wanted <= this._currentWidth()) {
            return;
        }

        this._animateTo(wanted);
    }

    /**
     * Move the boundary with a transition rather than in one frame.
     *
     * The class is only on for the length of the move — see the note in
     * start(): left on, every drag would lag a frame behind the pointer.
     */
    _animateTo(width) {
        this.paneTarget?.classList.add("is-settling");
        this._apply(width, { persist: true });
        window.setTimeout(() => this.paneTarget?.classList.remove("is-settling"), 220);
    }

    start(event) {
        if (!this.hasPaneTarget) {
            return;
        }

        event.preventDefault();

        this._startX = event.clientX;
        this._startWidth = this.paneTarget.getBoundingClientRect().width;
        this._dragging = true;
        this._strain = 0;

        // A transition left on during a drag makes the pane lag a frame behind
        // the pointer. It goes back on for the settle in _onUp.
        this.paneTarget.classList.remove("is-settling");

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

    /**
     * Letting go: either a new position, or a settle back to the limit.
     *
     * The strain is signed, and its sign is the direction the switch moves in —
     * positive is "more calendar", which is also which way the pointer went.
     */
    _onUp() {
        this._stopListening();
        this._dragging = false;
        document.body.classList.remove("select-none", "cursor-col-resize");

        const strain = this._strain ?? 0;
        this._strain = 0;
        this.handleTarget?.classList.remove("is-straining");

        if (Math.abs(strain) >= this.constructor.RUBBER_THRESHOLD) {
            // The width is left exactly where it was, deliberately: coming back
            // to `split` should give back the pane the user had, not the limit
            // they happened to drag past on the way out.
            this._apply(this._currentWidth());
            this._setMode(strain > 0 ? "calendar" : "mail");

            return;
        }

        // Short of the threshold, the band pulls back.
        this._animateTo(this._currentWidth());
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
     *
     * Past either bound the pane keeps moving, with resistance — see
     * _rubber(). That travel is applied to what is drawn and never to what is
     * stored: $strain holds the raw distance so _onUp can decide on it, and
     * _persist is only ever handed the clamped number.
     */
    _apply(width, { persist = false } = {}) {
        const combined = this._combinedWidth();
        const ceiling = Math.min(this.maxValue, combined - this.mainMinValue);
        const clamped = Math.max(this.minValue, Math.min(ceiling, width));

        // Only true on a viewport too narrow to hold both, where clamping up to
        // the pane's minimum would eat into the mail side's.
        const bounded = ceiling < this.minValue ? Math.max(0, ceiling) : clamped;

        let drawn = bounded;

        if (true === this._dragging) {
            this._strain = width - bounded;
            drawn = bounded + this._rubber(this._strain);

            this.handleTarget?.classList.toggle(
                "is-straining",
                Math.abs(this._strain) >= this.constructor.RUBBER_THRESHOLD,
            );
        }

        this.element.style.setProperty("--calendar-pane-width", `${Math.round(drawn)}px`);

        if (persist) {
            this._persist({ width: String(Math.round(bounded)) });
        }
    }

    /**
     * Asymptotic travel: `t * x / (x + t)` approaches t and never reaches it,
     * so the band always gives a little and the pane can never be dragged to a
     * width the row cannot hold. Signed, so it works at both ends.
     */
    _rubber(strain) {
        const travel = this.constructor.RUBBER_TRAVEL;
        const distance = Math.abs(strain);

        return Math.sign(strain) * ((travel * distance) / (distance + travel));
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
