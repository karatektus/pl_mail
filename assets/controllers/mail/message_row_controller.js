import { Controller } from "@hotwired/stimulus";
import { jsonCsrfHeaders } from "../../csrf.js";
import { listView } from "../../list_view.js";
import { announceWrite } from "../../mail_writes.js";
import { askConfirm } from "../../confirm.js";
import { requestFailed } from "../../request_errors.js";
import { leavesList, rowOf, takeOut } from "../../optimistic_rows.js";

/**
 * Handles per-row status actions in the message list.
 *
 * Values:
 *   id       — entity ID (thread or message)
 *   type     — "thread" (default) | "message"
 *
 * Routes used:
 *   thread  → /thread/{id}/status/{action}
 *   message → /message/{id}/status/{action}
 */
export default class extends Controller {
    static values = {
        id:   Number,
        type: { type: String, default: "thread" },
    };

    stop(event) {
        event.stopPropagation();
    }

    toggleSelect(event) {
        event.stopPropagation();

        const toolbar = document.querySelector("[data-controller~='mail--list-toolbar']");

        if (toolbar) {
            toolbar.dispatchEvent(
                new CustomEvent("mail--list-toolbar:row-changed", { bubbles: false }),
            );
        }
    }

    async toggleStar(event) {
        const { starUrl } = event.params;
        event.stopPropagation();
        await this.#post(starUrl);
    }

    async archive(event) {
        const { archiveUrl } = event.params;
        event.stopPropagation();
        await this.#post(archiveUrl);
    }

    async trash(event) {
        const { trashUrl } = event.params;
        event.stopPropagation();
        await this.#post(trashUrl);
    }

    /**
     * Delete for good — offered only on mail already in the trash or in spam.
     *
     * Asks here rather than through `data-turbo-confirm`, which Turbo only
     * consults for links and form submissions. This posts with fetch(), so the
     * attribute would have sat on the button looking like a confirmation and
     * never been read — the row would simply have vanished for good on the
     * first click.
     */
    async purge(event) {
        const { purgeUrl, purgeConfirm } = event.params;
        event.stopPropagation();

        if (false === await askConfirm(purgeConfirm)) {
            return;
        }

        await this.#post(purgeUrl);
    }

    /** Back to the inbox from the trash or from spam. */
    async restore(event) {
        const { restoreUrl } = event.params;
        event.stopPropagation();
        await this.#post(restoreUrl);
    }

    /**
     * The wake time arrives as a param, set by the snooze menu — see
     * mail--snooze-menu, which computes it in the browser because the server
     * has no timezone for the session.
     *
     * Absent, this clears the snooze. That used to be the *only* behaviour:
     * the signature took `until = null` as a second argument, and Stimulus
     * calls actions with the event alone, so the row's snooze button silently
     * unsnoozed instead of snoozing.
     */
    async snooze(event) {
        const { snoozeUrl, until = null } = event.params;
        event.stopPropagation();

        await this.#post(snoozeUrl, { until });
    }

    /**
     * Call off a scheduled send from the row.
     *
     * The row is an <li> with an absolutely-positioned overlay <a> across it —
     * so this stops the event the way every other row action does, and the
     * button sits above the anchor rather than inside a nested form. The answer
     * is a Turbo Stream that replaces this very row, which is why nothing here
     * touches the badge itself: the server decides what the row now says.
     *
     * `preventDefault` as well as `stopPropagation`, unlike its neighbours: in
     * the Drafts list the whole row is a link, and a click that reaches the
     * document's default action opens the composer over the toast that just
     * said the hold was lifted.
     */
    async cancelSchedule(event) {
        const { cancelScheduleUrl } = event.params;

        event.preventDefault();
        event.stopPropagation();

        await this.#post(cancelScheduleUrl);
    }

    /**
     * The snooze or spam button: ask the list's shared menu to open for this row.
     *
     * The menus are not in the row any more (#496) — there is one of each for
     * the whole list, in _partials/_row_menus.html.twig, which is outside the
     * list frame and so not an ancestor this controller could reach by action.
     * A document event is the way across, and it carries everything the menu
     * needs: which menu, the button to hang it from, this row, and the row's
     * URLs as the button's params.
     *
     * `keyboard` because a click with no pointer behind it is Enter or Space on
     * the button, and a menu opened from the keyboard should take the focus.
     */
    openMenu(event) {
        event.stopPropagation();
        event.preventDefault();

        document.dispatchEvent(new CustomEvent("mail:row-menu", {
            detail: {
                menu:     event.params.menu,
                anchor:   event.currentTarget,
                row:      this.element,
                params:   event.params,
                keyboard: 0 === event.detail,
            },
        }));
    }

    async markRead(event) {
        const { read, readUrl } = event.params;

        event.stopPropagation();
        await this.#post(readUrl, { read });
    }

    /**
     * Post to a status route on this row, for a caller that is not a click on
     * one of this row's own buttons: the row swipe, which commits on a drag as
     * well as on a tap. The same fetch, stream and announcement as every action
     * above, so there is one write path for a row, not a copy of it.
     *
     * @param {string} url   the status route, as the server rendered it
     * @param {object} body  the JSON body the route reads; empty for most routes
     */
    async perform(url, body = {}) {
        await this.#post(url, body);
    }

    // ── Private ───────────────────────────────────────────────────────────

    /**
     * Post to a status route on this row and draw the answer.
     *
     * A row the action takes out of this list is hidden before the request
     * goes, and put back if it fails (#335) — see assets/optimistic_rows.js.
     * Which action it is comes from the route itself, whose last segment is
     * the action's name (app_status_archive → …/archive); reading it there
     * rather than taking it as an argument means the swipe, which only has a
     * URL, gets the same behaviour as the buttons without a second table of
     * what each panel does.
     *
     * The list refresh is held for the length of the request when a row is
     * hidden, exactly as the toolbar holds it for a selection. Without the
     * hold a background refresh issued before the write landed would morph
     * the server's old list over the hidden row and show it again; with it,
     * the refresh runs once the answer is in and also puts the pager and the
     * empty state right, which no row-shaped stream can.
     */
    async #post(url, body = {}) {
        const action = new URL(url, window.location.origin).pathname.split("/").pop() ?? "";
        const row    = rowOf(this.element);
        const gone   = null !== row && true === leavesList(action, body) ? takeOut([row]) : null;

        if (null !== gone) {
            this.#hold("writing");
        }

        try {
            let response;

            try {
                response = await fetch(url, {
                    method: "POST",
                    headers: jsonCsrfHeaders(),
                    body: JSON.stringify({ ...listView(), ...body }),
                });
            } catch {
                gone?.putBack();
                requestFailed(null);
                return;
            }

            // A hidden row comes back first, so the toast and the row arrive
            // together; a row that was never hidden only needs the toast —
            // otherwise the click looks like it did nothing.
            if (requestFailed(response)) {
                gone?.putBack();
                return;
            }

            const html = await response.text();

            if (html.trim() !== "") {
                Turbo.renderStreamMessage(html);
            }

            // Here rather than in markRead() alone, because every action on this
            // row moves a number in the sidebar: archive and trash take the mail
            // out of Inbox, snooze takes it out and puts it back later, star moves
            // the Starred count. The row redraws itself from the stream; nothing
            // else does. Announced after the render so the badges and the row do
            // not disagree in between.
            announceWrite();
        } finally {
            if (null !== gone) {
                this.#hold("written");
            }
        }
    }

    /**
     * The toolbar's event names, raised from the row: the layout binds
     * `mail--list-toolbar:writing/written` to mail--mail-pane#hold/#release on
     * <body>, and mail--dnd borrows them the same way. Not this.dispatch(),
     * which would prefix them with this controller's own name.
     *
     * Dispatched on <body> itself, not on this row. By the time `written` is
     * raised the stream has usually removed the row, and an event dispatched on
     * a detached element reaches nobody — the hold would never be released and
     * the list would stop refreshing for the rest of the visit.
     */
    #hold(phase) {
        document.body.dispatchEvent(new CustomEvent(`mail--list-toolbar:${phase}`, { bubbles: true }));
    }
}
