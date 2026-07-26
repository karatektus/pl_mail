import { Controller } from "@hotwired/stimulus";

/**
 * Gmail-style "details" popover next to the recipient line of a message.
 *
 * Holds two independent disclosures: the panel itself, and the raw header
 * dump inside it ("show all headers"). Outside-click closing follows the
 * same capture-phase pattern as label_menu_controller.
 */
export default class extends Controller {
    static targets = ["panel", "allHeaders", "allHeadersLabel", "caret"];

    connect() {
        this._boundClose = this._closeOnOutsideClick.bind(this);
    }

    disconnect() {
        document.removeEventListener("click", this._boundClose, { capture: true });
    }

    toggle(event) {
        event.stopPropagation();

        if (!this.panelTarget.classList.contains("hidden")) {
            this._close();
            return;
        }

        this.panelTarget.classList.remove("hidden");

        if (this.hasCaretTarget) {
            this.caretTarget.classList.add("rotate-180");
        }

        document.addEventListener("click", this._boundClose, { capture: true });
    }

    toggleAll(event) {
        event.stopPropagation();

        if (!this.hasAllHeadersTarget) {
            return;
        }

        const hidden = this.allHeadersTarget.classList.toggle("hidden");

        if (this.hasAllHeadersLabelTarget) {
            const label = this.allHeadersLabelTarget;
            label.textContent = hidden ? label.dataset.more : label.dataset.less;
        }
    }

    // ── Private ───────────────────────────────────────────────────────────

    _closeOnOutsideClick(event) {
        if (!this.element.contains(event.target)) {
            this._close();
        }
    }

    _close() {
        this.panelTarget.classList.add("hidden");

        if (this.hasCaretTarget) {
            this.caretTarget.classList.remove("rotate-180");
        }

        document.removeEventListener("click", this._boundClose, { capture: true });
    }
}
