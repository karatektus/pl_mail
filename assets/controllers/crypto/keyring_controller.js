import { Controller } from "@hotwired/stimulus";
import { keyring } from "../../crypto/keyring.js";
import { forget } from "../../crypto/session.js";
import { showToast } from "../../toast.js";

/**
 * Opens the keyring once per page load and keeps it complete — see
 * templates/crypto/_keyring_boot.html.twig for what that means, and
 * crypto/keyring.js for how.
 *
 * Once per page load, not per Turbo visit: the keyring lives in the module and
 * survives visits, and the body this sits in does not. The `booted` flag below
 * is the module's, for the same reason.
 *
 * Silent on a session it cannot open — remember-me, single sign-on, an account
 * on the encryption passphrase that has not set it up: nothing is asked at
 * sign-in, and whatever needs the keyring later asks through
 * Keyring.requireOpen().
 *
 * It also forgets the session's stored half of the unlock on sign-out, which
 * is a form submission the browser makes and no Stimulus controller owns.
 *
 * Values:
 *   stateUrl, unlockUrl, preloginUrl, csrf, userId, email  — the keyring's config
 *   settingsUrl    — Settings → Encryption, where the nags point
 *   messages       — translated: lockedKeys, recovered, action
 */
let booted = false;

const NAGGED = "plmail.e2e.nagged";

export default class extends Controller {
    static values = {
        stateUrl: String,
        unlockUrl: String,
        preloginUrl: String,
        csrf: String,
        userId: String,
        email: String,
        settingsUrl: String,
        messages: Object,
    };

    #onSubmit = null;

    connect() {
        this.#onSubmit = (event) => {
            const action = event.target instanceof HTMLFormElement ? new URL(event.target.action, location.href).pathname : "";

            if (action.endsWith("/logout")) {
                forget();
            }
        };

        document.addEventListener("submit", this.#onSubmit, true);

        if (true === booted) {
            return;
        }

        booted = true;
        this.#boot();
    }

    disconnect() {
        document.removeEventListener("submit", this.#onSubmit, true);
    }

    // ── Private ─────────────────────────────────────────────────────────────

    async #boot() {
        const ring = keyring({
            stateUrl: this.stateUrlValue,
            unlockUrl: this.unlockUrlValue,
            preloginUrl: this.preloginUrlValue,
            csrf: this.csrfValue,
            userId: this.userIdValue,
            email: this.emailValue,
        });

        let result;

        try {
            result = await ring.ensure();
        } catch (error) {
            // A keyring that could not be opened is not something to interrupt
            // reading mail with; Settings → Encryption says what is wrong.
            console.warn("Keyring:", error);

            return;
        }

        window.dispatchEvent(new CustomEvent("crypto:keyring", { detail: result }));

        if ("open" !== result.status) {
            return;
        }

        // Only what changed under the person: keys reopened, or keys waiting
        // for the phrase. A missing phrase is not news on every first page
        // load — it shared the toast stack with whatever the person had just
        // done (a send's undo, say) — so it is said where it can be acted on,
        // on Settings → Encryption, and nowhere else.
        if (0 < result.recovered) {
            this.#nag(this.messagesValue.recovered, "success", false);
        } else if (0 < ring.lockedUserKeys().length) {
            this.#nag(this.messagesValue.lockedKeys, "info", true);
        }
    }

    /**
     * A toast, at most once per tab — a reminder that comes back on every page
     * is one people learn to click away without reading.
     */
    #nag(message, type, withLink) {
        try {
            if (null !== sessionStorage.getItem(NAGGED)) {
                return;
            }

            sessionStorage.setItem(NAGGED, "1");
        } catch {
            // Without sessionStorage it nags once per page load, which is
            // still only on a full load.
        }

        const toast = showToast(message, { type });

        if (null === toast || false === withLink || window.location.href.includes("section=encryption")) {
            return;
        }

        const link = document.createElement("a");

        link.href = this.settingsUrlValue;
        link.className = "ml-2 font-medium underline";
        link.textContent = this.messagesValue.action;
        toast.querySelector("[data-toast-message]")?.append(link);
    }
}
