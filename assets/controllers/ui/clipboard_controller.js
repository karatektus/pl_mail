import { Controller } from "@hotwired/stimulus";

/**
 * Copy the text of the "source" target to the clipboard and flash a
 * confirmation on the "label" target.
 *
 * navigator.clipboard needs a secure context, which a plain-HTTP dev host is
 * not — so there is a selection-based fallback rather than a dead button.
 */
export default class extends Controller {
    static targets = ["source", "label"];

    static values = {
        confirmText: { type: String, default: "Copied" },
        resetAfter: { type: Number, default: 2000 },
    };

    copy() {
        const text = this.sourceTarget.textContent.trim();

        this._write(text).then(() => this._flash());
    }

    async _write(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }

        // Fallback: select the element's text so ctrl+c works, since without a
        // secure context the async API is unavailable.
        const range = document.createRange();
        range.selectNodeContents(this.sourceTarget);

        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);

        return Promise.resolve();
    }

    _flash() {
        if (false === this.hasLabelTarget) {
            return;
        }

        const original = this.labelTarget.textContent;
        this.labelTarget.textContent = this.confirmTextValue;

        clearTimeout(this._timer);
        this._timer = setTimeout(() => {
            this.labelTarget.textContent = original;
        }, this.resetAfterValue);
    }

    disconnect() {
        clearTimeout(this._timer);
    }
}
