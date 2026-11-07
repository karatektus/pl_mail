import { Controller } from "@hotwired/stimulus";

/**
 * The list's one snooze menu and one spam menu, opened for whichever row asked (#496).
 *
 * WHY THERE IS ONE
 * ────────────────
 * Every row used to carry both menus: a ui--dropdown holding a mail--snooze-menu,
 * and a mail--spam-menu. That was about a third of a row's markup and three of
 * its four controllers, and a list switch builds fifty rows — measured, it was
 * most of the 100 ms (400 ms on a slow phone) that a category tab or a folder
 * change blocked the page for. Nobody opens fifty menus. One of each, filled in
 * for the row whose button was pressed, is the same menu on screen.
 *
 * HOW A ROW REACHES IT
 * ────────────────────
 * The row's button calls mail--message-row#openMenu, which raises `mail:row-menu`
 * on the document with the button (the anchor), the row and the row's URLs. The
 * menus are outside the list frame (see _partials/_row_menus.html.twig), so no
 * Stimulus action could reach them from a row; the event is the way across.
 *
 * What the menus do is not repeated here. A snooze option posts through the row's
 * own write path (mail--message-row#perform), the same as the swipe and the long-
 * press sheet, so a snooze from the shared menu hides the row ahead of the answer
 * and puts it back on failure exactly as the row's other actions do. The spam
 * menu is the existing controller, told which conversation and which button.
 *
 * STATE A ROW SHOWS
 * ─────────────────
 * The anchor gets `aria-expanded="true"` while its menu is open. That is the
 * accessible statement, and it is also what the row's action strip already keys
 * on to stay visible without the pointer (`has-[[aria-expanded=true]]:visible`),
 * and what mail--mail-pane reads to leave the row alone in a background refresh.
 */
export default class extends Controller {
    static targets = ["snooze", "unsnooze", "spam"];

    connect() {
        this._onOutside = (event) => this.#outside(event);
        this._onKey     = (event) => this.#key(event);
        this._onMove    = () => this.close();
    }

    disconnect() {
        this.close();
    }

    /** `mail:row-menu` from a row's button. */
    open({ detail }) {
        const { menu, anchor, row, params, keyboard } = detail ?? {};

        if (null == anchor || null == row) {
            return;
        }

        // One menu at a time, whichever row it belongs to.
        if ("spam" === menu) {
            this.close();

            // Toggles itself when it is the same button again.
            this.#spam()?.openFor(anchor, { url: params.spamMenuUrl, reportUrl: params.spamReportUrl });

            return;
        }

        if ("snooze" !== menu) {
            return;
        }

        this.#spam()?.close();

        // The same button again closes its own menu, the way a dropdown toggles.
        const again = this._anchor === anchor;

        this.close();

        if (true === again) {
            return;
        }

        this._anchor = anchor;
        this._rowId  = row.id;
        this._row    = row;
        this._url    = params.snoozeUrl;

        const panel = this.snoozeTarget;

        for (const element of this.unsnoozeTargets) {
            element.hidden = true !== params.snoozed;
        }

        // The wake times, worked out now rather than when the list was drawn:
        // a list left open across midnight would otherwise offer yesterday's
        // "tomorrow".
        this.application.getControllerForElementAndIdentifier(panel, "mail--snooze-menu")?.refresh();

        panel.hidden = false;
        this.#place(panel, anchor);
        anchor.setAttribute("aria-expanded", "true");

        document.addEventListener("click", this._onOutside, { capture: true });
        document.addEventListener("keydown", this._onKey);
        window.addEventListener("scroll", this._onMove, { capture: true });
        window.addEventListener("resize", this._onMove);

        if (true === keyboard) {
            this.#items()[0]?.focus();
        }
    }

    /** An option of the snooze menu. No wake time is Unsnooze. */
    snooze(event) {
        const { until = null } = event.params ?? {};
        const url = this._url;
        const row = this.#liveRow();

        event.stopPropagation();
        this.close();

        if (null === row || undefined === url) {
            return;
        }

        this.application.getControllerForElementAndIdentifier(row, "mail--message-row")?.perform(url, { until });
    }

    close({ restoreFocus = false } = {}) {
        const anchor = this._anchor;

        if (undefined === anchor) {
            return;
        }

        this._anchor = undefined;
        this._row    = undefined;
        this._rowId  = undefined;
        this._url    = undefined;

        const panel = this.snoozeTarget;

        panel.hidden = true;

        if ("function" === typeof panel.hidePopover && true === panel.matches(":popover-open")) {
            panel.hidePopover();
        }

        for (const property of ["top", "bottom", "left", "right", "maxHeight"]) {
            panel.style[property] = "";
        }

        anchor.setAttribute("aria-expanded", "false");

        document.removeEventListener("click", this._onOutside, { capture: true });
        document.removeEventListener("keydown", this._onKey);
        window.removeEventListener("scroll", this._onMove, { capture: true });
        window.removeEventListener("resize", this._onMove);

        if (true === restoreFocus && true === anchor.isConnected) {
            anchor.focus();
        }
    }

    // ── Private ───────────────────────────────────────────────────────────

    /**
     * Beside the button, in the top layer.
     *
     * The top layer because the list sits inside blurred panes, and a
     * `backdrop-filter` makes an ancestor the containing block for anything
     * `position: fixed` inside it — the same trap mail--label-menu escapes the
     * same way. The menu's own markup is outside those panes as well, but the
     * top layer also puts it above the reading pane and the sticky toolbar
     * without a z-index to argue about.
     *
     * Hung from the button's right edge, opening downwards, and flipped above
     * it when there is not room below — a row near the bottom of the list.
     * Kept inside the window either way.
     *
     * The comment this replaces in _thread_row warned that the row's snooze
     * menu stopped firing once sent to the top layer. That was its route
     * through ui--dropdown inside a row; this menu has neither, and the specs
     * press its options for real.
     */
    #place(panel, anchor) {
        if ("function" === typeof panel.showPopover) {
            if (false === panel.hasAttribute("popover")) {
                panel.setAttribute("popover", "manual");
            }

            panel.showPopover();
        }

        const margin = 8;
        const rect   = anchor.getBoundingClientRect();
        const below  = window.innerHeight - rect.bottom - margin;
        const above  = rect.top - margin;
        const flip   = below < 200 && above > below;
        const width  = panel.getBoundingClientRect().width;
        const left   = Math.min(
            Math.max(margin, rect.right - width),
            Math.max(margin, window.innerWidth - width - margin),
        );

        panel.style.margin    = "0";
        panel.style.left      = `${Math.round(left)}px`;
        panel.style.right     = "auto";
        panel.style.maxHeight = `${Math.max(96, Math.floor(flip ? above : below))}px`;

        if (true === flip) {
            panel.style.top    = "auto";
            panel.style.bottom = `${Math.round(window.innerHeight - rect.top + 4)}px`;
        } else {
            panel.style.top    = `${Math.round(rect.bottom + 4)}px`;
            panel.style.bottom = "auto";
        }
    }

    /**
     * The row the menu was opened for, as it is now.
     *
     * By id first: a background refresh may have replaced the element while the
     * menu was open, and the replacement is the one with a live controller.
     */
    #liveRow() {
        const current = undefined === this._rowId || "" === this._rowId ? null : document.getElementById(this._rowId);

        if (null !== current) {
            return current;
        }

        return true === this._row?.isConnected ? this._row : null;
    }

    #outside(event) {
        const target = event.target;

        // The menu itself, or the button that opened it — the button's own click
        // arrives next and closes it (see open()), and closing here first would
        // turn that into a reopen.
        if (this.snoozeTarget.contains(target) || this._anchor?.contains(target)) {
            return;
        }

        this.close();
    }

    /**
     * Escape closes and hands the focus back to the button. The arrows walk the
     * options, so a menu opened from the keyboard can be used from it.
     */
    #key(event) {
        if ("Escape" === event.key) {
            event.preventDefault();
            this.close({ restoreFocus: true });

            return;
        }

        if ("ArrowDown" !== event.key && "ArrowUp" !== event.key) {
            return;
        }

        const items = this.#items();
        const at    = items.indexOf(document.activeElement);

        if (0 === items.length) {
            return;
        }

        event.preventDefault();

        const step = "ArrowDown" === event.key ? 1 : -1;
        const next = -1 === at ? (1 === step ? 0 : items.length - 1) : (at + step + items.length) % items.length;

        items[next].focus();
    }

    /** The options on show, in order. */
    #items() {
        return [...this.snoozeTarget.querySelectorAll("button")].filter((button) => false === button.hidden);
    }

    #spam() {
        return this.hasSpamTarget
            ? this.application.getControllerForElementAndIdentifier(this.spamTarget, "mail--spam-menu")
            : null;
    }
}
