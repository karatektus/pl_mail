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

/**
 * Gap between the trigger's edge and the bubble. Wide enough for the caret to
 * sit in without the bubble crowding the pointer.
 */
const OFFSET = 12;

/**
 * Minimum gap measured from the trigger's *centre* rather than its edge.
 *
 * An edge offset alone assumes the trigger has some size. It does for a button,
 * where centre-to-edge already pushes the bubble clear of the pointer — but the
 * status dot is 0.4rem across, so its edge is barely off its centre and the
 * bubble landed almost under the cursor. Taking whichever of the two is larger
 * spaces the small ones properly and leaves everything else exactly where it
 * was, with no per-element markup to keep in sync.
 *
 * `data-tooltip-offset` (in px) overrides it for a one-off that needs more.
 */
const MIN_CENTRE_DISTANCE = 24;

/** Half the caret's width — it is a 6px border triangle. */
const CARET = 6;

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

        // A trigger can leave the page while the pointer is still on it, and
        // then nothing above fires: `mouseout` needs an element to leave, and
        // an element that has been removed never sends one. The compose window
        // is the case that showed it — hover the trash icon, send the message,
        // and the bubble saying "Delete draft" stays painted over the message
        // list indefinitely, outliving the button it describes by minutes.
        //
        // The document-level `click` above is what usually papers over this
        // (any click anywhere hides the bubble), which is why it looks
        // intermittent: it only survives when the window closes without one —
        // Ctrl+Enter to send, a keyboard shortcut, or a turbo-stream arriving
        // on its own. Verified by sending from the keyboard, where the bubble
        // was still `state=visible, opacity=1` afterwards.
        //
        // `turbo:before-render` does not cover it either: that fires for full
        // page renders, and the compose window closes by turbo-STREAM, which
        // is a different event. Watching the DOM catches every route at once,
        // including the ones nobody has thought of yet.
        this._observer = new MutationObserver(() => {
            if (this.current !== null && !this.current.isConnected) {
                this._hide();
            }
        });
        this._observer.observe(document.body, { childList: true, subtree: true });
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
        this._observer?.disconnect();

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

        if (!text || this._alreadySaysIt(trigger, text)) {
            return;
        }

        if (this._opensAVisibleMenu(trigger)) {
            return;
        }

        this.current = trigger;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this._show(trigger, text), SHOW_DELAY_MS);
    }

    /**
     * Is this trigger the button of a menu that is currently open?
     *
     * Clicking a dropdown trigger focuses it, and `focusin` is one of the
     * events that shows a tooltip — so opening the user menu painted its own
     * hint ("Account") across the top of the menu it had just opened. The
     * document-level click handler hides the bubble, but the focus arrives
     * after it, so hiding alone never fixed this; the show has to be refused
     * while the menu is up.
     *
     * Structural rather than an aria check, because ui--dropdown does not set
     * aria-expanded — it toggles `hidden` on its menu target. Reading that is
     * reading the same state the controller acts on, and it covers every
     * dropdown on the page rather than the one that was reported.
     *
     * A hint explaining a button is useful right up until the button has been
     * pressed and its answer is on screen; past that it is describing something
     * the user is already looking at.
     */
    _opensAVisibleMenu(trigger) {
        const dropdown = trigger.closest('[data-controller~="ui--dropdown"]');

        if (dropdown === null) {
            return false;
        }

        const menu = dropdown.querySelector('[data-ui--dropdown-target="menu"]');

        return menu !== null && false === menu.hidden;
    }

    /**
     * Is the element already showing this text?
     *
     * The sidebar links are the case that prompted it: each carries a `title`
     * repeating the word printed right next to the icon, so hovering "Inbox"
     * popped up a bubble saying "Inbox". A hint that restates what is on screen
     * is noise, and there were eight of them stacked down the left edge.
     *
     * Deliberately a rule rather than deleting those attributes. Collapsed to
     * the icon rail the label text is hidden (`.rail-hide`), and then the title
     * is the only thing identifying the icon — so the same markup has to behave
     * differently depending on a class toggled at runtime, which the template
     * cannot know. `innerText` is what makes this work: it reports rendered text
     * only, so the hidden span simply is not in it.
     *
     * `includes` rather than equality because the badge counts sit in the same
     * element — "Inbox 4" still says Inbox.
     */
    _alreadySaysIt(trigger, text) {
        const shown = (trigger.innerText || "").replace(/\s+/g, " ").trim();

        return shown !== "" && shown.includes(text.trim());
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

        const centreY = anchor.top + anchor.height / 2;
        const minCentre = Number(trigger.dataset.tooltipOffset) || MIN_CENTRE_DISTANCE;

        // Below by default; above when there is no room, which is what a
        // trigger in the last row of a long list needs. Both edges are held at
        // least `minCentre` from the trigger's middle, which is what keeps a
        // tiny trigger from wearing the bubble.
        let top = Math.max(anchor.bottom + OFFSET, centreY + minCentre);
        let placement = "below";

        if (top + box.height > window.innerHeight - MARGIN) {
            top = Math.min(anchor.top - OFFSET, centreY - minCentre) - box.height;
            placement = "above";
        }

        const centred = anchor.left + anchor.width / 2 - box.width / 2;
        const left = Math.min(
            Math.max(MARGIN, centred),
            window.innerWidth - box.width - MARGIN,
        );

        bubble.style.top = `${Math.max(MARGIN, top)}px`;
        bubble.style.left = `${left}px`;
        bubble.dataset.placement = placement;

        // The caret points at the trigger, not at the middle of the bubble.
        // Those are the same thing only until the bubble is pushed off centre
        // by a viewport edge — for a control in the far corner of the topbar
        // that is most of the time, and a caret aimed at nothing looks broken.
        // Kept a caret's width inside each end so it never overhangs a corner.
        const anchorCentre = anchor.left + anchor.width / 2;
        const caretX = Math.min(
            Math.max(CARET + MARGIN, anchorCentre - left),
            box.width - CARET - MARGIN,
        );

        bubble.style.setProperty("--caret-x", `${caretX}px`);
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
