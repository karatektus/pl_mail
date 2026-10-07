import { Controller } from "@hotwired/stimulus";
import { csrfToken } from "../../csrf.js";
import { announceWrite } from "../../mail_writes.js";
import { requestFailed } from "../../request_errors.js";

/**
 * The Undo button in the toast after an archive, a label change or a move.
 *
 * A sibling of compose--undo-send, chosen by the toast partial through
 * `extra.undo_controller`, and deliberately the same shape — same two values,
 * same `abort` action — so the partial has one button and not two.
 *
 * It is not that controller because of what has to happen after the request.
 * Calling off a send changes nothing on screen but the toast. Putting mail
 * back changes the list: rows that left have to return in their sorted places,
 * and the pager and the sidebar numbers move with them. No stream can carry
 * that — the reason bulk actions re-read the list frame as well as redrawing
 * rows — so this asks the mail pane for the frame and announces the write.
 *
 * Values:
 *   url       — POST /status/undo/{token}; the token is single use
 *   hideAfter — how long the toast stays, in ms. Unused here beyond keeping
 *               the two controllers interchangeable: ui--toast removes the
 *               toast, and this button with it.
 */
export default class extends Controller {
    static values = {
        url:       String,
        hideAfter: Number,
    };

    async abort() {
        // Once. The token is spent by the first request, so a second click
        // would be answered "too late" for something that had just worked.
        this.element.disabled = true;

        let response = null;

        try {
            response = await fetch(this.urlValue, {
                method: "POST",
                headers: { "X-Requested-With": "XMLHttpRequest", "X-CSRF-Token": csrfToken() },
            });
        } catch {
            // Handled below with response still null.
        }

        // Nothing was put back, and the token may still be good — a dropped
        // connection never reached the server — so the button is given back.
        if (requestFailed(response)) {
            this.element.disabled = false;

            return;
        }

        Turbo.renderStreamMessage(await response.text());

        this.#refreshList();
        announceWrite();

        this.element.closest("[data-controller~='ui--toast']")?.remove();
    }

    // ── Private ───────────────────────────────────────────────────────────

    /** mail-pane lives on <body> (see _layout/app.html.twig). */
    #refreshList() {
        const element = document.querySelector('[data-controller~="mail--mail-pane"]');

        if (!element) {
            return;
        }

        this.application
            .getControllerForElementAndIdentifier(element, "mail--mail-pane")
            ?.refreshNow();
    }
}
