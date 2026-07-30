import { Controller } from "@hotwired/stimulus";

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
    static targets = ["panel", "starIcon", "starLabel"];

    static values = {
        entityId: Number,
        entityType: { type: String, default: "message" },
        starred: Boolean,
    };

    connect() {
        this._boundClose = this._closeOnOutsideClick.bind(this);
    }

    disconnect() {
        document.removeEventListener("click", this._boundClose, { capture: true });
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
        document.addEventListener("click", this._boundClose, { capture: true });
    }

    /**
     * Dismiss the panel without swallowing the click: the links in here are
     * Turbo frame navigations, and Turbo only sees clicks that reach the
     * document — stopPropagation() would turn them into full page loads.
     */
    dismiss() {
        this._close();
    }

    async star(event) {
        event.stopPropagation();
        this._close();

        await this._post(this._url("star"));

        this.starredValue = !this.starredValue;
    }

    async markUnread(event) {
        event.stopPropagation();
        this._close();

        await this._post(this._url("read"), { read: false });

        this._closePane();
    }

    async archive(event) {
        event.stopPropagation();
        this._close();

        await this._post(this._url("archive"));

        this._closePane();
    }

    async trash(event) {
        event.stopPropagation();
        this._close();

        await this._post(this._url("trash"));

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

    async _post(url, body) {
        const response = await fetch(url, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": document.querySelector('meta[name="csrf-token"]')?.content ?? "",
            },
            body: JSON.stringify(body ?? {}),
        });

        if (!response.ok) {
            console.error(`[message-actions] request failed: ${url}`, response.status);
            return;
        }

        const html = await response.text();

        if (html.trim() !== "") {
            Turbo.renderStreamMessage(html);
        }
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

    _closeOnOutsideClick(event) {
        if (!this.element.contains(event.target)) {
            this._close();
        }
    }

    _close() {
        if (this.hasPanelTarget) {
            this.panelTarget.classList.add("hidden");
        }

        document.removeEventListener("click", this._boundClose, { capture: true });
    }
}
