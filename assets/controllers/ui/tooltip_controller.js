import {Controller} from "@hotwired/stimulus";

/**
 * One tooltip for the whole application.
 *
 * Mounted on <body> and never unmounted, it replaces the browser's native
 * `title` tooltip everywhere at once: that one waits about a second, renders in
 * an unthemed black box, and cannot be styled at all. Every `title` in the app
 * gets this instead, with no per-element markup — which is the point, because
 * the alternative was a bespoke tooltip span copied into each template (there
 * were two, both with a 500ms delay, both clipped by their own container).
 *
 * ## How the native one is suppressed
 *
 * There is no API for it. The only way is to take the attribute off the element
 * while the pointer is on it, so `title` is moved to `data-tooltip` the first
 * time an element is hovered and never put back. `aria-describedby` then points
 * at the bubble, so assistive technology still reads it — moving the attribute
 * without that would quietly remove the description from the accessibility
 * tree, which native `title` does provide.
 *
 * ## Why the bubble lives on <body>
 *
 * Because `position: fixed` is not fixed to the viewport inside an ancestor
 * with `backdrop-filter` — it anchors to that ancestor instead, and the panes
 * use it. A bubble rendered next to its trigger would be positioned against the
 * wrong box and clipped by the pane's bounds. One element, appended to <body>,
 * moved to wherever it is needed, avoids all of it.
 */

/**
 * Long enough not to strobe while the pointer sweeps across a toolbar, short
 * enough to read as immediate. The native delay this replaces is ~1000ms.
 */
const SHOW_DELAY_MS = 60;

/** Gap between the trigger and the bubble. */
const OFFSET = 8;

/** Keeps the bubble off the viewport edge when a trigger sits near one. */
const MARGIN = 8;

export default class extends Controller {
    connect() {
        this.bubble = null;
        this.timer = null;
        this.current = null;

        // Delegated, so elements Turbo swaps in later are covered without any
        // re-binding — including the ones that do not exist yet.
        this._onEnter = (event) => this._maybeShow(event.target);
        this._onLeave = (event) => this._maybeHide(event.target);
        this._onKeydown = (event) => {
            if (event.key === "Escape") {
                this._hide();
            }
        };

        // Capture: mouseenter/leave do not bubble, and focus events are the
        // keyboard equivalent — a tooltip only reachable by pointer is one half
        // of the users missing out.
        document.addEventListener("mouseover", this._onEnter);
        document.addEventListener("mouseout", this._onLeave);
        document.addEventListener("focusin", this._onEnter);
        document.addEventListener("focusout", this._onLeave);
        document.addEventListener("keydown", this._onKeydown);

        // Anything that moves the trigger invalidates the position, and there
        // is no cheap way to follow it. Hiding is honest and unnoticeable.
        this._onDismiss = () => this._hide();
        window.addEventListener("scroll", this._onDismiss, true);
        window.addEventListener("resize", this._onDismiss);
        document.addEventListener("click", this._onDismiss);
        document.addEventListener("turbo:before-render", this._onDismiss);
    }

    disconnect() {
        document.removeEventListener("mouseover", this._onEnter);
        document.removeEventListener("mouseout", this._onLeave);
        document.removeEventListener("focusin", this._onEnter);
        document.removeEventListener("focusout", this._onLeave);
        document.removeEventListener("keydown", this._onKeydown);
        window.removeEventListener("scroll", this._onDismiss, true);
        window.removeEventListener("resize", this._onDismiss);
        document.removeEventListener("click", this._onDismiss);
        document.removeEventListener("turbo:before-render", this._onDismiss);

        this._hide();
        this.bubble?.remove();
        this.bubble = null;
    }

    /**
     * The nearest ancestor carrying a tooltip, so hovering the <i> inside a
     * button still finds the button's own text.
     */
    _triggerFor(target) {
        if (!(target instanceof Element)) {
            return null;
        }

        return target.closest("[title], [data-tooltip]");
    }

    _maybeShow(target) {
        const trigger = this._triggerFor(target);

        if (trigger === null || trigger === this.current) {
            return;
        }

        // Taking `title` off now is what stops the native bubble appearing
        // underneath ours a second later.
        const native = trigger.getAttribute("title");

        if (native !== null && native !== "") {
            trigger.dataset.tooltip = native;
            trigger.removeAttribute("title");
        }

        const text = trigger.dataset.tooltip;

        if (!text) {
            return;
        }

        this.current = trigger;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this._show(trigger, text), SHOW_DELAY_MS);
    }

    _maybeHide(target) {
        const trigger = this._triggerFor(target);

        if (trigger !== null && trigger === this.current) {
            this._hide();
        }
    }

    _show(trigger, text) {
        // Gone from the document between the hover and the timer firing —
        // Turbo swaps make that ordinary rather than exceptional.
        if (!trigger.isConnected) {
            this._hide();

            return;
        }

        const bubble = this._ensureBubble();
        bubble.textContent = text;
        bubble.id ||= "app-tooltip";
        trigger.setAttribute("aria-describedby", bubble.id);

        // Measured while visible but not yet placed, so the size is real.
        bubble.dataset.state = "measuring";
        this._position(trigger, bubble);
        bubble.dataset.state = "visible";
    }

    _position(trigger, bubble) {
        const anchor = trigger.getBoundingClientRect();
        const box = bubble.getBoundingClientRect();

        // Below by default; above when there is no room, which is what a
        // trigger in the last row of a long list needs.
        let top = anchor.bottom + OFFSET;

        if (top + box.height > window.innerHeight - MARGIN) {
            top = anchor.top - box.height - OFFSET;
        }

        const centred = anchor.left + anchor.width / 2 - box.width / 2;
        const left = Math.min(
            Math.max(MARGIN, centred),
            window.innerWidth - box.width - MARGIN,
        );

        bubble.style.top = `${Math.max(MARGIN, top)}px`;
        bubble.style.left = `${left}px`;
    }

    _hide() {
        clearTimeout(this.timer);
        this.current?.removeAttribute("aria-describedby");
        this.current = null;

        if (this.bubble !== null) {
            this.bubble.dataset.state = "hidden";
        }
    }

    _ensureBubble() {
        if (this.bubble === null) {
            this.bubble = document.createElement("div");
            this.bubble.className = "app-tooltip";
            this.bubble.setAttribute("role", "tooltip");
            // Body-level, for the backdrop-filter reason in the class comment.
            document.body.appendChild(this.bubble);
        }

        return this.bubble;
    }
}
