import { Controller } from "@hotwired/stimulus";

/**
 * Fill the password fields of a form with one generated value and put that
 * value on the clipboard, saying so.
 *
 * Optional by design: the button sits beside ordinary password fields and
 * typing into them afterwards simply overwrites what was generated. The
 * generator exists because the export password guards every secret the
 * install has and "a password you did not think up" is the strongest kind —
 * but it is an offer, not a gate.
 *
 * The clipboard write mirrors ui--clipboard's two paths (secure-context
 * navigator.clipboard, then the execCommand scratch textarea) rather than
 * importing them: the semantics differ in the one way that matters — the
 * value never exists in the DOM as text, only as input values — so sharing
 * the select-the-source behaviour would be wrong, not just untidy.
 *
 * When neither path works the fields are switched to type="text" instead:
 * a masked password nobody was told is unreadable, and "copied" would be a
 * lie. Showing it and saying so is the honest fallback.
 */
export default class extends Controller {
    static targets = ["field", "note"];

    static values = {
        copiedText: String,
        shownText: String,
        length: { type: Number, default: 24 },
    };

    /** Unambiguous: no 0/O, 1/l/I — this password gets retyped from paper. */
    static alphabet = "abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789";

    generate() {
        const password = this._password();

        for (const field of this.fieldTargets) {
            field.value = password;
        }

        this._write(password).then((copied) => {
            if (false === copied) {
                for (const field of this.fieldTargets) {
                    field.type = "text";
                }
            }

            if (this.hasNoteTarget) {
                this.noteTarget.textContent = copied ? this.copiedTextValue : this.shownTextValue;
                this.noteTarget.classList.remove("hidden");
            }
        });
    }

    _password() {
        const alphabet = this.constructor.alphabet;
        const raw = new Uint32Array(this.lengthValue);

        crypto.getRandomValues(raw);

        return Array.from(raw, (n) => alphabet[n % alphabet.length]).join("");
    }

    async _write(text) {
        if (navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(text);

                return true;
            } catch {
                // Fall through to the legacy path, as ui--clipboard does.
            }
        }

        const active = document.activeElement;
        const scratch = document.createElement("textarea");

        scratch.value = text;
        scratch.setAttribute("readonly", "");
        scratch.style.cssText =
            "position:fixed;top:0;left:0;width:1px;height:1px;padding:0;border:0;opacity:0;";

        document.body.appendChild(scratch);
        scratch.select();
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
}
