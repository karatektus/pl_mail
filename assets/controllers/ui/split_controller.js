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
 *
 * ── A boundary WITHOUT the three positions ────────────────────────────────
 *
 * The appearance settings' live preview is the second thing in the app that is
 * a resizable pane, and it is only that: it has no switch, no fullscreen, no
 * mail to get out of the way of. `modes-value="false"` turns the whole
 * position machinery off — no handoff, no demotion listener, no data-calendar-
 * mode attribute — and what is left is the part both boundaries actually share:
 * the drag, the rubber-free clamp against what the two panes have between them,
 * the arrow keys, the double-click reset and the coalesced persist.
 *
 * `variable-value` says which custom property the boundary moves, so the two
 * cannot write over each other. That is the only thing in here that ever knew
 * a pane by name.
 */
export default class extends Controller {
    static targets = ["wrapper", "pane", "handle", "main"];
    static values = {
        stateUrl: String,
        token: String,
        variable: { type: String, default: "--calendar-pane-width" },
        // Whether this boundary has the three positions at all. Without them
        // the handle is a handle and nothing else.
        modes: { type: Boolean, default: true },
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

        this._onResize = () => this._reclamp();
        window.addEventListener("resize", this._onResize);

        // A plain boundary is done here: there is no position to restore, and
        // nothing on the page for a mail click to demote.
        if (false === this.modesValue) {
            this._reclamp();

            return;
        }

        // The server renders `mail` whatever the preference says — see
        // _mailbox.html.twig — so this is where the remembered position is
        // applied, and where it is refused on a window too narrow to mean it.
        // No fetch either way: this puts on screen what is already stored, it
        // does not record a choice the user has not made.
        //
        // The handoff outranks the server's answer for one navigation: a
        // mail-bound click demotes the calendar and navigates in the same
        // tick, and the persist cannot be trusted to beat the page request to
        // the server — it lost that race in practice, and the calendar came
        // back on top of the mail that had just been asked for. sessionStorage
        // is per-tab and read-once, which is exactly the shape of "what I just
        // chose, until the server has caught up".
        const handoff = this._takeHandoff();
        let mode = handoff ?? this.modeValue;

        // The arrival-side half of the same rule. The calendar PAGE shares
        // the sidebar but has no split controller and no list frame, so a
        // mail link there is an ordinary full visit and nothing on that side
        // can stash a handoff. Landing on a mail page from anywhere that is
        // not mail, with a remembered fullscreen calendar, would cover the
        // list that was just asked for — so the arrival itself demotes, and
        // persists, because following a mail link is as much a choice as
        // pressing the switch. A reload keeps its original referrer, which is
        // why the persist matters: the second arrival reads the stored split
        // and this branch no-ops.
        if (null === handoff
            && ("calendar" === mode || ("split" === mode && this._isNarrow()))
            && this._arrivedFromOutsideMail()) {
            mode = this._isNarrow() ? "mail" : "split";
            this._persist({ mode });
        }

        this._render(this._isNarrow() && mode !== "mail" ? "mail" : mode);
        // Same reason as in _setMode: a stored width from a bigger window
        // must not squeeze the mail to a sliver on this one.
        this._reclamp();

        // The bounds are a function of the window; the width is a stored
        // number of pixels. Resize the window and the two stop agreeing — the
        // pane keeps a width the row can no longer afford, and the next drag
        // starts from limits computed for a viewport that is gone, which is
        // what made the handle stop at odd places. Re-clamp as the window
        // changes and they stay in step. (The listener itself is registered at
        // the top of connect(), so a modeless boundary gets it too.)

        // Capture phase, because the interesting clicks are on links whose
        // navigation must proceed untouched — this listener only decides what
        // is on screen when that navigation lands. See _demoteForMail.
        //
        // On DOCUMENT, not this.element: below md the sidebar is the mobile
        // drawer, which is a SIBLING of this controller's element, so a
        // listener scoped here never heard a drawer click and small screens
        // kept their fullscreen calendar. There is one split shell per page,
        // so a document listener has exactly one owner.
        this._onMailClick = (event) => this._demoteForMail(event);
        document.addEventListener("click", this._onMailClick, true);
    }

    /** Below lg the pane replaces the mail rather than sitting beside it. */
    _isNarrow() {
        return window.matchMedia("(max-width: 1023px)").matches;
    }

    /**
     * Whether the calendar is currently COVERING the mail — which is the
     * question every demotion path actually asks. `calendar` covers at every
     * width, and below lg `split` does too: the row cannot hold both panes
     * there, so app.css draws split as the calendar with the mail hidden
     * ("Split below lg is Calendar"). A check against the mode NAME alone
     * missed exactly that state, and one toggle press on a narrow window put
     * the user in a fullscreen calendar no sidebar click could demote.
     */
    _calendarCoversMail() {
        return "calendar" === this.modeValue
            || ("split" === this.modeValue && this._isNarrow());
    }

    disconnect() {
        window.removeEventListener("resize", this._onResize);

        if (this._onMailClick) {
            document.removeEventListener("click", this._onMailClick, true);
        }

        this._stopListening();
    }

    /**
     * A link to mail, followed while the calendar owns the whole row, would
     * change a list nobody can see — the mail panes are in the DOM behind the
     * calendar, and a label click used to land there invisibly. So a
     * mail-bound click demotes the calendar: to `split` when the row affords
     * both panes, to `mail` alone below lg where split is not a real place.
     *
     * "Mail-bound" is any anchor into /mail — the labels, the system views,
     * search — which is the whole reason the route prefix exists. Compose is
     * not mail-bound: it opens in its own dock above whatever is showing.
     *
     * The navigation itself is never touched. For a full page visit the mode
     * must survive the unload, which is what the keepalive on _flush is for.
     */
    _demoteForMail(event) {
        const anchor = event.target instanceof Element ? event.target.closest("a[href]") : null;

        if (null === anchor) {
            return;
        }

        let path;

        try {
            path = new URL(anchor.href, window.location.origin).pathname;
        } catch {
            return;
        }

        if (false === path.startsWith("/mail")) {
            return;
        }

        // Frame-targeting is assigned HERE, not in the templates, because
        // only the page knows whether the list frame exists. The label rows
        // are rendered by a Twig macro (which cannot see an include's
        // variables) and re-rendered by a Turbo Stream that broadcasts one
        // body to every open page at once — two renderers that structurally
        // cannot answer "is there a frame where I am going?". This one line
        // can, and it makes every mail link behave identically wherever its
        // markup came from.
        if (undefined === anchor.dataset.turboFrame && null !== document.getElementById("inbox-list-frame")) {
            anchor.dataset.turboFrame = "inbox-list-frame";
        }

        if (false === this._calendarCoversMail()) {
            return;
        }

        const mode = this._isNarrow() ? "mail" : "split";

        // The stash is for FULL page visits only, where this controller is
        // torn down and the next one must not trust the server's stale answer.
        // A frame-targeted link keeps this very controller alive — the live
        // render below is already the whole fix there, and a stash written
        // for it would linger and mis-apply to some later, unrelated
        // navigation.
        const frame = anchor.dataset.turboFrame;

        if (undefined === frame || "_top" === frame) {
            this._stashHandoff(mode);
        }

        this._setMode(mode);
    }

    /** One navigation's worth of "what I just chose". */
    static HANDOFF_KEY = "plmail.calendar-mode.handoff";

    _stashHandoff(mode) {
        try {
            window.sessionStorage.setItem(this.constructor.HANDOFF_KEY, mode);
        } catch {
            // Storage denied (privacy mode) — the persist race is then the
            // best we have, which is how it behaved before the stash existed.
        }
    }

    /**
     * Whether this page was reached from same-origin territory outside /mail.
     *
     * The primary witness is the departure record nav_origin.js writes on
     * turbo:before-visit, because document.referrer is frozen at the initial
     * page load — Turbo Drive visits never update it, which is exactly how
     * the first version of this check silently never fired. The referrer is
     * kept only as the fallback for full page loads, where it does work. No
     * witness at all (bookmark, typed URL) answers false: nothing there asked
     * for mail specifically, so the remembered mode keeps its word.
     */
    _arrivedFromOutsideMail() {
        if (false === window.location.pathname.startsWith("/mail")) {
            return false;
        }

        let origin = null;

        try {
            origin = window.sessionStorage.getItem("plmail.nav-origin");
            window.sessionStorage.removeItem("plmail.nav-origin");
        } catch {
            // Fall through to the referrer.
        }

        if (origin) {
            return false === origin.startsWith("/mail");
        }

        if (!document.referrer) {
            return false;
        }

        try {
            const referrer = new URL(document.referrer);

            return referrer.origin === window.location.origin
                && false === referrer.pathname.startsWith("/mail");
        } catch {
            return false;
        }
    }

    _takeHandoff() {
        try {
            const mode = window.sessionStorage.getItem(this.constructor.HANDOFF_KEY);
            window.sessionStorage.removeItem(this.constructor.HANDOFF_KEY);

            return this.constructor.MODES.includes(mode) ? mode : null;
        } catch {
            return null;
        }
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
        if (true === this._dragging || false === this.hasPaneTarget) {
            return;
        }

        if (true === this.modesValue && (this.modeValue !== "split" || true === this._isNarrow())) {
            return;
        }

        // A pane that is not laid out has no width to re-clamp, and clamping
        // its zero up to the minimum would throw away the stored width for the
        // rest of the session. The appearance preview is `display: none` below
        // its container query, so this is the case that needs it.
        if (null === this.paneTarget.offsetParent && "fixed" !== getComputedStyle(this.paneTarget).position) {
            return;
        }

        this._apply(this.paneTarget.getBoundingClientRect().width);

        // TWICE, and only ever twice.
        //
        // _apply bounds the pane against what the two panes have BETWEEN them,
        // measured off the two of them — which is the right thing to measure
        // right up until the row cannot hold what it was rendered with. A
        // stored width from a bigger window arrives in the first paint and the
        // main pane is squeezed to nothing making room for it, so that first
        // measurement is of a layout that does not fit, and the bound computed
        // from it is generous by however much the row was overflowing. One pass
        // gets the layout back inside the row; measuring again then measures a
        // row that fits, which is the measurement the clamp wanted in the first
        // place. It is why a pane restored from a wide screen used to settle a
        // few pixels inside the main pane's floor rather than on it.
        //
        // No loop: the second pass is against a layout that already fits, so a
        // third would measure exactly what the second did.
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

        // Entering split honours the stored width, and the stored width can
        // be one a BIGGER window chose — up to the maximum. On a window just
        // above lg that leaves the mail a sliver beside a near-fullscreen
        // calendar, which reads as the demotion not having happened at all.
        // The clamp already knows the bounds; entering the mode is as much a
        // reason to run it as resizing the window ever was.
        this._reclamp();

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
        // this is what loads the calendar. Turbo takes it from there. A pane
        // that was OPEN at page load arrives with its body embedded
        // server-side and no src at all — setting one would refetch what is
        // already on screen, which is the late-fill the embedding removed —
        // so "has content" counts as loaded the same as "has src".
        if (showsCalendar) {
            const frame = this.wrapperTarget.querySelector("turbo-frame#calendar-pane-frame");

            if (frame && !frame.getAttribute("src") && null === frame.querySelector("[data-pane-min-width]")) {
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

        // The LIST frame loading while the calendar owns the row means mail
        // navigation just happened behind a fullscreen calendar — however it
        // was triggered. The click handler catches the ordinary cases before
        // navigation; this catches whatever slipped past it (a link the
        // handler never saw, a programmatic frame visit), because the frame
        // loading is the one event no mail navigation can avoid producing.
        if ("inbox-list-frame" === frame?.id && this._calendarCoversMail()) {
            this._setMode(this._isNarrow() ? "mail" : "split");

            return;
        }

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

        // No band, no threshold — just keep where it was let go, and write it.
        // The grip's flare is cleared here as well: it says something about the
        // pointer, and the pointer has gone.
        if (false === this.modesValue) {
            this._strain = 0;
            this.handleTarget?.classList.remove("is-straining");
            this._apply(this._currentWidth(), { persist: true });

            return;
        }

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

        // Two separate things used to be one `if`, and they are not the same
        // thing.
        //
        // The rubber BAND — the pane continuing to move past its limit, and a
        // release past the threshold landing in another position — exists to
        // reach the two other positions. Without them there is nowhere past
        // the limit to go, so the limit is a hard limit and the pane stops.
        //
        // The STRAIN, which is only the grip flaring, is about the pointer and
        // not about the pane: it says "you are pushing past the end" on a
        // control two pixels wide, and that is worth saying whether or not
        // there is somewhere to land. Held back from the modeless boundary, it
        // left the appearance preview stopping dead under a pointer still
        // moving, with nothing on screen acknowledging it — which reads as a
        // drag that broke rather than one that arrived.
        if (true === this._dragging) {
            this._strain = width - bounded;

            if (true === this.modesValue) {
                drawn = bounded + this._rubber(this._strain);
            }

            this.handleTarget?.classList.toggle(
                "is-straining",
                Math.abs(this._strain) >= this.constructor.RUBBER_THRESHOLD,
            );
        }

        this.element.style.setProperty(this.variableValue, `${Math.round(drawn)}px`);

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
            // The write must survive a full page visit that starts in the same
            // tick — a label click demoting the calendar navigates immediately,
            // and without keepalive the browser cancels this fetch with the
            // page, so the next render would put the calendar back on top of
            // the mail the user just asked for.
            keepalive: true,
        })
            .catch(() => {})
            .finally(() => {
                this._inFlight = null;
                this._flush();
            });
    }
}
