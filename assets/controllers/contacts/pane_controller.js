import { Controller } from "@hotwired/stimulus";
import * as Turbo from "@hotwired/turbo";
import { registerPane } from "../../panes.js";

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
 *
 * ── At full size ──────────────────────────────────────────────────────────
 *
 * The full contacts page is this same shell with `data-contacts-full` on it
 * (contacts/_page_shell.html.twig): the mail column put away and the dock
 * given the row. Leaving that is `reveal`, asked for by mail--mail-pane when a
 * message is opened from a contact, and by a list loaded into the mail column
 * from the sidebar: the mail comes in from the left and the dock draws back to
 * its width, as a view transition, with the contact as it was. From then on it
 * is the mailbox with the pane open, and remembered so.
 *
 * ── Asked from elsewhere ──────────────────────────────────────────────────
 *
 * Registered in assets/panes.js as "contacts", so anything can open it on a
 * contact — "Add to contacts" does, with an open_pane stream — without
 * knowing this controller: show(), below.
 */
export default class extends Controller {
    static targets = ["dock", "toggle"];
    static values = {
        open: Boolean,
        src: String,
        stateUrl: String,
        token: String,
        inboxUrl: String,
    };

    /** Where the row starts to hold both panes. Matches app.css. */
    static WIDE = "(min-width: 1920px)";

    connect() {
        // The topbar's first-paint accent, from lg up — see the button in
        // _topbar.html.twig. _render draws the real state from here on.
        this.toggleTargets.forEach((toggle) => toggle.classList.remove("lg:bg-accent-soft", "lg:text-accent"));

        this._onReveal = (event) => {
            event.detail.done = this.reveal();
        };
        document.addEventListener("contacts-pane:reveal", this._onReveal);

        // A list loaded into the mail column — the sidebar's mail links and
        // search target its frame (see ui--split) — is somebody going to the
        // mail. The syncs refresh it by fetch, not as a frame load, so this
        // hears only a navigation.
        this._onFrameLoad = (event) => {
            if ("inbox-list-frame" === event.target.id) {
                this.reveal();
            }
        };
        document.addEventListener("turbo:frame-load", this._onFrameLoad);

        this._unregister = registerPane("contacts", {
            available: () => this.hasDockTarget && false === this._isNarrow(),
            isOpen: () => this._isFull() || true === this.openValue,
            open: ({ src } = {}) => this.show(src),
            close: () => this._set(false),
        });

        // At full size the pane is open whatever is remembered, and has the
        // calendar beside it rather than taking turns — there is no mail on
        // screen to share the row with.
        if (this._isFull()) {
            this._render(true);

            return;
        }

        // Remembered open from a wider screen, beside a calendar this one
        // shows: the calendar keeps the room, as app.css already drew it. Not
        // written back — a smaller window is not somebody closing the pane.
        if (true === this.openValue && false === this._isWide() && true === this._calendarShowing()) {
            this._render(false);

            return;
        }

        this._render(this.openValue);
    }

    disconnect() {
        document.removeEventListener("contacts-pane:reveal", this._onReveal);
        document.removeEventListener("turbo:frame-load", this._onFrameLoad);
        this._unregister?.();
    }

    /**
     * On screen, showing src if given: the pane opened on it when it was
     * closed, the pane's frame sent there when it was open. Opening is _set(),
     * as the topbar button's is, so the calendar is asked for room and the
     * choice remembered the same way.
     *
     * The frame is pointed before the pane is opened, so _render() finds a src
     * and does not start loading the list first. Asked for the page it already
     * shows, it reloads: the contact was just changed, which is why it is
     * being shown.
     *
     * @param {string} [src]
     */
    show(src) {
        const frame = this.hasDockTarget ? this.dockTarget.querySelector("turbo-frame#contacts-pane-frame") : null;

        if (frame && src) {
            if (new URL(src, location.href).href === frame.src) {
                frame.reload();
            } else {
                frame.src = src;
            }
        }

        if (false === this._isFull() && true !== this.openValue) {
            this._set(true);
        }
    }

    /**
     * The topbar button. Below lg it is the link to /contacts it looks like,
     * and so is a middle click or a modified one anywhere.
     *
     * On the full contacts page it puts the address book away, which leaves
     * the mail: the Inbox, with the pane remembered closed.
     */
    toggle(event) {
        if (true === this._isNarrow() || event.metaKey || event.ctrlKey || event.shiftKey || 1 === event.button) {
            return;
        }

        event.preventDefault();

        // After the write, not beside it: the Inbox is rendered from what is
        // remembered, and a visit that overtook the write drew the pane open.
        if (this._isFull()) {
            this._persist(false).then(() => Turbo.visit(this.inboxUrlValue));

            return;
        }
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

        // Beside the full page the two do not take turns: no mail is showing.
        if (this._isFull()) {
            return;
        }

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

    /**
     * From the full page to the mailbox with the pane open: the mail column
     * comes in from the left, the dock draws back to its own width, and the
     * contact in it is the same DOM throughout — nothing reloads, nothing
     * scrolls. A view transition, where the browser has them and motion is
     * welcome; the same change without one otherwise.
     *
     * Below lg the mail does not sit beside the pane, so there it replaces it,
     * as the mail does everything below lg, and nothing is remembered: a phone
     * is not somebody docking the address book.
     *
     * @returns {Promise<void>} once the change is in the DOM
     */
    reveal() {
        if (false === this._isFull()) {
            return Promise.resolve();
        }

        const change = () => {
            this.element.removeAttribute("data-contacts-full");

            // The frame's navigations were the page's while it had the row;
            // the address is the mail's from here on.
            this.dockTarget.querySelector("turbo-frame#contacts-pane-frame")?.removeAttribute("data-turbo-action");

            if (this._isNarrow()) {
                this._render(true);
            } else {
                this._set(true);
            }
        };

        if (
            "function" !== typeof document.startViewTransition
            || this._isNarrow()
            || window.matchMedia("(prefers-reduced-motion: reduce)").matches
        ) {
            change();

            return Promise.resolve();
        }

        // Names for the two panes, for this transition only — see app.css.
        this.element.setAttribute("data-contacts-revealing", "");

        const transition = document.startViewTransition(change);
        transition.finished.finally(() => this.element.removeAttribute("data-contacts-revealing"));

        return transition.updateCallbackDone;
    }

    _isFull() {
        return this.element.hasAttribute("data-contacts-full");
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

    /**
     * Best-effort, as ui--split's is: a lost write costs the memory, not the
     * layout. Settles either way, for a caller that has to come after it.
     */
    _persist(open) {
        if (!this.hasStateUrlValue) {
            return Promise.resolve();
        }

        const body = new FormData();
        body.append("_token", this.tokenValue);
        body.append("open", open ? "1" : "0");

        return fetch(this.stateUrlValue, {
            method: "POST",
            body,
            headers: { "X-Requested-With": "fetch" },
            keepalive: true,
        }).catch(() => {});
    }
}
