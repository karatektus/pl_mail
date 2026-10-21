import { Controller } from "@hotwired/stimulus";

/*
 * The address book docked beside the mail: whether it is open, and whether it
 * can be open beside the calendar.
 *
 * On the shell, beside the calendar's ui--split, because both of the things it
 * talks to are there: the topbar's contacts button, and the calendar boundary
 * it may have to ask for room. The dock's own resizing is a second ui--split
 * on the dock — see _contacts_pane.html.twig.
 *
 * ── One pane or two ───────────────────────────────────────────────────────
 *
 * From WIDE up (a desktop monitor) the row holds the mail, the calendar and
 * the address book side by side, and each opens and closes on its own. Below
 * it — a notebook — it holds one of them beside the mail, so opening one puts
 * the other away: the address book asks the calendar to `yield`, and a
 * calendar position chosen while the address book is open closes it. Below lg
 * there is no dock at all, and the button is the link to /contacts it always
 * was.
 *
 * The rule is enforced in app.css as well, for the first paint and for a
 * window made narrower with both open: the address book is simply not drawn
 * while the calendar is beside the mail there. This controller keeps what is
 * remembered in step with what the user did.
 */
export default class extends Controller {
    static targets = ["dock", "toggle"];
    static values = {
        open: Boolean,
        src: String,
        stateUrl: String,
        token: String,
    };

    /** Where the row starts to hold both panes. Matches app.css. */
    static WIDE = "(min-width: 1920px)";

    connect() {
        // The topbar's first-paint accent, from lg up — see the button in
        // _topbar.html.twig. _render draws the real state from here on.
        this.toggleTargets.forEach((toggle) => toggle.classList.remove("lg:bg-accent-soft", "lg:text-accent"));

        // Remembered open from a wider screen, beside a calendar this one
        // shows: the calendar keeps the room, as app.css already drew it. Not
        // written back — a smaller window is not somebody closing the pane.
        if (true === this.openValue && false === this._isWide() && true === this._calendarShowing()) {
            this._render(false);

            return;
        }

        this._render(this.openValue);
    }

    /**
     * The topbar button. Below lg it is the link to /contacts it looks like,
     * and so is a middle click or a modified one anywhere.
     */
    toggle(event) {
        if (true === this._isNarrow() || event.metaKey || event.ctrlKey || event.shiftKey || 1 === event.button) {
            return;
        }

        event.preventDefault();
        this._set(!this.openValue);
    }

    /** The pane's own × . */
    close(event) {
        event?.preventDefault();
        this._set(false);
    }

    /** The calendar moved — see ui--split's `mode` event. */
    calendarMoved(event) {
        const mode = event.detail?.mode;

        if (true === this.openValue && "mail" !== mode && false === this._isWide()) {
            this._set(false);
        }
    }

    _set(open) {
        if (true === open && false === this._isWide()) {
            this.dispatch("claim");
        }

        this._render(open);
        this._persist(open);
        window.dispatchEvent(new CustomEvent("side-pane:changed"));
    }

    _render(open) {
        this.openValue = open;

        // Below lg there is no dock, so nothing to show as open.
        const docked = open && false === this._isNarrow();

        this.toggleTargets.forEach((toggle) => {
            toggle.classList.toggle("bg-accent-soft", docked);
            toggle.classList.toggle("text-accent", docked);
            toggle.classList.toggle("text-ink-muted", !docked);
            toggle.setAttribute("aria-pressed", docked ? "true" : "false");

            const label = open ? toggle.dataset.labelClose : toggle.dataset.labelOpen;

            if (undefined !== label) {
                toggle.setAttribute("aria-label", label);
                toggle.setAttribute("title", label);
            }
        });

        if (false === this.hasDockTarget) {
            return;
        }

        // lg:flex, never flex: below lg there is no dock, open or not.
        this.dockTarget.classList.add("hidden");
        this.dockTarget.classList.toggle("lg:flex", open);
        this.dockTarget.classList.toggle("lg:w-auto", open);

        // Lazy until first shown, as the calendar's frame is; a pane rendered
        // open with the page already has its content and needs no src.
        const frame = this.dockTarget.querySelector("turbo-frame#contacts-pane-frame");

        if (true === open && frame && !frame.getAttribute("src") && null === frame.querySelector("[data-contacts-page]")) {
            frame.setAttribute("src", this.srcValue);
        }
    }

    _calendarShowing() {
        return "mail" !== (this.element.dataset.calendarMode ?? "mail");
    }

    _isWide() {
        return window.matchMedia(this.constructor.WIDE).matches;
    }

    _isNarrow() {
        return window.matchMedia("(max-width: 1023px)").matches;
    }

    /** Best-effort, as ui--split's is: a lost write costs the memory, not the layout. */
    _persist(open) {
        if (!this.hasStateUrlValue) {
            return;
        }

        const body = new FormData();
        body.append("_token", this.tokenValue);
        body.append("open", open ? "1" : "0");

        fetch(this.stateUrlValue, {
            method: "POST",
            body,
            headers: { "X-Requested-With": "fetch" },
            keepalive: true,
        }).catch(() => {});
    }
}
