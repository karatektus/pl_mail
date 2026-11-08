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
 *
 * ## The entrance
 *
 * Declared here rather than on each menu in each template, because this
 * controller is what a menu IS: every popup it will ever open then behaves the
 * same way, and a menu added next month cannot forget to. Putting the attribute
 * in eleven templates instead would mean eleven chances for one of them to feel
 * different from the other ten for no reason anybody could name.
 *
 * No JavaScript plays it and none needs to. The menu is toggled with `hidden`,
 * and a display:none element has no running animation — so the entrance starts
 * by itself every time the menu is shown, which is the one moment it should.
 * There is deliberately no exit: a menu closes because somebody asked it to,
 * and an animation there is time between the ask and the answer.
 *
 * ## Above the button
 *
 * `data-ui--dropdown-place-value="above"` opens the menu over the button that
 * was pressed rather than where the template pinned it. The compose window's
 * action bar is the case: its menus were anchored to the bar's right edge,
 * because a button's x depended on where the row wrapped — so the templates
 * panel opened at the far right, away from the button that asked for it. The
 * row no longer wraps, and the menu goes into the browser's top layer
 * (`popover="manual"`), where nothing clips it and the window's backdrop blur
 * cannot make `position: fixed` relative to the window; the place is written
 * from the button's rectangle, kept inside the screen. `manual` and not
 * `auto`: closing stays this controller's (outside click, Escape), and auto's
 * light dismiss would eat the first click inside the menu.
 *
 * openAt(anchor) opens it over another element: the compose window's ⋮ menu
 * when the button itself has been folded into it (compose--action-overflow).
 */
export default class extends Controller {
    static targets = ["menu"];
    static values = { place: String };

    connect() {
        this._boundOutside = this._handleOutside.bind(this);
        this._boundEscape = this._handleEscape.bind(this);

        // `slide-down` because that is where nearly all of these come from:
        // anchored under their trigger, so they arrive from above. Nothing
        // waits for it — only opacity and transform move, so the first item is
        // clickable in the first frame.
        //
        // A menu that already names its own entrance keeps it. The handful that
        // open UPWARD instead (the send pill's schedule list, compose's
        // formatting menus — anything positioned `bottom-full`) want `rise`,
        // and a template is the only place that knows which way a given menu
        // is pinned.
        if (this.hasMenuTarget && false === this.menuTarget.hasAttribute("data-enter")) {
            this.menuTarget.setAttribute("data-enter", "slide-down");
        }
    }

    disconnect() {
        this._detach();
    }

    toggle(event) {
        event?.preventDefault();

        this._anchor = event?.currentTarget instanceof Element ? event.currentTarget : null;
        this.menuTarget.hidden ? this.open() : this.close();
    }

    /** Open over `anchor` instead of over this dropdown's own button. */
    openAt(anchor) {
        this._anchor = anchor;
        this.open();
    }

    open() {
        this.menuTarget.hidden = false;

        if ("above" === this.placeValue) {
            this._lift();
        }

        // For menus whose contents go stale while closed — the snooze menu
        // recomputes its wake times here, so one left open across midnight
        // cannot offer a "tomorrow" that is already today.
        this.dispatch("opened", { target: this.menuTarget });

        document.addEventListener("click", this._boundOutside, { capture: true });
        document.addEventListener("keydown", this._boundEscape);
    }

    close() {
        if (true === this.menuTarget.hidden) {
            return;
        }

        this.menuTarget.hidden = true;
        this._detach();
        this._drop();

        // For a trigger that shows whether its menu is open (aria-expanded):
        // an outside click or Escape closes the menu without its owner
        // otherwise hearing of it.
        this.dispatch("closed", { target: this.menuTarget });
    }

    /** Into the top layer, over the anchor. See "Above the button". */
    _lift() {
        const menu = this.menuTarget;

        if ("function" !== typeof menu.showPopover) {
            return;
        }

        if (false === menu.hasAttribute("popover")) {
            menu.setAttribute("popover", "manual");
        }

        if (false === menu.matches(":popover-open")) {
            menu.showPopover();
        }

        this._place();

        this._boundPlace ??= () => this._place();
        window.addEventListener("resize", this._boundPlace);
    }

    _place() {
        const menu = this.menuTarget;

        if (false === menu.matches(":popover-open")) {
            return;
        }

        const margin = 8;
        const anchor = this._visible(this._anchor) ?? this._visible(this.element.querySelector("button")) ?? this.element;
        const box = anchor.getBoundingClientRect();

        menu.style.position = "fixed";
        menu.style.margin = "0";
        menu.style.inset = "auto";
        // Below md the window is the whole screen and the menu its width, as
        // the template's left-2 right-2 had it.
        menu.style.width = window.innerWidth < 768 ? `${window.innerWidth - margin * 2}px` : "";
        menu.style.maxWidth = `${window.innerWidth - margin * 2}px`;
        menu.style.maxHeight = `${Math.max(160, Math.floor(box.top - margin * 2))}px`;

        const width = menu.getBoundingClientRect().width;
        const left = Math.min(Math.max(margin, box.left), Math.max(margin, window.innerWidth - width - margin));

        menu.style.left = `${Math.round(left)}px`;
        menu.style.bottom = `${Math.round(window.innerHeight - box.top + margin)}px`;
    }

    _drop() {
        const menu = this.menuTarget;

        if (undefined !== this._boundPlace) {
            window.removeEventListener("resize", this._boundPlace);
        }

        if (false === menu.hasAttribute("popover")) {
            return;
        }

        if (menu.matches(":popover-open")) {
            menu.hidePopover();
        }

        for (const property of ["position", "margin", "inset", "width", "max-width", "max-height", "left", "bottom"]) {
            menu.style.removeProperty(property);
        }
    }

    /** The element if it is laid out, null if it is hidden (folded into ⋮). */
    _visible(element) {
        return element instanceof Element && element.getClientRects().length > 0 ? element : null;
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
