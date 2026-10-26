/**
 * The side panes beside the mail, by name: one place to open one, close one,
 * or show something in one, for whoever needs to.
 *
 * Each pane already knows how to open itself — whether there is room, what
 * it has to ask the calendar for, what to remember (see contacts--pane). What
 * was missing is a way for anything else to ask. Without one, every feature
 * that wanted "and show it in the address book" would reach into that
 * controller, or dispatch an event of its own that only it and the pane knew
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
 *   available()       whether it can be shown at all here — no dock below
 *                     lg, for instance. A pane that is not available is
 *                     neither opened nor pointed anywhere, and openPane()
 *                     says so by returning false.
 *   isOpen()          whether it is on screen.
 *   open({ src })     on screen, showing src when one is given. Open already
 *                     is fine: then it only navigates.
 *   close()
 *
 * Only contacts--pane registers today. The calendar's pane is ui--split's and
 * has positions rather than open/closed; it can register here the day
 * something needs to ask for it.
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
