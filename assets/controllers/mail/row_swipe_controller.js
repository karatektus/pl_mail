import { Controller } from "@hotwired/stimulus";
import { motionIsOff } from "../../motion.js";
import {
    DIRECTION_LOCK_PX,
    Intent,
    claimSequence,
    createSequence,
    registerSurface,
    releaseSequence,
    unregisterSurface,
} from "../../touch_gesture.js";

/**
 * Swipe a mail row on a phone: right for its read action, left for archive.
 *
 * The row's directions and what they do are decided on the server (SwipeActionResolver):
 * a direction with no panel on a row is inert there, and this controller reads
 * that presence and nothing else. Its job is the gesture: lock the direction,
 * follow the finger, decide on release, and post the panel's own URL through
 * the row's mail--message-row, which is the one write path a row has.
 *
 * Mounted on #message-list, outside the list frame, so one set of listeners
 * serves every row, including rows a refresh has just drawn. A row that is
 * under a finger or open is marked (data-swipe-owned), and mail--mail-pane's
 * morph leaves a marked row alone until it is released.
 *
 * Touch only, below md (invariant 1). Under a mouse nothing here runs.
 *
 * ┌──────────────────┬──────────────────────────────────────────────────────────┐
 * │ Actions          │ commit: the revealed button (click), and a fling or a    │
 * │                  │ full drag, which commit without a tap                    │
 * ├──────────────────┼──────────────────────────────────────────────────────────┤
 * │ Reads            │ [data-swipe-panel="left|right"] (presence = that         │
 * │                  │ direction is live on the row), [data-swipe-content],     │
 * │                  │ the button's data-swipe-url and data-swipe-body          │
 * ├──────────────────┼──────────────────────────────────────────────────────────┤
 * │ Writes via       │ mail--message-row#perform, the row's own write path     │
 * ├──────────────────┼──────────────────────────────────────────────────────────┤
 * │ Registers        │ the "row" surface (touch_gesture.js): an open row        │
 * │                  │ refuses a pull, and a touch on it is its own business    │
 * ├──────────────────┼──────────────────────────────────────────────────────────┤
 * │ Routes           │ none of its own                                          │
 * └──────────────────┴──────────────────────────────────────────────────────────┘
 *
 * Constants, each a fraction or a px figure, with its derivation:
 *   PARTIAL_FRACTION = 0.35 of the row's width: release past it opens the row on
 *     its action.
 *   FULL_FRACTION = 0.70: release past it commits without a tap.
 *   FLING_PX_PER_MS = 0.5: a release at or above this speed, in the swipe's
 *     direction, commits, provided the finger has gone past PARTIAL.
 *   VELOCITY_WINDOW_MS = 100: the speed is measured over the last 100ms of
 *     samples, back from the release. A finger that stopped more than that before
 *     lifting releases at speed 0, so a drop reads as a drop.
 *   REST_PX = 96: how far an open row rests, which is the width of its button.
 *     In px, not rem, so the rest and the button cannot drift apart under a font
 *     preference.
 *
 * Thresholds are on finger displacement from touchstart, never on the drawn
 * offset, so easing and the rest position do not move them. An open row is
 * different in one respect: it is closed by a drag back past PARTIAL the other
 * way, and a drag that only nudges it further open keeps it open.
 *
 * Touches that start outside an open row close it, and are consumed: the sequence
 * claims "dismiss", which holds it until the finger lifts, so no pull and no swipe
 * can take it. A tap on the open row itself closes it too, and the click that
 * follows is consumed, so the row beneath does not open.
 *
 * Reduced motion: the drawn offset jumps rather than easing, under motionIsOff().
 */

/** Release past this fraction of the row's width opens the row. */
const PARTIAL_FRACTION = 0.35;

/** Release past this fraction of the row's width commits without a tap. */
const FULL_FRACTION = 0.7;

/** Speed, in px/ms, at which a release is a fling. */
const FLING_PX_PER_MS = 0.5;

/** The window, in ms, the release speed is measured over. */
const VELOCITY_WINDOW_MS = 100;

/** How far an open row rests: the width of its revealed button. */
const REST_PX = 96;

/** The name this controller registers as a surface, see touch_gesture.js. */
const SURFACE = "row";

/** The sequence that only closes an open row. Its claim is "dismiss". */
const DISMISS = "dismiss";

/** A row, as opposed to any node inside one. */
const ROW = 'li[data-controller~="mail--message-row"]';

/** The part of the row a swipe moves. */
const CONTENT = "[data-swipe-content]";

/** The panel a direction draws, present only where the direction is live. */
const PANEL = "[data-swipe-panel]";

/** The revealed button, which commits its own panel. */
const COMMIT = "[data-swipe-commit]";

/** Below md: 48rem, the same query the pull gesture uses. */
const MOBILE = "(width < 48rem)";

/** Which way each side's displacement runs, as a sign on finger movement. */
const SIGN = Object.freeze({ left: -1, right: 1 });

/**
 * Clamp a drawn offset to the side it is on, and to the row's width. A drag on
 * the left side never draws the row to the right, and the other way round.
 */
function clampOffset(offset, side, width) {
    return side === "left" ? Math.max(-width, Math.min(0, offset)) : Math.min(width, Math.max(0, offset));
}

/**
 * The finger's speed at release, in px/ms, measured over the last
 * VELOCITY_WINDOW_MS of samples. Positive is the direction of the finger's last
 * move, so the sign is what the fling test reads. Zero when nothing moved in the
 * window, which is what a drop looks like.
 */
function releaseVelocity(samples, endAt) {
    const recent = samples.filter((sample) => sample.t >= endAt - VELOCITY_WINDOW_MS);

    if (recent.length === 0 || samples.length < 2) {
        return 0;
    }

    const last = samples[samples.length - 1];
    const first = recent.length >= 2 ? recent[0] : samples[samples.length - 2];

    return last.t === first.t ? 0 : (last.x - first.x) / (last.t - first.t);
}

/**
 * What a release on a drag does. The thresholds are displacement from touchstart,
 * and an open row is closed by a drag back past partial, not by a nudge further open.
 *
 * @returns {"commit"|"open"|"close"}
 */
function outcomeOf({ along, speed, width, onOpenRow }) {
    if (along >= FULL_FRACTION * width) {
        return "commit";
    }

    if (speed >= FLING_PX_PER_MS && along >= PARTIAL_FRACTION * width) {
        return "commit";
    }

    if (onOpenRow) {
        return along <= -PARTIAL_FRACTION * width ? "close" : "open";
    }

    return along >= PARTIAL_FRACTION * width ? "open" : "close";
}

export default class extends Controller {
    connect() {
        // { row, side } for the open row, or null. The surface predicate reads this, so a
        // row that has left the document no longer counts as open.
        this._open = null;

        // The touch sequence in progress, or null. See #touchStart.
        this._active = null;

        // Set by a touch that closed a row, so the click that follows it is not a click on
        // what is beneath. Cleared by the next touch, or by the click it consumes.
        this._consumeClick = false;

        this.element.addEventListener("touchstart", this.#onStart, { passive: true });
        this.element.addEventListener("touchmove", this.#onMove, { passive: true });
        this.element.addEventListener("touchend", this.#onEnd, { passive: true });
        this.element.addEventListener("touchcancel", this.#onCancel, { passive: true });
        // Capture, so a click on a row's overlay link is seen before the link acts on it.
        this.element.addEventListener("click", this.#onClick, { capture: true });

        // Past the phone width the gesture is off, so an open row must not stay open there: a
        // rotation to a desktop width closes it, as sidebar_drawer_controller does at its own breakpoint.
        this._mobile = window.matchMedia(MOBILE);
        this._onBreakpoint = () => {
            if (this._mobile.matches === false) {
                this.#close();
            }
        };
        this._mobile.addEventListener("change", this._onBreakpoint);

        registerSurface(SURFACE, () => this._open !== null && this._open.row.isConnected);
    }

    disconnect() {
        this.#abandon();
        this.#close();

        this._mobile.removeEventListener("change", this._onBreakpoint);

        this.element.removeEventListener("touchstart", this.#onStart);
        this.element.removeEventListener("touchmove", this.#onMove);
        this.element.removeEventListener("touchend", this.#onEnd);
        this.element.removeEventListener("touchcancel", this.#onCancel);
        this.element.removeEventListener("click", this.#onClick, { capture: true });

        unregisterSurface(SURFACE);
    }

    // ── Private ───────────────────────────────────────────────────────────

    #onStart = (event) => this.#touchStart(event);
    #onMove = (event) => this.#touchMove(event);
    #onEnd = (event) => this.#touchEnd(event);
    #onCancel = () => this.#abandon();
    #onClick = (event) => this.#click(event);

    #touchStart(event) {
        this._consumeClick = false;

        // A second finger ends whatever this sequence was, and is not a swipe itself.
        if (event.touches.length !== 1) {
            this.#abandon();

            return;
        }

        // A one-finger touchstart begins a new sequence, so whatever this controller still
        // holds is stale: its touchend or touchcancel may never reach us, because the element
        // the finger was on was removed mid-drag. Abandon it, and release only its own claim.
        this.#abandon();

        if (window.matchMedia(MOBILE).matches !== true) {
            return;
        }

        const row = event.target.closest?.(ROW) ?? null;
        const open = this.#openRow()?.row ?? null;

        // A touch outside the open row closes it, and the sequence goes no further.
        const touch = event.touches[0];
        const origin = { x: touch.clientX, y: touch.clientY, t: event.timeStamp };

        if (open !== null && open !== row) {
            this.#close();

            this._active = { row: null, origin, maxReach: 0, claimed: claimSequence(DISMISS, { except: SURFACE }) };

            return;
        }

        if (row === null || this.element.contains(row) === false || row.querySelector(PANEL) === null) {
            return;
        }

        this._active = {
            row,
            sequence: createSequence(),
            origin,
            // The revealed button the touch began on, if any: a tap on it commits that panel.
            button: event.target.closest?.(COMMIT) ?? null,
            onOpenRow: open === row,
            maxReach: 0,
            dragging: false,
            ignored: false,
            claimed: false,
            side: null,
            width: 0,
            startOffset: 0,
            last: 0,
            samples: [],
        };
    }

    #touchMove(event) {
        const active = this._active;

        if (active === null || event.touches.length !== 1) {
            return;
        }

        const touch = event.touches[0];
        const dx = touch.clientX - active.origin.x;
        const dy = touch.clientY - active.origin.y;

        active.maxReach = Math.max(active.maxReach, Math.abs(dx), Math.abs(dy));

        // A dismissal has nothing to drag: it only has to be consumed.
        if (active.row === null || active.ignored) {
            return;
        }

        if (active.dragging === false) {
            const intent = active.sequence.move(dx, dy, event.timeStamp - active.origin.t);

            if (intent === Intent.UNDECIDED) {
                return;
            }

            // Only a horizontal decision is a swipe. A vertical one is a scroll or a pull, and
            // must not claim the sequence here: the pull is refused by the claim, not by luck.
            // An open row moves on its own side, whichever way the finger went: a drag back is
            // how it closes. A closed row locks to the side the finger went.
            const horizontal = intent === Intent.LEFT || intent === Intent.RIGHT;
            const lock = !horizontal ? null : active.onOpenRow && this._open !== null ? this._open.side : sideOf(intent);

            if (lock === null || active.row.querySelector(`[data-swipe-panel="${lock}"]`) === null || claimSequence("swipe", { except: SURFACE }) === false) {
                active.ignored = true;

                return;
            }

            active.dragging = true;
            active.claimed = true;
            active.side = lock;
            active.width = active.row.getBoundingClientRect().width;
            active.startOffset = active.onOpenRow ? SIGN[lock] * REST_PX : 0;
        }

        active.last = dx;
        active.samples.push({ x: dx, t: event.timeStamp });

        this.#render(active.row, active.side, clampOffset(active.startOffset + dx, active.side, active.width), true);
    }

    #touchEnd(event) {
        const active = this._active;

        this._active = null;

        if (active === null) {
            return;
        }

        if (active.row === null) {
            releaseSequence(DISMISS);

            // A tap outside the row leaves a click behind it; a drag does not.
            this._consumeClick = active.maxReach < DIRECTION_LOCK_PX;

            return;
        }

        if (active.claimed === false) {
            // A tap on the open row closes it, and the click after it is not the row's to take.
            // A tap on the revealed button is that button's commit, by touch, exactly as its click
            // is: the click the browser then synthesises is consumed, so the button posts once.
            if (active.onOpenRow && active.maxReach < DIRECTION_LOCK_PX) {
                this.#close();

                if (active.button !== null) {
                    this.#post(active.row, active.button.dataset.swipeUrl, JSON.parse(active.button.dataset.swipeBody || "{}"));
                }

                this._consumeClick = true;
            }

            return;
        }

        releaseSequence("swipe");

        // The row left the document, and this touchend arrived anyway: the touch did not stay on the
        // removed node, so nothing is committed and nothing is shown. A removed row whose touch ended
        // elsewhere never reaches this point; #touchStart abandons that stale sequence instead.
        if (active.row.isConnected === false) {
            this._open = this._open?.row === active.row ? null : this._open;

            return;
        }

        const sign = SIGN[active.side];
        const outcome = outcomeOf({
            along: sign * active.last,
            speed: sign * releaseVelocity(active.samples, event.timeStamp),
            width: active.width,
            onOpenRow: active.onOpenRow,
        });

        if (outcome === "commit") {
            this.#close();
            this.#reset(active.row);
            this.#commitPanel(active.row, active.side);

            return;
        }

        if (outcome === "open") {
            this.#close();
            this.#open(active.row, active.side);

            return;
        }

        this.#close();
        this.#reset(active.row);
    }

    /**
     * Drop whatever sequence is in progress. A drag that was following the finger
     * settles where it was, so an open row stays open and a closed one returns home.
     */
    #abandon() {
        const active = this._active;

        this._active = null;

        if (active === null) {
            return;
        }

        if (active.row === null) {
            releaseSequence(DISMISS);

            return;
        }

        if (active.claimed) {
            releaseSequence("swipe");
        }

        if (active.row.isConnected === false || active.claimed === false) {
            return;
        }

        if (this._open !== null && this._open.row === active.row) {
            this.#open(active.row, this._open.side);
        } else {
            this.#reset(active.row);
        }
    }

    /**
     * The revealed button's click, commits its own URL and body on this row. Handled
     * here rather than by a Stimulus action on the button, so the markup carries no
     * action attribute for every row. The close comes first, so the row is back at
     * rest before the stream redraws it.
     *
     * Otherwise, a click the last touch asked to be consumed is stopped here.
     */
    #click(event) {
        // The click a consumed touch leaves behind, whatever it landed on. A touch that committed
        // the button has already posted, so its click must not post again.
        if (this._consumeClick) {
            this._consumeClick = false;

            event.preventDefault();
            event.stopPropagation();

            return;
        }

        const commit = event.target.closest?.(COMMIT);

        if (commit) {
            const row = commit.closest(ROW);

            event.stopPropagation();

            if (row !== null) {
                this.#close();
                this.#post(row, commit.dataset.swipeUrl, JSON.parse(commit.dataset.swipeBody || "{}"));
            }
        }
    }

    /** Rest the row open on its side, and remember it. */
    #open(row, side) {
        this._open = { row, side };
        this.#render(row, side, SIGN[side] * REST_PX, false);
    }

    /** Close the open row, if there is one. */
    #close() {
        const open = this.#openRow();

        this._open = null;

        if (open !== null) {
            this.#reset(open.row);
        }
    }

    /**
     * The open row, or null. A row that a refresh has removed from the document is
     * not open any more, and is forgotten here, so it cannot make the next touch on
     * the list look like a touch outside an open row.
     */
    #openRow() {
        if (this._open !== null && this._open.row.isConnected === false) {
            this._open = null;
        }

        return this._open;
    }

    /** Post the panel's own URL and body through the row's write path. */
    #commitPanel(row, side) {
        const button = row.querySelector(`[data-swipe-panel="${side}"] ${COMMIT}`);

        if (button === null) {
            return;
        }

        this.#post(row, button.dataset.swipeUrl, JSON.parse(button.dataset.swipeBody || "{}"));
    }

    #post(row, url, body) {
        this.application.getControllerForElementAndIdentifier(row, "mail--message-row")?.perform(url, body);
    }

    /**
     * Draw the row at an offset, on a side. The owned marker is set first, so a
     * refresh that lands now is held off this row (see mail--mail-pane's _mayMorph).
     */
    #render(row, side, offset, dragging) {
        row.dataset.swipeOwned = "";
        row.dataset.swipeSide = side;
        row.style.setProperty("--swipe-reveal", `${Math.abs(offset)}px`);

        const content = row.querySelector(CONTENT);

        if (content === null) {
            return;
        }

        content.style.transform = offset === 0 ? "" : `translateX(${offset}px)`;
        // No easing while the finger is on the row, and none at all under reduced motion.
        content.style.transition = dragging || motionIsOff() ? "none" : "";
    }

    /** Take the row back to rest, and release the marker that kept a refresh off it. */
    #reset(row) {
        delete row.dataset.swipeOwned;
        delete row.dataset.swipeSide;
        row.style.removeProperty("--swipe-reveal");

        const content = row.querySelector(CONTENT);

        if (content !== null) {
            content.style.transform = "";
            // The stylesheet eases every transform on the content (180ms), so a reset under reduced
            // motion must say "none" outright, or the close and the snap back would still animate.
            content.style.transition = motionIsOff() ? "none" : "";
        }
    }
}

/** The side a horizontal intent locks to, or null for a vertical or a hold. */
function sideOf(intent) {
    if (intent === Intent.LEFT) {
        return "left";
    }

    if (intent === Intent.RIGHT) {
        return "right";
    }

    return null;
}
