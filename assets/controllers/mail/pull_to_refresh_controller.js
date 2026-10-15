import { Controller } from "@hotwired/stimulus";
import { motionIsOff } from "../../motion.js";
import { Intent, claimSequence, createSequence, releaseSequence } from "../../touch_gesture.js";

/**
 * Pull the mail list down from its top to sync, on a phone.
 *
 * The list is the only thing that moves, and it does not move: the indicator is
 * what slides down with the finger, so no row is touched while a pull is in
 * progress. Release past the threshold runs the topbar's sync button, through its
 * own controller, and the indicator rests in view with a spinning icon while that
 * button reports a run in progress, whichever control started it.
 *
 * Mounted on #message-list, outside the list frame, so it survives a frame swap.
 * Touches are read on that element, and the rows scroller is found at touchstart
 * with closest(), because a frame visit replaces the scroller itself.
 *
 * Touch only, below md (invariant 1). Under a mouse nothing here runs.
 *
 * ┌─────────────────┬─────────────────────────────────────────────────────────┐
 * │ Targets         │ indicator (the slide with the finger), icon (spins)    │
 * ├─────────────────┼─────────────────────────────────────────────────────────┤
 * │ Reaches         │ the topbar's mail--sync-now controller, by lookup, to  │
 * │                 │ call run(): its running guard and toast, not a click   │
 * ├─────────────────┼─────────────────────────────────────────────────────────┤
 * │ Listens for     │ mail--sync-now:started / :stopped on the document      │
 * ├─────────────────┼─────────────────────────────────────────────────────────┤
 * │ Routes          │ none: the sync is the sync button's own POST           │
 * └─────────────────┴─────────────────────────────────────────────────────────┘
 *
 * Constants, all derived from the owner-confirmed defaults:
 *   PULL_RESISTANCE = 0.5. The indicator shows finger travel x 0.5.
 *   RELEASE_DISPLAYED_PX = 70. Letting go past it syncs. Finger travel that
 *     reaches it is 70 / 0.5 = 140px. So a finger of 180px releases (displayed
 *     90) and one of 120px does not (displayed 60).
 *   SYNC_RESTING_PX = 48. Where the indicator rests while a run is open.
 *
 * Thresholds are measured on finger displacement from touchstart, never on the
 * rendered translation, so easing does not move them.
 *
 * A pull ends in one of three ways, and only the first can sync:
 *   - touchend, of the finger that was pulling: release past the threshold syncs;
 *   - touchcancel (the system took the touch), or a second finger landing during
 *     the pull: the pull is ABORTED. The indicator returns to idle, the claim is
 *     released, and nothing syncs;
 *   - no touchend or touchcancel ever arrives: the element the finger was on was
 *     removed mid-pull, so events stop reaching this element. The next one-finger
 *     touchstart abandons the stale pull the same way an abort does, and nothing syncs.
 *
 * Touch moves are listened to with passive: false only while a sequence might be
 * a pull: the listener is added at touchstart when the list is at its top, and
 * removed as soon as the sequence is decided against a pull, or when it ends. A
 * touchmove that is part of a pull is cancelable, and preventDefault is called
 * only then, so a scroll that is not a pull is left to the browser.
 *
 * Reduced motion: the indicator's slide is removed under motionIsOff(), so a
 * release snaps back rather than animating. Thresholds are unchanged.
 *
 * What is NOT here: the row-swipe and sheet gestures (later changes). They share
 * the arbiter in touch_gesture.js, and this controller claims its sequence through
 * claimSequence("pull"), so a claimed or open-surface sequence is never pulled.
 */

/** Displayed distance per pixel of finger travel. A pull resists by half. */
const PULL_RESISTANCE = 0.5;

/** Displayed distance at which letting go starts a sync. */
const RELEASE_DISPLAYED_PX = 70;

/**
 * Where the indicator rests while a run is open, measured from the top of the list.
 * The pill is 25px tall (6px padding each side around a 13px line), so its top is
 * 48 - 25 = 23px below the list's top: in view, clear of the edge, and below the
 * topbar's own line. Resting at 0 would leave it hidden, which is what this fixes.
 */
const SYNC_RESTING_PX = 48;

/** The list's scroller, as the list frame draws it. */
const ROWS = '[data-list-region="rows"]';

/** The topbar's sync button. Its controller's run() is the sync, not a click on the button. */
const SYNC_BUTTON = "[data-controller~='mail--sync-now']";

/** Below md: 48rem, the mobile_search_controller's query. */
const MOBILE = "(width < 48rem)";

const STATE = Object.freeze({
    IDLE: "idle",
    PULLING: "pulling",
    RELEASE: "release",
    SYNCING: "syncing",
});

/**
 * Where the indicator rests, as a transform. Hidden when idle; at the pull's own
 * distance while pulling; at SYNC_RESTING_PX while a run is open, so the spinner is
 * in view for as long as the sync is.
 */
function restingTransform(state, displayed) {
    if (state === STATE.IDLE) {
        return "";
    }

    const travel = state === STATE.SYNCING ? SYNC_RESTING_PX : displayed;

    return `translateY(calc(-100% + ${travel}px))`;
}

export default class extends Controller {
    static targets = ["indicator", "icon"];

    connect() {
        this._syncing = false;
        this._pulling = false;
        this._claimed = false;
        this._sequence = null;
        this._rows = null;
        this._displayed = 0;

        this._onStart = this._touchStart.bind(this);
        this._onMove = this._touchMove.bind(this);
        this._onEnd = this._touchEnd.bind(this);
        this._onCancel = this._touchCancel.bind(this);
        this._onStarted = () => this._setSyncing(true);
        this._onStopped = () => this._setSyncing(false);

        this.element.addEventListener("touchstart", this._onStart, { passive: true });
        this.element.addEventListener("touchend", this._onEnd, { passive: true });
        this.element.addEventListener("touchcancel", this._onCancel, { passive: true });
        document.addEventListener("mail--sync-now:started", this._onStarted);
        document.addEventListener("mail--sync-now:stopped", this._onStopped);

        this._render();
    }

    disconnect() {
        this._abort();

        this.element.removeEventListener("touchstart", this._onStart);
        this.element.removeEventListener("touchend", this._onEnd);
        this.element.removeEventListener("touchcancel", this._onCancel);
        document.removeEventListener("mail--sync-now:started", this._onStarted);
        document.removeEventListener("mail--sync-now:stopped", this._onStopped);
    }

    // ── Private ───────────────────────────────────────────────────────────

    /**
     * A sequence may become a pull only if it starts on the rows scroller, while
     * the list is at its very top. The scrollTop test is in one place, here, so
     * the decision and the arming cannot disagree about it.
     */
    _mayStart() {
        return this._rows !== null && this._rows.scrollTop === 0;
    }

    _touchStart(event) {
        // A second finger, or more, ends any pull in progress. It is never a pull, and
        // whatever the first finger does afterwards cannot sync it.
        if (event.touches.length !== 1) {
            this._abort();

            return;
        }

        // A one-finger touchstart begins a new sequence, so whatever this controller still
        // holds is stale: its touchend or touchcancel may never reach us, because the
        // element the finger was on was removed mid-gesture. Abandon it, with no sync.
        this._abort();

        this._sequence = null;
        this._rows = null;

        if (window.matchMedia(MOBILE).matches !== true) {
            return;
        }

        const rows = event.target.closest?.(ROWS);

        if (!rows || !this.element.contains(rows)) {
            return;
        }

        const touch = event.touches[0];

        this._rows = rows;
        this._origin = { x: touch.clientX, y: touch.clientY, at: event.timeStamp };
        this._sequence = createSequence();

        if (this._mayStart()) {
            // Armed for this sequence only. See the class docblock on why this is
            // added here and not permanently.
            this.element.addEventListener("touchmove", this._onMove, { passive: false });
        }
    }

    _touchMove(event) {
        if (this._sequence === null || event.touches.length !== 1) {
            return;
        }

        const touch = event.touches[0];
        const dx = touch.clientX - this._origin.x;
        const dy = touch.clientY - this._origin.y;

        if (this._pulling === false) {
            const intent = this._sequence.move(dx, dy, event.timeStamp - this._origin.at);

            if (intent === Intent.UNDECIDED) {
                return;
            }

            // Decided, and not a pull: a vertical move downward, from the top, with
            // nothing else claiming the sequence. Anything else is left to the browser.
            if (intent !== Intent.VERTICAL || dy <= 0 || this._mayStart() === false || claimSequence("pull") === false) {
                this._disarm();

                return;
            }

            this._claimed = true;
            this._pulling = true;
        }

        if (event.cancelable) {
            event.preventDefault();
        }

        this._displayed = Math.max(0, dy * PULL_RESISTANCE);
        this._render();
    }

    /**
     * touchend: the finger lifted. Only a pull this controller holds can sync, and
     * only past the threshold. A touchend for a sequence that never claimed releases
     * nothing: the claim, if any, belongs to someone else.
     */
    _touchEnd() {
        this._disarm();

        if (this._claimed === false) {
            return;
        }

        // The rows scroller was replaced during the pull (a frame swap, or a refresh that
        // rebuilt the list): the pull is no longer over the list it started on, so it aborts.
        if (this._rows.isConnected === false) {
            this._abort();

            return;
        }

        const released = this._displayed >= RELEASE_DISPLAYED_PX;

        this._release();

        if (released) {
            this._syncNow();
        }

        this._displayed = 0;
        this._render();
    }

    /** touchcancel: the system took the touch. The pull is aborted, and nothing syncs. */
    _touchCancel() {
        this._abort();
    }

    /**
     * The button's own run(): its running guard and its toast decide, so a pull
     * during a sync is refused exactly as a click would be. A disabled button is
     * not a guard here, because nothing clicks it.
     */
    _syncNow() {
        const button = document.querySelector(SYNC_BUTTON);

        if (button === null) {
            return;
        }

        this.application.getControllerForElementAndIdentifier(button, "mail--sync-now")?.run();
    }

    /** Stop listening for this sequence's moves, and forget it. */
    _disarm() {
        this.element.removeEventListener("touchmove", this._onMove);
        this._sequence = null;
    }

    /** Give back this controller's claim, if it holds one. Never another consumer's. */
    _release() {
        if (this._claimed) {
            releaseSequence("pull");
            this._claimed = false;
        }

        this._pulling = false;
    }

    /** A pull that did not end in a release: listeners off, claim released, indicator home. No sync. */
    _abort() {
        this._disarm();
        this._release();
        this._displayed = 0;
        this._render();
    }

    _setSyncing(on) {
        this._syncing = on;
        this._render();
    }

    _state() {
        if (this._syncing) {
            return STATE.SYNCING;
        }

        if (this._pulling) {
            return this._displayed >= RELEASE_DISPLAYED_PX ? STATE.RELEASE : STATE.PULLING;
        }

        return STATE.IDLE;
    }

    _render() {
        if (this.hasIndicatorTarget === false) {
            return;
        }

        const state = this._state();
        const indicator = this.indicatorTarget;

        indicator.dataset.state = state;
        indicator.style.transform = restingTransform(state, this._displayed);
        // Under reduced motion the slide back is a jump, not an animation.
        indicator.style.transition = motionIsOff() ? "none" : "";

        if (this.hasIconTarget) {
            this.iconTarget.classList.toggle("fa-spin", state === STATE.SYNCING);
        }
    }
}
