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
 * ── Three positions ──────────────────────────────────────────────────────
 *
 *   mail      closed
 *   split     docked beside the mail
 *   contacts  full width, the mail behind it: the full page's layout
 *             (`data-contacts-full`) on the mailbox, so reveal() brings the
 *             mail back exactly as it does from /contacts
 *
 * The topbar button steps through them as the calendar's does (ui--split),
 * and `skip` (User::$contactsSwitchSkip) leaves one of the two open ones out.
 * The dock's handle reaches the two ends too: dragged past either limit it
 * reports `snap`, and snapped() makes that full width or closed.
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
        // The stored position — see "Three positions". `open` is whether it
        // is docked at all, what most of this file asks.
        mode: { type: String, default: "mail" },
        skip: { type: String, default: "none" },
        // The /contacts page itself, as opposed to the mailbox in `contacts`
        // position: there "less" than full width is a visit to the mail.
        page: Boolean,
        src: String,
        stateUrl: String,
        token: String,
        inboxUrl: String,
    };

    /** The positions, in the order the button steps through them. */
    static MODES = ["mail", "split", "contacts"];

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

        // Full width remembered from a bigger screen, on one with no dock: the
        // mail, as the calendar's position is demoted there. Not written back
        // — a phone is not somebody closing the pane.
        if (this._isFull() && false === this.pageValue && this._isNarrow()) {
            this.element.removeAttribute("data-contacts-full");
            this._render(false);

            return;
        }

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

        const next = this._nextMode();

        // The /contacts page has no mail behind it to go back to: anything
        // short of full width there is the mail, as a visit. After the write,
        // not beside it: the Inbox is rendered from what is remembered, and a
        // visit that overtook the write drew the pane open.
        if (this.pageValue && "contacts" !== next) {
            this._persist(next, { thenVisit: true }).then(() => Turbo.visit(this.inboxUrlValue));

            return;
        }

        this._setMode(next);
    }

    /**
     * The dock's handle let go past one of its limits (ui--side-split's
     * `snap`): past the widest, full width; past the narrowest, closed.
     */
    snapped(event) {
        this._setMode("more" === event.detail?.toward ? "contacts" : "mail");
    }

    /** Where the button goes next — CalendarPaneMode::next()'s rules. */
    _nextMode() {
        const mode = this._mode();

        if ("split" === this.skipValue) {
            return "mail" === mode ? "contacts" : "mail";
        }

        if ("contacts" === this.skipValue) {
            return "mail" === mode ? "split" : "mail";
        }

        const modes = this.constructor.MODES;

        return modes[(modes.indexOf(mode) + 1) % modes.length];
    }

    /** The position on screen now, which a reveal or a drag may have moved. */
    _mode() {
        if (this._isFull()) {
            return "contacts";
        }

        return true === this.openValue ? "split" : "mail";
    }

    /**
     * The pane's own ↗: full width, in place. A modified or middle click is
     * left to the link, which opens the page in a new tab.
     */
    expand(event) {
        if (event.metaKey || event.ctrlKey || event.shiftKey || 1 === event.button) {
            return;
        }

        event.preventDefault();
        this._setMode("contacts");
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

    /** Docked or closed, for the callers that only know those two. */
    _set(open) {
        this._setMode(open ? "split" : "mail");
    }

    _setMode(mode) {
        // Docking asks the calendar for room below WIDE, as opening always
        // did. Full width asks only when it opens from closed: from beside the
        // mail the room is already this pane's, and full width has the
        // calendar beside it rather than taking turns.
        const claims = "split" === mode || ("contacts" === mode && "mail" === this._mode());

        if (claims && false === this._isWide()) {
            this.dispatch("claim");
        }

        if (false === this.pageValue) {
            this.element.toggleAttribute("data-contacts-full", "contacts" === mode);
        }

        this._render("mail" !== mode);
        this._persist(mode);
        window.dispatchEvent(new CustomEvent("side-pane:changed"));
    }

    _render(open) {
        this.openValue = open;

        // Below lg there is no dock, so nothing to show as open.
        const docked = open && false === this._isNarrow();

        // Which position the button shows, and what pressing it does next —
        // ui--split's icons and label for the calendar, the same way: every
        // icon and name is in the template, translated, and only picked here.
        // Below lg there is no dock, so the button is the plain link it looks
        // like, in its closed position.
        const mode = docked ? this._mode() : "mail";
        const next = docked || false === this._isNarrow() ? this._nextMode() : "split";

        this.toggleTargets.forEach((toggle) => {
            toggle.classList.toggle("bg-accent-soft", docked);
            toggle.classList.toggle("text-accent", docked);
            toggle.classList.toggle("text-ink-muted", !docked);
            toggle.setAttribute("aria-pressed", docked ? "true" : "false");

            // The first paint's classes (`hidden lg:flex` and the like, see
            // _topbar.html.twig) go entirely, the plain `hidden` with them:
            // left on, it hid the icon of the position the pane was restored
            // in, and the button was an empty square. The attribute says which
            // one shows from here on, as ui--split's FIRST_PAINT_ICON does.
            toggle.querySelectorAll("[data-contacts-mode-icon]").forEach((icon) => {
                icon.className = "flex";
                icon.toggleAttribute("hidden", icon.dataset.contactsModeIcon !== mode);
            });

            const label = toggle.querySelector(`[data-contacts-mode-name="${next}"]`)?.textContent;

            if (undefined !== label) {
                toggle.setAttribute("aria-label", label);
                toggle.setAttribute("title", label);
            }
        });

        if (false === this.hasDockTarget) {
            return;
        }

        // The boundary keeps its hands off the width anywhere but beside the
        // mail — see ui--split's _reclamp().
        this.dockTarget.toggleAttribute("data-split-held", "split" !== this._mode());

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

            // The mailbox from here on, so less than full width is the pane
            // drawing back, not a visit to the Inbox (see toggle()).
            this.pageValue = false;

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
    _persist(mode, { thenVisit = false } = {}) {
        this.modeValue = mode;

        if (!this.hasStateUrlValue) {
            return Promise.resolve();
        }

        // Through the dock's boundary when there is one, so the mode and the
        // width are one writer's (ui--side-split#remember). Not before a
        // visit: that has to wait for its write, and the queue does not say
        // when it is done.
        const boundary = false === thenVisit && this.hasDockTarget
            ? this.application.getControllerForElementAndIdentifier(this.dockTarget, "ui--side-split")
            : null;

        if (null !== boundary) {
            boundary.remember({ mode });

            return Promise.resolve();
        }

        const body = new FormData();
        body.append("_token", this.tokenValue);
        body.append("mode", mode);

        return fetch(this.stateUrlValue, {
            method: "POST",
            body,
            headers: { "X-Requested-With": "fetch" },
            keepalive: true,
        }).catch(() => {});
    }
}
