import { Controller } from "@hotwired/stimulus";

/**
 * Copy the text of the "source" target to the clipboard, and say so.
 *
 * Targets:
 *   source  — the element whose textContent is copied. May be hidden, and
 *             often is: the block a call site owes the clipboard is composed
 *             in Twig and rendered off-screen (admin logs, mail headers).
 *   label   — optional; its text becomes confirmText/failedText and goes back.
 *             Give it `sr-only` when the icon is the visible confirmation and
 *             the words are for a screen reader.
 *   icon    — optional; its `idleIcon` class is exchanged for confirmIcon or
 *             failedIcon and back. For icon-only buttons, which otherwise had
 *             no confirmation at all — the source highlight below is invisible
 *             when the source is, and there is no label to swap.
 *
 * Values: confirmText, failedText (translate them at the call site — the
 * defaults here are English), idleIcon/confirmIcon/failedIcon (Font Awesome
 * glyph classes, style class untouched), resetAfter (ms).
 *
 * navigator.clipboard needs a secure context, which a self-hosted plMail on a
 * plain-HTTP LAN hostname is not — so there is a deprecated-but-working
 * execCommand path behind it rather than a dead button. Selecting the text and
 * calling it done is not enough on its own: a phone has no ctrl+c, and the
 * source may not even be on screen.
 */
export default class extends Controller {
    static targets = ["source", "label", "icon"];

    static values = {
        confirmText: { type: String, default: "Copied" },
        failedText: { type: String, default: "Copy failed" },
        idleIcon: { type: String, default: "fa-copy" },
        confirmIcon: { type: String, default: "fa-check" },
        failedIcon: { type: String, default: "fa-triangle-exclamation" },
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

    /**
     * Says so, on whichever of the two the call site gave us.
     *
     * The icon half exists because an icon-only button had no confirmation at
     * all: the source highlight is invisible when the source is a hidden
     * element, and there is no label to swap. A copy button in a mail header
     * sits next to the address it copies, where a word would not fit but a
     * check mark does.
     */
    _flash(copied = true) {
        clearTimeout(this._timer);

        const restore = [];

        if (true === this.hasLabelTarget) {
            const original = this.labelTarget.textContent;

            this.labelTarget.textContent = copied ? this.confirmTextValue : this.failedTextValue;
            restore.push(() => {
                this.labelTarget.textContent = original;
            });
        }

        if (true === this.hasIconTarget) {
            const swapped = copied ? this.confirmIconValue : this.failedIconValue;

            // Only the glyph class is exchanged: the style (fa-solid), the
            // size and the colour beside it belong to the button, not to the
            // state, and rewriting the whole class list would take them too.
            this.iconTarget.classList.remove(this.idleIconValue);
            this.iconTarget.classList.add(swapped);

            restore.push(() => {
                this.iconTarget.classList.remove(swapped);
                this.iconTarget.classList.add(this.idleIconValue);
            });
        }

        if (0 === restore.length) {
            return;
        }

        this._timer = setTimeout(() => {
            for (const undo of restore) {
                undo();
            }
        }, this.resetAfterValue);
    }

    disconnect() {
        clearTimeout(this._timer);
    }
}
