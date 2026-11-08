import { Controller } from "@hotwired/stimulus";

/**
 * "Connect with browser approval" beside a connect form's manual fields (#235).
 *
 * Asks the server to start a login, opens the service's approval page in a new
 * tab, and polls until the person has approved, given up, or let it expire.
 * Nothing here names a provider: which ones offer this is decided server-side
 * by whether a BrowserLoginFlowInterface exists for them.
 *
 * The tab is opened before the start request goes out, while the click still
 * counts as the person's own — opened after an await, a popup blocker eats it.
 * If the start fails, the empty tab is closed again. The approval address is
 * also shown as a link, for when the tab was blocked anyway or got closed.
 *
 * The name and address come from the form's own fields as they stand, looked
 * up by name suffix as connection_test_controller does, so this works inside
 * the settings modal and inside the setup wizard's expanded row alike.
 *
 * When it is done:
 *   - with a done URL (the setup wizard), that frame is reloaded on it, which
 *     shows the row as connected;
 *   - otherwise (the settings modal), the server's Turbo Stream — the same one
 *     a manual save answers with — is rendered and the modal closed.
 */
export default class extends Controller {
    static targets = ["button", "waiting", "link", "error"];

    static values = {
        startUrl: String,
        csrf: String,
        integration: { type: String, default: "" },
        doneUrl: { type: String, default: "" },
        doneFrame: { type: String, default: "" },
        /** Translated "could not start (%status%)", for an answer with no message. */
        failed: { type: String, default: "%status%" },
        /** How often to ask, in milliseconds. */
        interval: { type: Number, default: 2000 },
    };

    disconnect() {
        this._stop();
    }

    async start() {
        this._stop();
        this._showError("");

        const tab = window.open("", "_blank");
        this.buttonTarget.disabled = true;

        let started;

        try {
            const response = await fetch(this.startUrlValue, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "Accept": "application/json",
                    "X-CSRF-Token": this.csrfValue,
                },
                body: JSON.stringify({
                    name: this._field("name"),
                    baseUrl: this._field("baseUrl"),
                    integration: this.integrationValue,
                }),
            });

            const body = await response.json().catch(() => ({}));

            if (false === response.ok) {
                throw new Error(body.message || this.failedValue.replace("%status%", String(response.status)));
            }

            started = body;
        } catch (error) {
            tab?.close();
            this.buttonTarget.disabled = false;
            this._showError(error instanceof Error ? error.message : String(error));

            return;
        }

        if (tab) {
            tab.opener = null;
            tab.location.href = started.loginUrl;
        }

        this.linkTarget.href = started.loginUrl;
        this.waitingTarget.hidden = false;
        this._pollUrl = started.pollUrl;
        this._timer = window.setTimeout(() => this._poll(), this.intervalValue);
    }

    cancel() {
        this._stop();
        this.waitingTarget.hidden = true;
        this.buttonTarget.disabled = false;
    }

    // ── Private ───────────────────────────────────────────────────────────

    async _poll() {
        this._timer = null;

        let result;

        try {
            const response = await fetch(this._pollUrl, {
                method: "POST",
                headers: { "Accept": "application/json", "X-CSRF-Token": this.csrfValue },
            });

            // A dropped request is not the end of the approval: the person may
            // still be typing their password. Ask again.
            if (false === response.ok) {
                this._schedule();

                return;
            }

            result = await response.json();
        } catch {
            this._schedule();

            return;
        }

        if ("pending" === result.status) {
            this._schedule();

            return;
        }

        this.waitingTarget.hidden = true;
        this.buttonTarget.disabled = false;

        if ("failed" === result.status) {
            this._showError(result.message || "");

            return;
        }

        this._finish(result);
    }

    _finish(result) {
        if ("" !== this.doneUrlValue) {
            const frame = "" === this.doneFrameValue ? null : document.getElementById(this.doneFrameValue);

            if (frame) {
                frame.src = this.doneUrlValue;
            } else {
                window.Turbo.visit(this.doneUrlValue);
            }

            return;
        }

        if (result.stream) {
            window.Turbo.renderStreamMessage(result.stream);
        }

        const modalElement = this.element.closest("[data-controller~='ui--modal']")
            ?? document.querySelector("[data-controller~='ui--modal']");
        const modal = modalElement
            ? this.application.getControllerForElementAndIdentifier(modalElement, "ui--modal")
            : null;

        modal?.close();
    }

    _schedule() {
        if (null === this._pollUrl) {
            return;
        }

        this._timer = window.setTimeout(() => this._poll(), this.intervalValue);
    }

    _stop() {
        if (this._timer) {
            window.clearTimeout(this._timer);
        }

        this._timer = null;
        this._pollUrl = null;
    }

    _field(suffix) {
        const form = this.element.closest("form");
        const input = form?.querySelector(`[name$="[${suffix}]"]`);

        return input ? input.value : null;
    }

    _showError(message) {
        this.errorTarget.textContent = message;
        this.errorTarget.hidden = "" === message;
    }
}
