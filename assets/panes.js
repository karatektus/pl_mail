/**
 * The panes of the workspace, by name: one place to open one, close one, or
 * show something in one, for whoever needs to.
 *
 * The workspace knows how to put a pane on screen — where it goes, whether
 * there is room, what to remember (see layout--workspace). What was missing
 * is a way for anything else to ask. Without one, every feature
 * that wanted "and show it in the address book" would reach into the
 * workspace's controller, or dispatch an event of its own that only it and the pane knew
 * about, and the next such feature would do it again a little differently.
 *
 * So a pane registers itself here by name, and callers use the name:
 *
 *     import { openPane } from "../panes.js";
 *     openPane("contacts", { src: "/contacts/12" });
 *
 * and a server response can ask for the same thing without any script of its
 * own, as a turbo-stream:
 *
 *     <turbo-stream action="open_pane" pane="contacts" src="/contacts/12"></turbo-stream>
 *
 * which is what "Add to contacts" answers with: the contact just made or added
 * to, in the pane.
 *
 * ── What a pane registers ────────────────────────────────────────────────
 *
 *   available()       whether it can be shown at all here — not below lg,
 *                     where the panes are tabs, for instance. A pane that is not available is
 *                     neither opened nor pointed anywhere, and openPane()
 *                     says so by returning false.
 *   isOpen()          whether it is on screen.
 *   open({ src })     on screen, showing src when one is given. Open already
 *                     is fine: then it only navigates.
 *   close()
 *
 * layout--workspace registers the calendar, the address book and the tasks
 * (#755). Opening one the workspace does not show adds it at the right-hand
 * edge, as opening a docked pane used to; the list and the message are opened
 * by the mail's own controller.
 */
import * as Turbo from "@hotwired/turbo";

/** @type {Map<string, {available: () => boolean, isOpen: () => boolean, open: (options: {src?: string}) => void, close: () => void}>} */
const panes = new Map();

/**
 * Called from the pane's connect(). Returns the unregister, for its
 * disconnect(): a Turbo visit replaces the shell and with it the controller,
 * and a registry still holding the old one would open a pane that is gone.
 * Only the same registration is removed, so a new shell that connected
 * before the old one disconnected keeps its place.
 */
export function registerPane(name, pane) {
    panes.set(name, pane);

    return () => {
        if (panes.get(name) === pane) {
            panes.delete(name);
        }
    };
}

/**
 * Opens the pane, showing src if given. False when there is no such pane on
 * this page or it cannot be shown here, so a caller can fall back.
 *
 * @param {string} name
 * @param {{src?: string}} [options]
 * @returns {boolean}
 */
export function openPane(name, options = {}) {
    const pane = panes.get(name);

    if (undefined === pane || false === pane.available()) {
        return false;
    }

    pane.open(options);

    return true;
}

/** @param {string} name */
export function closePane(name) {
    panes.get(name)?.close();
}

/** @param {string} name */
export function isPaneOpen(name) {
    return true === panes.get(name)?.isOpen();
}

// The stream actions. Neither needs a target: the pane is found by name.
Turbo.StreamActions.open_pane = function () {
    openPane(this.getAttribute("pane") ?? "", { src: this.getAttribute("src") ?? undefined });
};

Turbo.StreamActions.close_pane = function () {
    closePane(this.getAttribute("pane") ?? "");
};

/**
 * The mail, asked for from a page without workspaces (#755).
 *
 * In the mailbox, opening a folder in a workspace without the mail list goes
 * to the first workspace that has one (layout--workspace's
 * #bringListForward), because the list's frame load says the mail was asked
 * for. From Settings or the admin panel the same click is a whole page load,
 * and a page load says nothing of why it happened: the server draws the
 * workspace last used, which may have no list, and the mail asked for is
 * nowhere on screen.
 *
 * So a click here on a link into the mail leaves a note for the next page,
 * and layout--workspace takes it up as it starts. A note, not a URL
 * parameter, so the address stays the folder's; short-lived, so a mail page
 * reached some other way later in the tab is not taken for it; and only from
 * a page without workspaces, since in the mailbox the frame load already
 * says it. A reload leaves none, so reloading a workspace without a list
 * keeps it.
 */
export const MAIL_ASKED_FOR = "plmail.mailAskedFor";

document.addEventListener("click", (event) => {
    if (event.defaultPrevented || 0 !== event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
    }

    const link = event.target.closest?.("a[href]");

    if (!link || link.target === "_blank" || null !== document.querySelector("[data-layout-root]")) {
        return;
    }

    const url = new URL(link.href, location.href);

    if (url.origin === location.origin && url.pathname.startsWith("/mail")) {
        try {
            sessionStorage.setItem(MAIL_ASKED_FOR, String(Date.now()));
        } catch {
            // Without storage the next page simply draws the last workspace.
        }
    }
}, true);

/** Whether the page being drawn was asked for as "the mail" (and forget it either way). */
export function mailWasAskedFor() {
    try {
        const at = Number(sessionStorage.getItem(MAIL_ASKED_FOR) ?? 0);

        sessionStorage.removeItem(MAIL_ASKED_FOR);

        return Date.now() - at < 15_000;
    } catch {
        return false;
    }
}
