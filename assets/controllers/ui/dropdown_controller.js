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
 */
export default class extends Controller {
    static targets = ["menu"];

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

        this.menuTarget.hidden ? this.open() : this.close();
    }

    open() {
        this.menuTarget.hidden = false;

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
