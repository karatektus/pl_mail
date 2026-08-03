import { Controller } from "@hotwired/stimulus";

/**
 * Copy the text of the "source" target to the clipboard and flash a
 * confirmation on the "label" target.
 *
 * navigator.clipboard needs a secure context, which a self-hosted plMail on a
 * plain-HTTP LAN hostname is not — so there is a deprecated-but-working
 * execCommand path behind it rather than a dead button. Selecting the text and
 * calling it done is not enough on its own: a phone has no ctrl+c, and the
 * source may not even be on screen (the admin log view copies composed text
 * from a hidden element).
 */
export default class extends Controller {
    static targets = ["source", "label"];

    static values = {
        confirmText: { type: String, default: "Copied" },
        failedText: { type: String, default: "Copy failed" },
        resetAfter: { type: Number, default: 2000 },
    };

    copy() {
        const text = this.sourceTarget.textContent.trim();

        this._write(text).then((copied) => this._flash(copied));
    }

    async _write(text) {
        if (navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(text);

                return true;
            } catch {
                // Denied by permissions or an iframe policy. Fall through to
                // the legacy path instead of reporting a failure we can still
                // avoid.
            }
        }

        const copied = this._execCopy(text);

        // The highlight is the only feedback the icon-only call sites have —
        // they carry no label target — so it stays either way.
        this._select();

        return copied;
    }

    /**
     * The one write path an insecure context has. Copies from a scratch
     * textarea rather than from the source element, because the text we owe
     * the clipboard is not always what the source renders, and may not be
     * rendered at all.
     */
    _execCopy(text) {
        const active = document.activeElement;
        const scratch = document.createElement("textarea");

        scratch.value = text;
        scratch.setAttribute("readonly", "");
        // Off screen but painted: execCommand will not copy from a hidden
        // element, and a laid-out one would scroll the page as it takes focus.
        scratch.style.cssText =
            "position:fixed;top:0;left:0;width:1px;height:1px;padding:0;border:0;opacity:0;";

        document.body.appendChild(scratch);
        scratch.select();
        // iOS ignores select() on a readonly field.
        scratch.setSelectionRange(0, text.length);

        let copied = false;

        try {
            copied = document.execCommand("copy");
        } catch {
            copied = false;
        }

        scratch.remove();
        active?.focus?.();

        return copied;
    }

    /** Highlights the source, when there is something on screen to highlight. */
    _select() {
        if (0 === this.sourceTarget.getClientRects().length) {
            return;
        }

        const range = document.createRange();
        range.selectNodeContents(this.sourceTarget);

        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
    }

    _flash(copied = true) {
        if (false === this.hasLabelTarget) {
            return;
        }

        const original = this.labelTarget.textContent;
        this.labelTarget.textContent = copied ? this.confirmTextValue : this.failedTextValue;

        clearTimeout(this._timer);
        this._timer = setTimeout(() => {
            this.labelTarget.textContent = original;
        }, this.resetAfterValue);
    }

    disconnect() {
        clearTimeout(this._timer);
    }
}
