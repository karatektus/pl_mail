import { Controller } from "@hotwired/stimulus";
import { derivePasswordSecrets } from "../../password_kdf.js";
import { remember } from "../../crypto/session.js";

/**
 * Signs in without sending the password.
 *
 * Asks /login/prelogin how to stretch the password for this address, stretches
 * it (see password_kdf.js), and posts the result in `auth_hash` with the
 * password field's name taken off, so the box keeps its value — which is what a
 * password manager reads — and the request does not carry it.
 *
 * The one exception is `migrate`: an account whose stored hash is still of the
 * password itself can only be checked against the password, so for that one
 * sign-in it is posted alongside the auth hash and the server swaps the hash
 * over. See App\Security\LoginFormAuthenticator.
 *
 * The same stretching yields the key that opens the person's keyring. It is
 * kept for the session the way crypto/session.js describes — wrapped here,
 * with only the unlock key posted as `unlock_key` — so the keyring is open on
 * the first page after signing in without the password being asked for again.
 *
 * Values:
 *   preloginUrl         — the prelogin endpoint
 *   busyLabel           — the button's text while stretching, translated
 *   failedMessage       — shown when the prelogin could not be reached
 *   rateLimitedMessage  — shown when it answered 429
 *
 * Targets:
 *   email, password     — the two boxes
 *   authHash            — hidden input the result goes into
 *   unlockKey           — hidden input for the session's unlock key
 *   submit              — the button
 *   error               — where a failure is said, hidden until then
 */
export default class extends Controller {
    static targets = ["email", "password", "authHash", "unlockKey", "submit", "error"];
    static values = {
        preloginUrl: String,
        busyLabel: String,
        failedMessage: String,
        rateLimitedMessage: String,
    };

    #busy = false;
    #idleLabel = null;
    #onPageShow = null;

    connect() {
        // Back to a page the browser kept whole: the password box has no name
        // and the button says it is busy, and no Stimulus lifecycle event says
        // so. Put the form back the way a fresh one is.
        this.#onPageShow = (event) => {
            if (true === event.persisted) {
                this.#reset();
            }
        };

        window.addEventListener("pageshow", this.#onPageShow);
    }

    disconnect() {
        window.removeEventListener("pageshow", this.#onPageShow);
    }

    /**
     * Runs after the browser's own checks: the submit event does not fire for
     * a form with an empty required field.
     */
    async submit(event) {
        event.preventDefault();

        if (true === this.#busy) {
            return;
        }

        this.#hold();

        try {
            const params = await this.#prelogin();

            const { authHash, wrapKey } = await derivePasswordSecrets(this.passwordTarget.value, params.salt, params.iterations);

            this.authHashTarget.value = authHash;
            this.unlockKeyTarget.value = await remember(wrapKey);

            if (true !== params.migrate) {
                this.passwordTarget.removeAttribute("name");
            }

            // HTMLFormElement.submit(), which fires no submit event — so this
            // handler does not run a second time — and skips validation that
            // has already passed.
            this.element.submit();
        } catch (error) {
            this.#reset();
            this.#fail("rate_limited" === error?.message ? this.rateLimitedMessageValue : this.failedMessageValue);
        }
    }

    // ── Private ─────────────────────────────────────────────────────────────

    async #prelogin() {
        const response = await fetch(this.preloginUrlValue, {
            method: "POST",
            headers: { Accept: "application/json" },
            body: new URLSearchParams({ email: this.emailTarget.value }),
        });

        if (429 === response.status) {
            throw new Error("rate_limited");
        }

        if (false === response.ok) {
            throw new Error("prelogin_failed");
        }

        return response.json();
    }

    #hold() {
        this.#busy = true;
        this.errorTarget.hidden = true;
        this.submitTarget.disabled = true;
        this.submitTarget.setAttribute("aria-busy", "true");

        if ("" !== this.busyLabelValue) {
            this.#idleLabel ??= this.submitTarget.textContent;
            this.submitTarget.textContent = this.busyLabelValue;
        }
    }

    #reset() {
        this.#busy = false;
        this.authHashTarget.value = "";
        this.unlockKeyTarget.value = "";
        this.passwordTarget.setAttribute("name", "password");
        this.submitTarget.disabled = false;
        this.submitTarget.removeAttribute("aria-busy");

        if (null !== this.#idleLabel) {
            this.submitTarget.textContent = this.#idleLabel;
        }
    }

    #fail(message) {
        this.errorTarget.textContent = message;
        this.errorTarget.hidden = false;
    }
}
