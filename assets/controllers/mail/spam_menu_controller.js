import LabelMenuController from "./label_menu_controller.js";
import { jsonCsrfHeaders } from "../../csrf.js";
import { announceWrite } from "../../mail_writes.js";
import { requestFailed } from "../../request_errors.js";

/**
 * The spam button's menu: move one conversation to Spam, and optionally make
 * the filter that sends its sender, or its sender's whole domain, there too.
 *
 * Extends the label menu for the reason the move menu does: everything hard
 * about that controller is the PANEL — the top layer, fitting inside whatever
 * clips, dismissing on scroll — and none of it is about labels.
 *
 * What is its own is where the panel's contents come from. They depend on who
 * the conversation is from, so they are fetched when the button is pressed
 * rather than rendered into every row of a list. Fetched on every open, not
 * once: it is one small request, and it puts the menu back to its first state
 * — a confirmation somebody backed out of is not still showing next time.
 *
 * Values:
 *   url        — GET, the panel's contents for this conversation
 *   reportUrl  — POST, the action; body `{ filter, scope, value }` where
 *                filter is `none` | `sender` | `domain`
 *   closesPane — true in the reading pane, whose conversation has just left
 *   align      — `start` hangs the panel from the button's left edge, `end`
 *                (the inherited behaviour) from its right
 *
 * Targets:
 *   trigger — the button; carries aria-expanded, which a list row also reads
 *             to keep its hover actions on screen while the menu is open
 *
 * and, inside the fetched panel, all optional:
 *   more    — "More options", shown in place of the domain row for a mail
 *             provider's domain
 *   domain  — the "whole domain" row, which asks before it acts
 *   confirm — the question it asks
 */
export default class extends LabelMenuController {
    static targets = ["panel", "trigger", "more", "domain", "confirm"];

    static values = {
        url:        String,
        reportUrl:  String,
        closesPane: { type: Boolean, default: false },
        align:      { type: String, default: "end" },
    };

    async toggle(event) {
        // Now, not after the fetch: by then the click has already reached the
        // row this button sits in, and the row has opened its conversation.
        event.stopPropagation();

        if (false === this.panelTarget.classList.contains("hidden")) {
            this._close();

            return;
        }

        if (true === this._loading) {
            return;
        }

        this._loading = true;

        let response;

        try {
            response = await fetch(this.urlValue, { headers: { "X-Requested-With": "XMLHttpRequest" } });
        } catch {
            requestFailed(null);

            return;
        } finally {
            this._loading = false;
        }

        if (requestFailed(response)) {
            return;
        }

        this.panelTarget.innerHTML = await response.text();

        super.toggle(event);

        this.triggerTarget.setAttribute("aria-expanded", "true");
    }

    /** See the panel in _spam_menu.html.twig: a click in it is not a click on the row. */
    stop(event) {
        event.stopPropagation();
    }

    /** A mail provider's domain: the domain row takes the place of the button that revealed it. */
    moreOptions(event) {
        event.stopPropagation();

        this.moreTarget.hidden   = true;
        this.domainTarget.hidden = false;

        this._place();
    }

    /** First click on "whole domain": ask. The filter is made by the button in the question. */
    askDomain(event) {
        event.stopPropagation();

        this.domainTarget.hidden  = true;
        this.confirmTarget.hidden = false;

        this._place();
    }

    cancelDomain(event) {
        event.stopPropagation();

        this.confirmTarget.hidden = true;
        this.domainTarget.hidden  = false;

        this._place();
    }

    async report(event) {
        event.stopPropagation();

        const filter = event.currentTarget.dataset.spamFilter ?? "none";
        const view   = this.#view();

        this._close();

        let response;

        try {
            response = await fetch(this.reportUrlValue, {
                method: "POST",
                headers: jsonCsrfHeaders(),
                body: JSON.stringify({ filter, scope: view.scope, value: view.value }),
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

        if (true === this.closesPaneValue) {
            this.#closePane();
        }
    }

    // ── Private ───────────────────────────────────────────────────────────

    /**
     * The inherited placement hangs the panel from the button's right edge,
     * which suits a button at the right of its row. The reading pane's button
     * is at the pane's left edge, where that put the menu over the sidebar.
     *
     * Only the top-layer case is corrected here; in place, the stylesheet's
     * own `left-0` already says it.
     */
    _place() {
        super._place();

        const panel = this.panelTarget;

        if ("start" !== this.alignValue || false === panel.matches(":popover-open")) {
            return;
        }

        const margin = 8;
        const rect   = this.triggerTarget.getBoundingClientRect();
        const width  = panel.getBoundingClientRect().width;
        const left   = Math.min(
            Math.max(margin, rect.left),
            Math.max(margin, window.innerWidth - width - margin),
        );

        panel.style.left = `${Math.round(left)}px`;
    }

    _close() {
        super._close();

        if (this.hasTriggerTarget) {
            this.triggerTarget.setAttribute("aria-expanded", "false");
        }
    }

    /** The label menu ticks what the selection carries. This menu has no ticks. */
    _syncFromSelection() {
    }

    /** Which list is on screen, in the list toolbar's own words. See mail--move-menu. */
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
