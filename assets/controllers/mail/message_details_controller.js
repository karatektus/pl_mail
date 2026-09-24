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
        this.#fitIntoPane();
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
     * Keep the panel inside the pane it opens in.
     *
     * It hangs from the recipient line at a fixed 28rem, and the reading pane
     * clips whatever leaves it. Beside the docked calendar the pane is
     * narrower than the line's offset plus the panel, so the right edge of
     * every row was cut away, addresses included. So the panel moves left by
     * what does not fit, and is narrowed only when the pane itself is narrower
     * than the panel. Measured on every open, since the calendar can be docked
     * or closed in between.
     */
    #fitIntoPane() {
        const panel = this.panelTarget;
        const pane = this.#clippingAncestor();

        panel.style.left = "";
        panel.style.maxWidth = "";

        if (null === pane) {
            return;
        }

        const margin = 8;
        const bounds = pane.getBoundingClientRect();
        const room = bounds.width - 2 * margin;

        if (panel.getBoundingClientRect().width > room) {
            panel.style.maxWidth = `${room}px`;
        }

        const box = panel.getBoundingClientRect();
        const overflow = box.right - (bounds.right - margin);

        if (overflow > 0) {
            panel.style.left = `${-Math.min(overflow, box.left - (bounds.left + margin))}px`;
        }
    }

    /** The nearest ancestor that clips: the one the panel has to fit inside. */
    #clippingAncestor() {
        for (let element = this.element.parentElement; null !== element; element = element.parentElement) {
            const style = getComputedStyle(element);

            if ("visible" !== style.overflowX || "visible" !== style.overflowY) {
                return element;
            }
        }

        return null;
    }

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
