import { Controller } from "@hotwired/stimulus";

/**
 * The docked compose window's size, set by dragging its top-left corner and
 * kept per device (#507).
 *
 * Per device and not per account, because the right size belongs to the
 * screen: a laptop and the monitor beside it want different windows, and an
 * account setting would make one of them wrong. So it lives in localStorage,
 * like the sidebar rail, and the script in _layout/app.html.twig puts it on
 * <html> before the first paint, so a window does not open at 520 × 600 and
 * jump.
 *
 * The size is two custom properties on <html>, `--compose-w` and
 * `--compose-h`, which the window's classes read with the old size as the
 * fallback (compose/_window.html.twig). On <html> rather than on the window:
 * every docked window shares them, a window opened later starts at the size
 * the last one was given, and the pre-paint script has nothing else to reach.
 *
 * The top-left corner because the dock is anchored bottom-right: the window
 * grows up and to the left, away from the corner that stays put. Below md the
 * window is the whole screen and the handle is not shown; an inline reply is
 * as wide as its thread and has no handle either. The CSS cap that keeps the
 * window above an on-screen keyboard (`.compose-dock .compose-window` in
 * app.css) still applies on top of whatever height is set here.
 *
 * Double-click puts the default back. A pointer gesture only: the window
 * works at any size, so the handle stays out of the Tab order rather than
 * being a stop that does nothing from the keyboard.
 */
export const STORAGE_KEY = "plmail:composeSize";

const MIN_WIDTH = 420;
const MIN_HEIGHT = 360;
const MARGIN = 32;

export default class extends Controller {
    connect() {
        this._boundMove = this._move.bind(this);
        this._boundEnd = this._end.bind(this);
    }

    disconnect() {
        this._detach();
    }

    start(event) {
        if (0 !== event.button) {
            return;
        }

        event.preventDefault();

        const box = this._window().getBoundingClientRect();

        this._drag = { x: event.clientX, y: event.clientY, width: box.width, height: box.height };
        this.element.setPointerCapture?.(event.pointerId);
        this.element.addEventListener("pointermove", this._boundMove);
        this.element.addEventListener("pointerup", this._boundEnd);
        this.element.addEventListener("pointercancel", this._boundEnd);
        document.documentElement.classList.add("compose-resizing");
    }

    /** Back to the window's own size, and forget the one kept. */
    reset() {
        document.documentElement.style.removeProperty("--compose-w");
        document.documentElement.style.removeProperty("--compose-h");

        try {
            localStorage.removeItem(STORAGE_KEY);
        } catch (_) {
            // Storage refused (private mode): the size still resets for this page.
        }
    }

    _move(event) {
        if (undefined === this._drag) {
            return;
        }

        // Up and to the left is bigger: the window's bottom-right corner is
        // the one that stays where it is.
        this._apply(
            this._drag.width + (this._drag.x - event.clientX),
            this._drag.height + (this._drag.y - event.clientY),
        );
    }

    _end(event) {
        this.element.releasePointerCapture?.(event.pointerId);
        this._detach();
        this._drag = undefined;
        this._save();
    }

    _apply(width, height) {
        const w = Math.round(Math.min(Math.max(MIN_WIDTH, width), Math.max(MIN_WIDTH, window.innerWidth - MARGIN)));
        const h = Math.round(Math.min(Math.max(MIN_HEIGHT, height), Math.max(MIN_HEIGHT, window.innerHeight * 0.9)));

        document.documentElement.style.setProperty("--compose-w", `${w}px`);
        document.documentElement.style.setProperty("--compose-h", `${h}px`);
    }

    _save() {
        const style = document.documentElement.style;
        const w = parseInt(style.getPropertyValue("--compose-w"), 10);
        const h = parseInt(style.getPropertyValue("--compose-h"), 10);

        if (false === Number.isFinite(w) || false === Number.isFinite(h)) {
            return;
        }

        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({ w, h }));
        } catch (_) {
            // Storage refused: the size holds for this page and is not kept.
        }
    }

    _window() {
        return this.element.closest(".compose-window") ?? this.element;
    }

    _detach() {
        this.element.removeEventListener("pointermove", this._boundMove);
        this.element.removeEventListener("pointerup", this._boundEnd);
        this.element.removeEventListener("pointercancel", this._boundEnd);
        document.documentElement.classList.remove("compose-resizing");
    }
}
