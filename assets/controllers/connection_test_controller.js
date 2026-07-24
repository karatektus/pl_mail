import { Controller } from "@hotwired/stimulus";

/**
 * Probes the currently entered IMAP/SMTP settings without saving them.
 *
 * Inputs are resolved by name suffix (account[imapHost] → imapHost) so the
 * controller needs no per-field target attributes of its own.
 */
export default class extends Controller {
    static targets = ["button", "spinner", "panel", "imapRow", "imapText", "smtpRow", "smtpText", 'imapTarget', 'smtpTarget'];

    static values = {
        url: String,
        accountId: Number,
        csrf: String,
    };

    async run() {
        this._setBusy(true);
        this.panelTarget.classList.add("hidden");

        try {
            const response = await fetch(this.urlValue, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-Token": this.csrfValue,
                },
                body: JSON.stringify(this._payload()),
            });

            if (response.ok === false) {
                this._render(
                    { ok: false, message: `Request failed (${response.status}).` },
                    { ok: false, message: "" },
                );
                return;
            }

            const result = await response.json();
            this._render(result.imap, result.smtp);
        } catch (error) {
            this._render({ ok: false, message: error.message }, { ok: false, message: "" });
        } finally {
            this._setBusy(false);
        }
    }

    // ── Private ───────────────────────────────────────────────────────────

    _payload() {
        const payload = {
            username: this._value("username"),
            password: this._value("password"),
            imapHost: this._value("imapHost"),
            imapPort: this._value("imapPort"),
            imapEncryption: this._value("imapEncryption"),
            smtpHost: this._value("smtpHost"),
            smtpPort: this._value("smtpPort"),
            smtpEncryption: this._value("smtpEncryption"),
        };

        if (this.hasAccountIdValue === true) {
            payload.accountId = this.accountIdValue;
        }

        return payload;
    }

    _value(name) {
        const field = this.element.querySelector(`[name$="[${name}]"]`);

        if (field === null) {
            return "";
        }

        return field.value.trim();
    }

    _setBusy(busy) {
        this.buttonTarget.disabled = busy;
        this.spinnerTarget.classList.toggle("hidden", busy === false);
    }

    _render(imap, smtp) {
        this.panelTarget.classList.remove("hidden");
        this.imapTargetTarget.textContent = imap.target ?? "";
        this.smtpTargetTarget.textContent = smtp.target ?? "";
        this._paint(this.imapRowTarget, this.imapTextTarget, imap);
        this._paint(this.smtpRowTarget, this.smtpTextTarget, smtp);
    }

    _paint(row, text, probe) {
        const icon = row.querySelector("i");

        // ok === null means "not configured" — neither pass nor fail.
        const isSkipped = probe.ok === null;
        const isOk = probe.ok === true;

        icon.className = isSkipped
            ? "fa-solid fa-circle-minus mt-0.5 shrink-0 text-ink-faint"
            : isOk
                ? "fa-solid fa-circle-check mt-0.5 shrink-0 text-success"
                : "fa-solid fa-circle-xmark mt-0.5 shrink-0 text-danger";

        text.textContent = probe.message;
        text.classList.toggle("text-ink-faint", isSkipped);
        text.classList.toggle("text-ink-soft", isSkipped === false);
    }
}
