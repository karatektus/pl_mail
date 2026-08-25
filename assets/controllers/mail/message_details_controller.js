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
        this.#header()?.setAttribute("data-details-open", "");

        if (this.hasCaretTarget) {
            this.caretTarget.classList.add("rotate-180");
        }

        document.addEventListener("click", this._boundClose, { capture: true });
    }

    /**
     * Anything clicked inside the panel stops there.
     *
     * The panel lives inside the message's own click target, so a click that
     * bubbles collapses the message the reader just opened — taking the panel
     * with it. Every control in here would otherwise have to remember to stop
     * for itself, which the copy buttons duly did not, and the symptom is a
     * button that appears to do nothing because its own container vanished.
     */
    stop(event) {
        event.stopPropagation();
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

    /**
     * The sticky header this panel hangs from, where there is one — the
     * single-message view includes the same partial outside a conversation and
     * has nothing to raise.
     */
    #header() {
        return this.element.closest(".sticky");
    }

    _closeOnOutsideClick(event) {
        if (!this.element.contains(event.target)) {
            this._close();
        }
    }

    _close() {
        this.panelTarget.classList.add("hidden");
        this.#header()?.removeAttribute("data-details-open");

        if (this.hasCaretTarget) {
            this.caretTarget.classList.remove("rotate-180");
        }

        document.removeEventListener("click", this._boundClose, { capture: true });
    }
}
