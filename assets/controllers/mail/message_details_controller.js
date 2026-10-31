import { Controller } from "@hotwired/stimulus";

/**
 * Gmail-style "details" popover next to the recipient line of a message.
 *
 * Holds three independent disclosures: the panel itself, and inside it the
 * raw header dump ("show all headers") and the filter test ("test my filters
 * on this message"). Outside-click closing follows the same capture-phase
 * pattern as label_menu_controller.
 */
export default class extends Controller {
    static targets = ["panel", "allHeaders", "allHeadersLabel", "filters", "filtersLabel", "caret"];

    /** The least height the panel is squeezed to, in pixels — see #fitIntoPane(). */
    static MIN_HEIGHT = 160;

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

    /**
     * Open or close the filter test, asking the server on every opening.
     *
     * The frame's src is set here rather than followed from a link. Turbo
     * takes over a link only from a click that reaches the document, and
     * #stop keeps every click in this panel from getting there — so a link
     * was followed by the browser and the bare fragment replaced the page.
     * Asked again each time, because the reader may have edited a filter in
     * between, and an answer about the old one is the wrong answer.
     */
    toggleFilters(event) {
        event.stopPropagation();

        if (!this.hasFiltersTarget) {
            return;
        }

        const hidden = this.filtersTarget.classList.toggle("hidden");

        if (this.hasFiltersLabelTarget) {
            const label = this.filtersLabelTarget;
            label.textContent = hidden ? label.dataset.more : label.dataset.less;
        }

        const frame = this.filtersTarget.querySelector("turbo-frame");

        if (hidden || null === frame) {
            return;
        }

        if (frame.src) {
            frame.reload();
        } else {
            frame.src = frame.dataset.src;
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
     *
     * The same for its height. With all headers and the filter test open, the
     * panel ran past the bottom of the pane and the rest was cut off, the
     * buttons that close them included. So it is capped at the room between
     * its top and the bottom of the visible pane, and scrolls inside that.
     * Never under MIN_HEIGHT: a message scrolled to the very bottom of the
     * pane would otherwise get a panel a few pixels tall.
     */
    #fitIntoPane() {
        const panel = this.panelTarget;
        const pane = this.#clippingAncestor();

        panel.style.left = "";
        panel.style.maxWidth = "";
        panel.style.maxHeight = "";

        if (null === pane) {
            return;
        }

        const margin = 8;
        const bounds = pane.getBoundingClientRect();
        const bottom = Math.min(bounds.bottom, window.innerHeight) - margin;

        panel.style.maxHeight = `${Math.max(bottom - panel.getBoundingClientRect().top, this.constructor.MIN_HEIGHT)}px`;

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
