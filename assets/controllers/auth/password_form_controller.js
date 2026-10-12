import { Controller } from "@hotwired/stimulus";
import { deriveAuthHash, newSalt } from "../../password_kdf.js";

/**
 * Sets a new password without sending it — for Settings → Security, and for
 * the page that makes somebody replace a password an administrator chose.
 *
 * The boxes are for typing into. Before the form goes this checks what only it
 * can see (the length, and that both boxes agree), stretches the new password
 * with a fresh salt, proves the current one the way the stored hash expects,
 * and takes the names off every box so none of them is posted. See
 * App\Form\User\NewPasswordType for the server's side of that bargain.
 *
 * "The way the stored hash expects": for an account already on a stretched
 * password, the current one is stretched with that account's own salt; for one
 * whose hash is still of the password itself, the password is the only thing
 * that can be checked, and it is sent as it is. `currentSalt` empty means the
 * latter. Either way the new password is stretched, so the account is moved
 * over by changing it.
 *
 * Values:
 *   currentSalt         — this account's salt, empty when it has none
 *   currentIterations   — its iteration count
 *   minLength           — User::PASSWORD_MIN_LENGTH
 *   currentRequiredMessage — translated
 *   tooShortMessage     — translated
 *   mismatchMessage     — translated
 *   busyLabel           — the button's text while stretching, translated
 *
 * Targets:
 *   currentPassword, currentProof   — optional; absent on the forced form
 *   newPassword, newRepeat          — the two boxes
 *   newAuthHash, newSalt, newIterations — hidden; the last is read, not written
 *   submit                          — optional; the button to mark busy
 */
export default class extends Controller {
    static targets = [
        "currentPassword",
        "currentProof",
        "newPassword",
        "newRepeat",
        "newAuthHash",
        "newSalt",
        "newIterations",
        "submit",
    ];

    static values = {
        currentSalt: String,
        currentIterations: Number,
        minLength: Number,
        currentRequiredMessage: String,
        tooShortMessage: String,
        mismatchMessage: String,
        busyLabel: String,
    };

    #busy = false;
    #ready = false;
    #idleLabel = null;

    /**
     * Clears a complaint as soon as the person starts fixing it. Without this
     * the box stays invalid after the fix, and the browser refuses the form
     * with the old message.
     */
    clearValidity(event) {
        event.target.setCustomValidity("");
    }

    async submit(event) {
        // The second pass, from requestSubmit() below: everything is filled
        // in, so let it through to Turbo or the browser.
        if (true === this.#ready) {
            this.#ready = false;
            return;
        }

        event.preventDefault();

        if (true === this.#busy || false === this.#validate()) {
            return;
        }

        this.#hold();

        try {
            if (true === this.hasCurrentPasswordTarget) {
                const current = this.currentPasswordTarget.value;

                this.currentProofTarget.value = "" === this.currentSaltValue
                    ? current
                    : await deriveAuthHash(current, this.currentSaltValue, this.currentIterationsValue);
            }

            const salt = newSalt();

            this.newSaltTarget.value = salt;
            this.newAuthHashTarget.value = await deriveAuthHash(
                this.newPasswordTarget.value,
                salt,
                Number(this.newIterationsTarget.value),
            );
        } catch {
            this.#release();
            return;
        }

        [this.currentPasswordTargets, this.newPasswordTargets, this.newRepeatTargets]
            .flat()
            .forEach((input) => input.removeAttribute("name"));

        // requestSubmit() and not submit(): it fires the submit event again,
        // which is what Turbo listens for on the settings card. No submitter:
        // neither form has more than one button, and neither reads its name.
        this.#ready = true;
        this.element.requestSubmit();
    }

    // ── Private ─────────────────────────────────────────────────────────────

    #validate() {
        // Checked here rather than left to the server: an empty current
        // password would arrive as an empty proof, and be reported as a
        // browser without JavaScript rather than as the empty box it is.
        if (true === this.hasCurrentPasswordTarget && "" === this.currentPasswordTarget.value) {
            this.currentPasswordTarget.setCustomValidity(this.currentRequiredMessageValue);
            this.currentPasswordTarget.reportValidity();

            return false;
        }

        const password = this.newPasswordTarget.value;

        // Characters, not UTF-16 units: the floor is what the person has to
        // type, as mb_strlen() counted it when the server still could.
        if (Array.from(password).length < this.minLengthValue) {
            this.newPasswordTarget.setCustomValidity(this.tooShortMessageValue);
            this.newPasswordTarget.reportValidity();

            return false;
        }

        if (password !== this.newRepeatTarget.value) {
            this.newRepeatTarget.setCustomValidity(this.mismatchMessageValue);
            this.newRepeatTarget.reportValidity();

            return false;
        }

        return true;
    }

    #hold() {
        this.#busy = true;

        if (false === this.hasSubmitTarget) {
            return;
        }

        this.submitTarget.disabled = true;
        this.submitTarget.setAttribute("aria-busy", "true");

        if ("" !== this.busyLabelValue) {
            this.#idleLabel ??= this.submitTarget.textContent;
            this.submitTarget.textContent = this.busyLabelValue;
        }
    }

    #release() {
        this.#busy = false;

        if (false === this.hasSubmitTarget) {
            return;
        }

        this.submitTarget.disabled = false;
        this.submitTarget.removeAttribute("aria-busy");

        if (null !== this.#idleLabel) {
            this.submitTarget.textContent = this.#idleLabel;
        }
    }
}
