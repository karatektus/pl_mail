import { Controller } from "@hotwired/stimulus";
import { jsonCsrfHeaders } from "../../csrf.js";
import { listView } from "../../list_view.js";
import { announceWrite } from "../../mail_writes.js";
import { askConfirm } from "../../confirm.js";
import { requestFailed } from "../../request_errors.js";
import { postPin, redrawAndPlace, swingTack } from "../../pin.js";
import { onOutsidePress } from "../../outside_press.js";

/**
 * Status actions for the reading pane: the per-message overflow menu and the
 * thread-level toolbar (same controller, `targetType` switches the endpoint
 * between /status/message/… and /status/thread/…; the toolbar has no panel).
 *
 * The status endpoints answer with a Turbo Stream that targets the *list row*
 * (#thread_{id}); nothing in the reading pane is re-rendered, so anything that
 * stays on screen (the star) is updated optimistically here. Actions that mean
 * "this message left the view" (archive, trash, mark unread) close the pane.
 */
export default class extends Controller {
    static targets = ["panel", "starIcon", "starLabel", "pin"];

    static values = {
        entityId: Number,
        entityType: { type: String, default: "message" },
        starred: Boolean,
    };

    connect() {
        this._boundEscape = this._closeOnEscape.bind(this);
    }

    disconnect() {
        this._detach();
    }

    toggle(event) {
        event.stopPropagation();

        if (!this.hasPanelTarget) {
            return;
        }

        if (!this.panelTarget.classList.contains("hidden")) {
            this._close();
            return;
        }

        this.panelTarget.classList.remove("hidden");

        // A tap on iOS and a press inside the message both close it now; see
        // outside_press.js for why a click listener heard neither (#807).
        this._detach();
        this._stopOutside = onOutsidePress(this.element, () => this._close());
        document.addEventListener("keydown", this._boundEscape);
    }

    /**
     * Dismiss the panel without swallowing the click: the links in here are
     * Turbo frame navigations, and Turbo only sees clicks that reach the
     * document — stopPropagation() would turn them into full page loads.
     */
    dismiss() {
        this._close();
    }

    /**
     * Ask this message's body to translate itself (#713). The body has its own
     * controller, mail--message-translation, and the menu does not reach into
     * it: it says which message, and the body that is that message answers.
     */
    translate() {
        this._close();
        this.dispatch("translate", { target: document, detail: { id: this.entityIdValue } });
    }

    async star(event) {
        event.stopPropagation();
        this._close();

        if (false === await this._post(this._url("star"))) {
            return;
        }

        this.starredValue = !this.starredValue;
    }

    /**
     * Pin or unpin the open conversation (#654). The conversation stays open
     * — a pin moves its row in the list, not the reader — so the button is
     * updated here, and the list's row is moved the way the row's own button
     * moves it.
     */
    async pin(event) {
        event.stopPropagation();

        const response = await postPin(this._url("pin"));

        if (null === response) {
            return;
        }

        const pinned = "true" === response.headers.get("X-Pinned");

        if (this.hasPinTarget) {
            const icon = this.pinTarget.querySelector("i");

            icon?.classList.toggle("text-accent", pinned);
            await swingTack(icon, pinned);

            this.pinTarget.setAttribute("aria-pressed", pinned ? "true" : "false");

            const label = pinned ? this.pinTarget.dataset.unpinLabel : this.pinTarget.dataset.pinLabel;

            this.pinTarget.setAttribute("aria-label", label);
            this.pinTarget.setAttribute("title", label);

            const text = this.pinTarget.querySelector(".action-btn-label");

            if (text) {
                text.textContent = label;
            }
        }

        await redrawAndPlace(`thread_${this.entityIdValue}`, await response.text(), pinned);

        announceWrite();
    }

    async markUnread(event) {
        event.stopPropagation();
        this._close();

        if (false === await this._post(this._url("read"), { read: false })) {
            return;
        }

        this._closePane();
    }

    /**
     * Snooze the open conversation, from its own toolbar.
     *
     * The wake time arrives as a param from mail--snooze-menu, which works it
     * out in the browser — the server has no timezone for the session. Absent,
     * this clears the snooze, exactly as the row's does.
     *
     * A snoozed conversation has left the list it was opened from, so the pane
     * closes, as it does after Archive. Clearing a snooze moves nothing the
     * reader is looking at, and they stay where they are.
     */
    async snooze(event) {
        const { until = null } = event.params;
        event.stopPropagation();

        if (false === await this._post(this._url("snooze"), { until })) {
            return;
        }

        if (null !== until) {
            this._closePane();
        }
    }

    async archive(event) {
        event.stopPropagation();
        this._close();

        if (false === await this._post(this._url("archive"))) {
            return;
        }

        this._closePane();
    }

    async trash(event) {
        event.stopPropagation();
        this._close();

        if (false === await this._post(this._url("trash"))) {
            return;
        }

        this._closePane();
    }

    /** Out of the bin, as the row's Restore does. */
    async restore(event) {
        event.stopPropagation();
        this._close();

        if (false === await this._post(this._url("restore"))) {
            return;
        }

        this._closePane();
    }

    /**
     * Delete for good, asked first in the application's dialog — see
     * mail--message-row#purge, which asks the same question for the row.
     */
    async purge(event) {
        const { purgeConfirm } = event.params;
        event.stopPropagation();
        this._close();

        if (false === await askConfirm(purgeConfirm)) {
            return;
        }

        if (false === await this._post(this._url("purge"))) {
            return;
        }

        this._closePane();
    }

    starredValueChanged() {
        if (this.hasStarIconTarget) {
            this.starIconTarget.classList.toggle("fa-solid", this.starredValue);
            this.starIconTarget.classList.toggle("fa-regular", !this.starredValue);
            this.starIconTarget.classList.toggle("text-amber-400", this.starredValue);
            this.starIconTarget.classList.toggle("text-ink-faint", !this.starredValue);
        }

        if (this.hasStarLabelTarget) {
            const label = this.starLabelTarget;
            label.textContent = this.starredValue ? label.dataset.unstar : label.dataset.star;
        }
    }

    // ── Private ───────────────────────────────────────────────────────────

    _url(action) {
        return `/status/${this.entityTypeValue}/${this.entityIdValue}/${action}`;
    }

    /**
     * Answers false when the request failed, so the caller leaves the star and
     * the pane as they were instead of acting on a change that never happened.
     */
    async _post(url, body) {
        let response;

        try {
            response = await fetch(url, {
                method: "POST",
                headers: jsonCsrfHeaders(),
                body: JSON.stringify({ ...listView(), ...(body ?? {}) }),
            });
        } catch {
            requestFailed(null);
            return false;
        }

        if (requestFailed(response)) {
            return false;
        }

        const html = await response.text();

        if (html.trim() !== "") {
            Turbo.renderStreamMessage(html);
        }

        // Star, mark-unread, archive and trash all move a sidebar number, and
        // the streams these endpoints answer with address the list row only —
        // see the class docblock. Mark-unread is the one that shows up in the
        // report as "the counter does not go back up".
        announceWrite();

        return true;
    }

    /** mail-pane lives on <body> (see _layout/app.html.twig). */
    _closePane() {
        const element = document.querySelector('[data-controller~="mail--mail-pane"]');

        if (!element) {
            return;
        }

        this.application
            .getControllerForElementAndIdentifier(element, "mail--mail-pane")
            ?.close();
    }

    _closeOnEscape(event) {
        if ("Escape" === event.key) {
            this._close();
        }
    }

    _close() {
        if (this.hasPanelTarget) {
            this.panelTarget.classList.add("hidden");
        }

        this._detach();
    }

    _detach() {
        this._stopOutside?.();
        this._stopOutside = undefined;
        document.removeEventListener("keydown", this._boundEscape);
    }
}
