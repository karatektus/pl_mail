import { Controller } from "@hotwired/stimulus";

/**
 * A small click-to-open menu.
 *
 * The compose window already has bespoke open/close logic for the from-address
 * picker, written before there was a second menu to justify sharing anything.
 * This is the generic version for menus that need nothing beyond "show it, and
 * close it again when the user looks away".
 *
 * Outside clicks are caught in the capture phase so the menu closes even when
 * the thing clicked stops propagation on its way up.
 */
export default class extends Controller {
    static targets = ["menu"];

    connect() {
        this._boundOutside = this._handleOutside.bind(this);
        this._boundEscape = this._handleEscape.bind(this);
    }

    disconnect() {
        this._detach();
    }

    toggle(event) {
        event?.preventDefault();

        this.menuTarget.hidden ? this.open() : this.close();
    }

    open() {
        this.menuTarget.hidden = false;

        document.addEventListener("click", this._boundOutside, { capture: true });
        document.addEventListener("keydown", this._boundEscape);
    }

    close() {
        if (true === this.menuTarget.hidden) {
            return;
        }

        this.menuTarget.hidden = true;
        this._detach();
    }

    _handleOutside(event) {
        if (false === this.element.contains(event.target)) {
            this.close();
        }
    }

    _handleEscape(event) {
        if ("Escape" === event.key) {
            this.close();
        }
    }

    _detach() {
        document.removeEventListener("click", this._boundOutside, { capture: true });
        document.removeEventListener("keydown", this._boundEscape);
    }
}
