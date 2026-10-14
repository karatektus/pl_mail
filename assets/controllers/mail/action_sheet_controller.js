import { Controller } from "@hotwired/stimulus";
import { askConfirm } from "../../confirm.js";
import { motionIsOff } from "../../motion.js";
import { CLICK_WINDOW_MS, registerSurface, unregisterSurface } from "../../touch_gesture.js";

/**
 * The long-press action sheet for one mail row.
 *
 * A copy of the list's one <template> (rendered in _mailbox.html.twig, outside every
 * list region), made by mail--row-hold when a hold opens it and appended to <body>. The
 * copy is taken once, at connect: the row's entries are read from the row's data-sheet
 * attribute at that moment, and the URLs the sheet posts are those captured then. A
 * refresh that morphs the list afterwards cannot change what the open sheet does.
 *
 * What it shows is the server's decision (SheetAffordance, via SwipeActionResolver::sheet()):
 * an entry the row's data-sheet does not carry is hidden, and a key the template does not
 * know is ignored. Its job is the sheet: fill the entries, make the page behind it inert,
 * close on Escape, on the backdrop, after an action, or when the row leaves the list, and
 * post through the row's own write path (mail--message-row#perform), never a copy of it.
 *
 * Its pickers (move, labels, snooze) are the existing menus, each behind an invisible
 * trigger, opened from their entry (the mail--list-toolbar#openFlyout precedent). Each
 * picker is given this row's target, carried labels and label ids at open time, and those
 * are Stimulus values, so the menus read them live.
 *
 * Registers "sheet" as an open surface (touch_gesture.js, invariant 9): while it is
 * connected, a pull and a swipe are refused, and both work again once it closes.
 *
 * Closing: Escape, the backdrop, an action, a picker's pick (after it runs), and a row that
 * leaves the list (a refresh removed it, or it is animating out). Closing removes the
 * sheet, which lifts inert and returns focus to the row's checkbox.
 *
 * Reads    the row (by its DOM id), its data-sheet, data-starred, data-label-ids,
 *          data-unread, and the message-row id
 * Writes   through mail--message-row#perform (status routes), mail--action-sheet's own
 *          select (the row checkbox, with a real change event)
 * Routes   none of its own: every URL is the captured one from the row's data-sheet
 *
 * Reduced motion: the slide takes zero time under motionIsOff().
 */

/** The name this sheet registers as an open surface under, see touch_gesture.js. */
const SURFACE = "sheet";

/** The page behind the sheet, made inert while it is open: the sidebar drawer's shell. */
const SHELL = '[data-controller~="ui--sidebar-drawer"]';

export default class extends Controller {
    static targets = ["panel", "backdrop"];

    static values = {
        rowDomId: String,
    };

    connect() {
        // Set before anything can return early, so disconnect() (which runs for the early return too)
        // never reads a field connect() did not reach.
        this._shell = null;
        this._liftsShell = false;

        this._row = document.getElementById(this.rowDomIdValue);

        // A row that is no longer there (a refresh took it between the hold and this) has no sheet.
        if (this._row === null) {
            this.element.remove();

            return;
        }

        const { type, placement, entries } = JSON.parse(this._row.getAttribute("data-sheet") ?? "{}");

        this._entries = entries ?? {};
        this._type = type ?? "thread";
        this._placement = placement ?? "";
        this._rowId = this._row.getAttribute("data-mail--message-row-id-value") ?? "";
        this._labelIds = this._row.getAttribute("data-label-ids") ?? "";
        this._returnTo = this._row.querySelector("[data-thread-select]");

        this.#fill();

        // The page behind is inert, and this is what keeps Tab in the sheet. The message list
        // lives under the sidebar drawer's shell, so making that inert covers the whole page.
        this._shell = document.getElementById("message-list")?.closest(SHELL) ?? null;

        // Only an inert this sheet set is lifted on close: a shell that was already inert belongs to someone else.
        if (this._shell !== null && this._shell.inert === false) {
            this._shell.inert = true;
            this._liftsShell = true;
        }

        this._onKeydown = (event) => {
            if (event.key === "Escape") {
                event.preventDefault();
                this.close();
            }
        };
        document.addEventListener("keydown", this._onKeydown);

        // The move picker's entries close the sheet, and so does a modal opened from it (Create label),
        // which lives outside the sheet and would otherwise open under it. Captured, so the listener
        // sees the click before the picker's own action (which stops propagation), and the close waits
        // a tick so that action has run.
        this._onPick = (event) => {
            if (event.target.closest?.('[role="menuitem"], [data-controller~="ui--modal"]')) {
                setTimeout(() => this.close(), 0);
            }
        };
        this.element.addEventListener("click", this._onPick, { capture: true });

        // A row a refresh removes, or one that is animating out of the list, takes the sheet with it.
        this._observer = new MutationObserver(() => this.#checkRow());
        this._observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ["data-leaving"] });

        // The finger that opened the sheet is still down, and when it lifts it can leave a click
        // behind (a tap, to the browser), which lands on the backdrop that now covers the row. That
        // click must not close the sheet it opened. Only the first touchend is the opening finger's.
        this._ignoreClickUntil = 0;
        this._onOpeningTouchEnd = () => {
            this._ignoreClickUntil = performance.now() + CLICK_WINDOW_MS;
            document.removeEventListener("touchend", this._onOpeningTouchEnd, { capture: true });
        };
        document.addEventListener("touchend", this._onOpeningTouchEnd, { capture: true, passive: true });


        registerSurface(SURFACE, () => this.element.isConnected);

        this.element.style.setProperty("--sheet-duration", motionIsOff() ? "0ms" : "200ms");

        this.panelTarget.querySelector("button:not([hidden])")?.focus();
    }

    disconnect() {
        this._observer?.disconnect();
        this.element.removeEventListener("click", this._onPick, { capture: true });
        document.removeEventListener("touchend", this._onOpeningTouchEnd, { capture: true });
        document.removeEventListener("keydown", this._onKeydown);
        unregisterSurface(SURFACE);

        if (this._liftsShell) {
            this._shell.inert = false;
        }

        if (this._returnTo?.isConnected) {
            this._returnTo.focus();
        }
    }

    close() {
        this.element.remove();
    }

    backdropClick(event) {
        if (event.target !== event.currentTarget) {
            return;
        }

        // Within the window after the opening finger lifted, a click on the backdrop is that finger's.
        if (performance.now() < this._ignoreClickUntil) {
            return;
        }

        this.close();
    }

    /** An entry that posts a status route: close the sheet, confirm if it must, then post the captured URL. */
    async run(event) {
        const { sheetUrl: url = "", sheetBody: body = "{}", sheetConfirm: confirm = "" } = event.currentTarget.dataset;

        this.close();

        if (confirm !== "" && false === await askConfirm(confirm)) {
            return;
        }

        this.#post(url, JSON.parse(body));
    }

    /** The snooze menu's option: the wake time arrives as a param, set by mail--snooze-menu. */
    snooze(event) {
        const { until = null } = event.params;

        this.close();
        this.#post(this._snoozeUrl, { until });
    }

    /** The row's checkbox, ticked with a real change event so the toolbar's count hears it. */
    select() {
        const box = this._row.querySelector("[data-thread-select]");

        if (box !== null && false === box.checked) {
            box.checked = true;
            box.dispatchEvent(new Event("change", { bubbles: true }));
        }

        this.close();
    }

    /**
     * Opens a picker by pressing its invisible trigger. The entry's click stops here, so the
     * picker's own outside-click listeners, which it adds on open, never see the click that
     * opened them. The trigger's click is a new event, which the picker's toggle expects.
     */
    openPicker(event) {
        event.stopPropagation();

        const name = event.currentTarget.dataset.sheetPicker;

        this.element.querySelector(`[data-sheet-picker-scope="${name}"] button`)?.click();
    }

    // ── Private ───────────────────────────────────────────────────────────

    /**
     * Show the entries the row offers, with the URL and body each posts to, and point the
     * pickers at this row. A picker's own values are read live, so setting them before it
     * is used is enough.
     */
    #fill() {
        const readState = this._entries.read_unread?.b?.read === true ? "mark_read" : "mark_unread";
        const starState = this._row.getAttribute("data-starred") === "true" ? "unstar" : "star";
        const states = { read_unread: readState, star: starState };

        for (const entry of this.element.querySelectorAll("[data-sheet-entry]")) {
            const key = entry.dataset.sheetEntry;
            const offered = this._entries[key];
            const container = entry.closest("[data-sheet-row]") ?? entry;

            container.hidden = offered === undefined;

            if (offered === undefined) {
                continue;
            }

            entry.dataset.sheetUrl = offered.u ?? "";
            entry.dataset.sheetBody = JSON.stringify(offered.b ?? {});

            for (const variant of entry.querySelectorAll("[data-sheet-when]")) {
                variant.hidden = variant.dataset.sheetWhen !== states[key];
            }
        }

        // Each picker's first button is its invisible trigger. It is pressed, never found: it has
        // the same name as the entry that opens it, so it is taken out of the accessibility tree.
        for (const scope of this.element.querySelectorAll("[data-sheet-picker-scope]")) {
            const trigger = scope.querySelector("button");

            trigger?.setAttribute("aria-hidden", "true");
            trigger?.setAttribute("tabindex", "-1");
        }

        this._snoozeUrl = this._entries.snooze?.u ?? "";

        const move = this.element.querySelector('[data-controller~="mail--move-menu"]');

        move?.setAttribute("data-mail--move-menu-target-id-value", this._rowId);
        move?.setAttribute("data-mail--move-menu-carried-value", JSON.stringify(this._labelIds === "" ? [] : this._labelIds.split(",")));
        move?.setAttribute("data-mail--move-menu-placement-value", this._placement);

        const labels = this.element.querySelector('[data-controller~="mail--label-menu"]');

        labels?.setAttribute("data-mail--label-menu-target-type-value", this._type);
        labels?.setAttribute("data-mail--label-menu-target-id-value", this._rowId);
        labels?.setAttribute("data-mail--label-menu-label-ids-value", this._labelIds);
    }

    /**
     * Close when the row has left the list, or is leaving it. A stream that redraws the row (a label
     * tick, a star) replaces its node under the same DOM id, so the row is looked up again by that id,
     * and the sheet follows the new node: the sheet closes only when the id is gone, or the row leaves.
     */
    #checkRow() {
        const current = document.getElementById(this.rowDomIdValue);

        if (current === null || current.hasAttribute("data-leaving")) {
            this.close();

            return;
        }

        if (current !== this._row) {
            this._row = current;
            this._returnTo = current.querySelector("[data-thread-select]");
        }
    }

    /** The row's own write path: the same fetch, stream and announcement as its strip. */
    #post(url, body) {
        this.application.getControllerForElementAndIdentifier(this._row, "mail--message-row")?.perform(url, body);
    }
}
