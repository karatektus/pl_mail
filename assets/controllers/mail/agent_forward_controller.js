import { Controller } from "@hotwired/stimulus";
import { requestFailed } from "../../request_errors.js";

/**
 * The "Forward to agent" dialog: pick an agent, write a note, send.
 *
 * Values:
 *   url    the endpoint for THIS message (app_mail_agent_forward_send)
 *   token  the per-action `agent-forward` CSRF token
 * Targets:
 *   agent        the radio for each agent; its value is the address
 *   note         the note field, focused on open
 *   submit       the Send button, whose label names the picked agent
 *   submitLabel
 *
 * Ctrl/⌘+Enter sends from anywhere in the dialog, and Alt+1–9 picks an agent
 * without leaving the note: the whole hand-off is the shortcut that opened the
 * dialog, a sentence and one chord. Alt is matched on the physical key
 * (`event.code`), because on a Mac Alt+1 types "¡" and `event.key` says so.
 *
 * The server answers with a turbo-stream carrying the "sending" toast and its
 * Undo; it is rendered, and the dialog closed, only once the send is queued.
 */
export default class extends Controller {
    static targets = ["agent", "note", "submit", "submitLabel"];

    static values = { url: String, token: String };

    keydown(event) {
        if (event.isComposing) {
            return;
        }

        if ("Enter" === event.key && (event.metaKey || event.ctrlKey)) {
            event.preventDefault();
            this.submit();

            return;
        }

        const digit = /^Digit([1-9])$/.exec(event.code);

        if (null === digit || !event.altKey || event.metaKey || event.ctrlKey) {
            return;
        }

        const radio = this.agentTargets[Number(digit[1]) - 1];

        if (undefined !== radio && !radio.disabled) {
            event.preventDefault();
            radio.checked = true;
            this.pick();
        }
    }

    pick() {
        const name = this.#picked()?.dataset.name ?? "";

        this.submitLabelTarget.textContent = this.submitTarget.dataset.label.replace("__name__", name);
    }

    async submit() {
        const agent = this.#picked();

        if (undefined === agent || true === this.submitTarget.disabled) {
            return;
        }

        this.submitTarget.disabled = true;

        let response;

        try {
            response = await fetch(this.urlValue, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "Accept": "text/vnd.turbo-stream.html",
                    "X-CSRF-Token": this.tokenValue,
                },
                body: JSON.stringify({ agent: agent.value, note: this.noteTarget.value }),
            });
        } catch {
            requestFailed(null);
            this.submitTarget.disabled = false;

            return;
        }

        if (requestFailed(response)) {
            this.submitTarget.disabled = false;

            return;
        }

        const stream = await response.text();

        if (this.element.isConnected) {
            this.#closeDialog();
        }

        Turbo.renderStreamMessage(stream);
    }

    // ── Private ───────────────────────────────────────────────────────────

    #picked() {
        return this.agentTargets.find((radio) => radio.checked);
    }

    /** Through the shell's own controller, for the reason insight--report gives. */
    #closeDialog() {
        const backdrop = document.querySelector("[data-ui--modal-dialog]");

        if (null === backdrop) {
            return;
        }

        this.application
            .getControllerForElementAndIdentifier(backdrop, "ui--modal")
            ?.close();
    }
}
