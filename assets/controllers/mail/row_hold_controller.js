import { Controller } from "@hotwired/stimulus";
import {
    CLICK_WINDOW_MS,
    HOLD_MS,
    Intent,
    claimSequence,
    createSequence,
    releaseSequence,
} from "../../touch_gesture.js";

/**
 * Long-press a mail row on a phone to open its action sheet.
 *
 * A finger that rests on a row for HOLD_MS, moving no more than HOLD_SLOP_PX, claims
 * the sequence as "hold" and opens the sheet for that row (see mail--action-sheet). The
 * decision is the arbiter's (touch_gesture.js): this controller only supplies the
 * displacement and the time, and asks again when its own timer fires, because the
 * arbiter never sees time pass by itself.
 *
 * Mounted on #message-list, beside the row swipe and the pull, so one set of listeners
 * serves every row, including rows a refresh has just drawn. Touch only, below md.
 *
 * ┌──────────────────┬──────────────────────────────────────────────────────────┐
 * │ Actions          │ opens the sheet (mail--action-sheet) for the held row    │
 * ├──────────────────┼──────────────────────────────────────────────────────────┤
 * │ Reads            │ li[data-sheet] on the row (presence = the row has a      │
 * │                  │ sheet), the row's id                                     │
 * ├──────────────────┼──────────────────────────────────────────────────────────┤
 * │ Writes           │ draggable on thread rows: "false" on a phone (see        │
 * │                  │ TOUCH_ONLY_LIST), "true" elsewhere                       │
 * ├──────────────────┼──────────────────────────────────────────────────────────┤
 * │ Claims           │ "hold", via touch_gesture.js. A claimed sequence, or an  │
 * │                  │ open surface, refuses it. Once a hold is decided a later │
 * │                  │ drag never becomes a swipe                              │
 * ├──────────────────┼──────────────────────────────────────────────────────────┤
 * │ Routes           │ none                                                     │
 * └──────────────────┴──────────────────────────────────────────────────────────┘
 *
 * Clock: performance.now() for the origin, the samples and the timer, all on one
 * clock. event.timeStamp is not used, because the timer and the events must agree on
 * what "450ms" is, and a test clock moves only the former.
 *
 * Constants, each with its derivation:
 *   HOLD_MS = 450 and HOLD_SLOP_PX = 8 live in touch_gesture.js, with the arbiter.
 *   CLICK_WINDOW_MS = 300 (touch_gesture.js): the click a finger lifting from a hold may
 *     leave behind follows its touchend within the double-tap delay. A click inside that
 *     window after a hold is swallowed; one after it is not.
 *   HAPTIC_MS = 15: the length of the vibration when the sheet opens. navigator.vibrate
 *     exists on Android Chrome and not on iOS, where the call is simply absent.
 *
 * What it does NOT do: it does not decide what a row offers (the sheet's own data
 * does), and it does not close the sheet (that is the sheet's).
 */

/** Below md: 48rem, the same query the swipe and the pull use. */
const MOBILE = "(width < 48rem)";

/**
 * The phone's pointers: a touch screen below md. Rows are not native drags here.
 *
 * Why it is in this controller, and not in CSS or in the server's markup: a row is
 * draggable because the server says so (`draggable="true"`, for a thread that is not a
 * draft), and nothing CSS can do switches the attribute off; `-webkit-user-drag` only
 * stops the image, and a long press on iOS may lift the row as a drag, which races the
 * 450ms hold. HTML5 drag is documented as mouse-only in dnd_controller.js, so nothing a
 * phone can do is lost. The server cannot know the pointer, so the list does it.
 */
const TOUCH_ONLY_LIST = "(pointer: coarse) and (width < 48rem)";

/** The attribute the server puts on a thread row that can be dragged. See _thread_row.html.twig. */
const DRAG_SOURCE = "[data-dnd-thread]";

/** A row, as opposed to any node inside one. */
const ROW = 'li[data-controller~="mail--message-row"]';

/** Vibration when the sheet opens, in milliseconds. */
const HAPTIC_MS = 15;

/**
 * The attribute the pressed row and its overlay carry while a finger is on them. The
 * iOS callout and selection hooks in app.css key on it. CLIENT_OWNED in mail_pane, so a
 * refresh mid-press does not strip it.
 */
const PRESSED = "data-pressed";

export default class extends Controller {
    connect() {
        // The touch sequence in progress, or null: { row, sequence, origin, dx, dy, timer, held }.
        this._active = null;

        // Set when a hold opened the sheet, so the click the lifting finger may leave is swallowed.
        this._consumeClickUntil = 0;

        this.element.addEventListener("touchstart", this.#onStart, { passive: true });
        this.element.addEventListener("touchmove", this.#onMove, { passive: true });
        this.element.addEventListener("touchend", this.#onEnd, { passive: true });
        this.element.addEventListener("touchcancel", this.#onCancel, { passive: true });
        // Capture, so the overlay link's own click is seen, and stopped, before it acts.
        this.element.addEventListener("click", this.#onClick, { capture: true });
        // A long press is also a context menu on Android. While a finger is on a row, that menu is not wanted.
        this.element.addEventListener("contextmenu", this.#onContextMenu);

        // Rows are not native drags on a phone (see #syncDraggable). A refresh morphs the rows
        // back to the server's draggable="true", so this watches the attribute as well as the list.
        this._touchOnly = window.matchMedia(TOUCH_ONLY_LIST);
        this._touchOnly.addEventListener("change", this.#onTouchOnlyChange);
        this._draggable = new MutationObserver(() => this.#syncDraggable());
        this._draggable.observe(this.element, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ["draggable"],
        });
        this.#syncDraggable();
    }

    disconnect() {
        this.#abandon();

        this.element.removeEventListener("touchstart", this.#onStart);
        this.element.removeEventListener("touchmove", this.#onMove);
        this.element.removeEventListener("touchend", this.#onEnd);
        this.element.removeEventListener("touchcancel", this.#onCancel);
        this.element.removeEventListener("click", this.#onClick, { capture: true });
        this.element.removeEventListener("contextmenu", this.#onContextMenu);

        this._touchOnly.removeEventListener("change", this.#onTouchOnlyChange);
        this._draggable.disconnect();
    }

    // ── Private ───────────────────────────────────────────────────────────

    #onStart = (event) => this.#touchStart(event);
    #onMove = (event) => this.#touchMove(event);
    #onEnd = () => this.#touchEnd();
    #onCancel = () => this.#abandon();
    #onClick = (event) => this.#click(event);
    #onContextMenu = (event) => this.#contextMenu(event);
    #onTouchOnlyChange = () => this.#syncDraggable();

    /**
     * Each thread row's draggable matches the pointer: "false" on a phone, "true" elsewhere.
     * Written only where it differs, so the observer this triggers does not loop.
     */
    #syncDraggable() {
        const want = this._touchOnly.matches ? "false" : "true";

        for (const row of this.element.querySelectorAll(DRAG_SOURCE)) {
            if (row.getAttribute("draggable") !== want) {
                row.setAttribute("draggable", want);
            }
        }
    }

    #touchStart(event) {
        // A second finger is not a hold, and ends whatever a first one was.
        if (event.touches.length !== 1) {
            this.#abandon();

            return;
        }

        this._consumeClickUntil = 0;

        // A one-finger touchstart begins a new sequence, so whatever this controller still holds is
        // stale: its touchend or touchcancel may never reach us (the row it was on was removed). Its
        // timer goes, its pressed marks go, and its claim is released, before anything new is armed.
        this.#abandon();

        if (window.matchMedia(MOBILE).matches !== true) {
            return;
        }

        const row = event.target.closest?.(ROW) ?? null;

        if (row === null || this.element.contains(row) === false || row.hasAttribute("data-sheet") === false) {
            return;
        }

        const touch = event.touches[0];
        const origin = { x: touch.clientX, y: touch.clientY, t: performance.now() };

        this._active = {
            row,
            sequence: createSequence(),
            origin,
            dx: 0,
            dy: 0,
            timer: null,
            held: false,
        };

        row.setAttribute(PRESSED, "");
        this.#overlayOf(row)?.setAttribute(PRESSED, "");

        // The arbiter is asked again when the time has come: nothing else calls it while the finger is still.
        this._active.timer = setTimeout(() => this.#decide(), HOLD_MS);
    }

    #touchMove(event) {
        const active = this._active;

        if (active === null || active.held || event.touches.length !== 1) {
            return;
        }

        const touch = event.touches[0];

        active.dx = touch.clientX - active.origin.x;
        active.dy = touch.clientY - active.origin.y;

        this.#decide();
    }

    #touchEnd() {
        const active = this._active;

        if (active === null) {
            return;
        }

        this.#release(active);

        if (active.held) {
            this._consumeClickUntil = performance.now() + CLICK_WINDOW_MS;
            releaseSequence("hold");
        }

        this._active = null;
    }

    /**
     * Ask the arbiter what the sequence is now, and act on a hold. Called on every move
     * and by the timer. Once held, nothing here changes again until touchend.
     */
    #decide() {
        const active = this._active;

        if (active === null || active.held) {
            return;
        }

        // A row a refresh removed under the finger is no hold: nothing is claimed and nothing opens.
        // A row a refresh removed under the finger is no hold: nothing is claimed and nothing opens.
        if (active.row.isConnected === false) {
            this.#release(active);
            this._active = null;

            return;
        }

        const elapsed = performance.now() - active.origin.t;
        const intent = active.sequence.move(active.dx, active.dy, elapsed);

        if (intent !== Intent.HOLD) {
            // A decided swipe or scroll is the other gestures' business. This sequence is done with.
            if (intent !== Intent.UNDECIDED) {
                this.#release(active);
                this._active = null;

                return;
            }

            // Still undecided: the timer can fire a little early (performance.now() and the timer do not
            // agree to the millisecond), and nothing else would ask again. Ask again for what is left.
            this.#arm(active, HOLD_MS - elapsed);

            return;
        }

        // A claim that is refused (a swipe or pull holds the sequence, or a surface is open) means no sheet.
        if (claimSequence("hold") === false) {
            this.#release(active);
            this._active = null;

            return;
        }

        active.held = true;
        this.#release(active, { keepPressed: true });
        this.#open(active.row);
    }

    /**
     * Ask #decide again after `ms` (replacing any timer already armed). Nothing is armed when the time
     * is up already: a sequence that is still undecided then is one the arbiter will not hold.
     */
    #arm(active, ms) {
        clearTimeout(active.timer);
        active.timer = ms > 0 ? setTimeout(() => this.#decide(), ms) : null;
    }

    /** Clear the timer and, unless the hold is kept, the pressed marks. */
    #release(active, { keepPressed = false } = {}) {
        clearTimeout(active.timer);
        active.timer = null;

        if (keepPressed) {
            return;
        }

        active.row.removeAttribute(PRESSED);
        this.#overlayOf(active.row)?.removeAttribute(PRESSED);
    }

    /** A touch that is lost (the system took it, or a second finger came): nothing is left held. */
    #abandon() {
        const active = this._active;

        this._active = null;

        if (active === null) {
            return;
        }

        this.#release(active);

        if (active.held) {
            releaseSequence("hold");
        }
    }

    /** The overlay link that covers the row, which iOS would call out on. */
    #overlayOf(row) {
        return row.querySelector(":scope > a[tabindex='-1']");
    }

    /**
     * Opens the sheet for the row. The sheet is a copy of the list's one <template>, made
     * now, so a refresh that lands after this point cannot change what it shows or posts.
     */
    #open(row) {
        const template = this.element.querySelector("template[data-action-sheet-template]");

        if (template === null) {
            return;
        }

        const sheet = document.importNode(template.content.firstElementChild, true);

        sheet.setAttribute("data-mail--action-sheet-row-dom-id-value", row.id);
        document.body.append(sheet);

        navigator.vibrate?.(HAPTIC_MS);
    }

    /** While a finger is on a row, the browser's own long-press menu is refused. */
    #contextMenu(event) {
        if (this._active !== null) {
            event.preventDefault();
        }
    }

    /** The click a finger lifted from a hold leaves behind, swallowed so the reading pane does not open. */
    #click(event) {
        if (performance.now() > this._consumeClickUntil) {
            return;
        }

        this._consumeClickUntil = 0;

        event.preventDefault();
        event.stopPropagation();
    }
}
