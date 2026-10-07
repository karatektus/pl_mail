import LabelMenuController from "./label_menu_controller.js";
import { jsonCsrfHeaders } from "../../csrf.js";
import { announceWrite } from "../../mail_writes.js";
import { requestFailed } from "../../request_errors.js";

/**
 * "Move to" dropdown: pick one destination, and the conversation goes there.
 *
 * Extends the label menu rather than copying it, because everything hard about
 * that controller is about the PANEL and none of it is about labels — lifting
 * it into the top layer so the reading pane's backdrop blur cannot trap it,
 * fitting it inside whatever clips, dismissing it on scroll. A second copy of
 * that would be two places to fix the next time a pane grows a filter.
 *
 * What differs is what a click means. There it toggles one of many and the
 * panel stays open; here it is the whole action, and the panel closes.
 *
 * Values:
 *   targetId — the thread, in the reading pane. Omitted in the list toolbar,
 *              where the pick is dispatched as `mail--move-menu:picked` and
 *              mail--list-toolbar posts it for the selection it owns.
 *   carried  — ids of the labels that thread already has, which are not
 *              offered. Reading pane only; the list reads the ticked rows.
 *   placement — `trash` or `spam` when that thread is already there, which
 *              takes that entry off too. Reading pane only; in the list the
 *              view being looked at says the same thing.
 *
 * Routes used:
 *   POST /status/bulk/move-to — one route for one conversation or fifty. The
 *        body names the target and the list being looked at; the server works
 *        out which label comes off (App\Service\Mail\MoveToService).
 */
export default class extends LabelMenuController {
    static targets = ["panel"];

    static values = {
        targetId: Number,
        carried:  { type: Array, default: [] },
        placement: { type: String, default: "" },
    };

    async move(event) {
        event.stopPropagation();

        // A label the user made is named by id; the Inbox, Spam and Trash by
        // role, because their labels may not exist yet — see _move_menu.
        const { moveRole = "", labelId = "" } = event.currentTarget.dataset;
        const target = "" !== moveRole ? { role: moveRole } : { labelId: Number(labelId) };

        this._close();

        if (false === this.hasTargetIdValue) {
            this.dispatch("picked", { detail: target });

            return;
        }

        const view = this.#view();

        let response;

        try {
            response = await fetch("/status/bulk/move-to", {
                method: "POST",
                headers: jsonCsrfHeaders(),
                body: JSON.stringify({
                    ids: [this.targetIdValue],
                    ...target,
                    scope: view.scope,
                    value: view.value,
                }),
            });
        } catch {
            requestFailed(null);
            return;
        }

        if (requestFailed(response)) {
            return;
        }

        Turbo.renderStreamMessage(await response.text());
        announceWrite();

        // The conversation has been filed, so the pane showing it closes — the
        // same ending archive has in mail--message-actions, and for the same
        // reason: what is on screen is no longer in the list behind it.
        this.#closePane();
    }

    // ── Private ───────────────────────────────────────────────────────────

    /**
     * Called by the inherited toggle() each time the panel opens. The label
     * menu ticks what the selection carries; this has no ticks, and uses the
     * same moment to hide the entries that would do nothing:
     *
     *  - the list the person is already looking at, and
     *  - any label the conversation already has. Moving mail to where it
     *    already is reads as a destination and is not one.
     *
     * Read from the DOM at open time rather than rendered into the panel,
     * because the reading pane's copy comes from a request with no list behind
     * it: only the page knows which list is underneath.
     */
    _syncFromSelection() {
        const view    = this.#view();
        const carried = this.#carried();

        for (const entry of this.panelTarget.querySelectorAll("[role='menuitem']")) {
            const id = entry.dataset.labelId ?? "";

            const role = entry.dataset.moveRole ?? "";

            // The Inbox, the bin and spam name themselves by role in both
            // places: the list's scope is `inbox` | `trash` | `spam`, and so
            // is the entry's. A label view matches by id instead.
            const current = "" !== role
                ? role === view.scope
                : "label" === view.scope && "" !== view.value && id === view.value;

            // Already in the bin, whichever list it was opened from — a
            // search result, say. Never true of the Inbox entry: placement is
            // only ever `trash` or `spam` here.
            const there = "" !== role && role === this.placementValue;

            entry.hidden = current || there || ("" !== id && carried.has(id));
        }
    }

    /**
     * The label ids already on what is about to be moved.
     *
     * One conversation says so itself. For a selection it is the labels EVERY
     * ticked row carries — the reading the label menu's ticks use, and for the
     * same reason: a label only some of them have is still a real destination
     * for the rest. Both sources name the user's own labels only, so the
     * Inbox is never hidden this way: in the Inbox the view rule above hides
     * it, and from a label "Move to Inbox" still takes that label off.
     */
    #carried() {
        if (this.hasTargetIdValue) {
            return new Set(this.carriedValue.map(String));
        }

        const rows = [...document.querySelectorAll("[data-thread-select]:checked")]
            .map((box) => box.closest("[data-label-ids]"))
            .filter((row) => null !== row)
            .map((row) => (row.dataset.labelIds ?? "").split(",").filter((id) => "" !== id));

        if (0 === rows.length) {
            return new Set();
        }

        return new Set(rows[0].filter((id) => rows.every((ids) => ids.includes(id))));
    }

    /**
     * Which list is on screen, in the list toolbar's own words.
     *
     * The toolbar already carries the view as values for the bulk actions
     * (see mail--list-toolbar), so this reads them from there instead of
     * naming the view a second time. No toolbar means no list — a conversation
     * opened by URL — and an empty scope is the server's "nowhere in
     * particular", where a move only ever takes the Inbox off.
     */
    #view() {
        const toolbar = document.querySelector("[data-controller~='mail--list-toolbar']");

        return {
            scope: toolbar?.getAttribute("data-mail--list-toolbar-view-scope-value") ?? "",
            value: toolbar?.getAttribute("data-mail--list-toolbar-view-value-value") ?? "",
        };
    }

    /** mail-pane lives on <body> (see _layout/app.html.twig). */
    #closePane() {
        const element = document.querySelector('[data-controller~="mail--mail-pane"]');

        if (!element) {
            return;
        }

        this.application
            .getControllerForElementAndIdentifier(element, "mail--mail-pane")
            ?.close();
    }
}
