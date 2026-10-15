import { Controller } from "@hotwired/stimulus";
import { csrfToken } from "../../csrf.js";
import { keyring } from "../../crypto/keyring.js";
import { openEncrypted } from "../../crypto/message.js";

/**
 * Decrypts, in the background, the encrypted mail of the accounts that keep
 * decrypted mail on the server, and hands each message over
 * (App\Controller\Mail\DecryptedMailController) — so search, parcels, dates
 * and rules work on it without anybody having to open it first.
 *
 * Only rendered for a person with such an account (keeps_decrypted_mail()),
 * and does nothing until the keyring is open. One message at a time, with a
 * breath between them so the page stays responsive: the crypto runs on the
 * page's own thread (the importmap is not available to workers). It asks
 * again every few minutes and whenever the tab comes back into view.
 *
 * One loop per tab, whatever Turbo does: the state lives in the module, not
 * in the controller, which connects again on every visit.
 */
let running = false;
let lastRun = 0;

const PAUSE_MS = 250;
const EVERY_MS = 5 * 60 * 1000;

export default class extends Controller {
    static values = {
        pendingUrl: String,
        keyring: Object,
    };

    #timer = null;
    #onVisible = null;

    connect() {
        this.#onVisible = () => {
            if ("visible" === document.visibilityState) {
                this.#run(false);
            }
        };
        document.addEventListener("visibilitychange", this.#onVisible);
        this.#timer = setInterval(() => this.#run(false), EVERY_MS);

        this.#run(false);
    }

    disconnect() {
        document.removeEventListener("visibilitychange", this.#onVisible);
        clearInterval(this.#timer);
    }

    async #run(force) {
        if (true === running || (false === force && Date.now() - lastRun < 30_000)) {
            return;
        }

        running = true;
        lastRun = Date.now();

        try {
            const ring = keyring(this.keyringValue);

            if ("open" !== (await ring.ensure()).status) {
                return;
            }

            for (;;) {
                const response = await fetch(this.pendingUrlValue, { cache: "no-store" });
                const pending = true === response.ok ? (await response.json()).messages ?? [] : [];
                let kept = 0;

                for (const message of pending) {
                    if (true === await this.#keep(message, ring)) {
                        kept += 1;
                    }

                    await new Promise((resolve) => setTimeout(resolve, PAUSE_MS));
                }

                // Nothing more, or nothing that would go: done until next time.
                if (0 === pending.length || 0 === kept) {
                    break;
                }
            }
        } catch {
            // A locked keyring, a dropped connection: next time.
        } finally {
            running = false;
        }
    }

    async #keep(message, ring) {
        try {
            const raw = await fetch(message.raw, { cache: "no-store" });

            if (false === raw.ok) {
                return false;
            }

            const opened = await openEncrypted(new Uint8Array(await raw.arrayBuffer()), ring);
            const answer = await fetch(message.upload, {
                method: "POST",
                body: opened.mime,
                headers: { "Content-Type": "application/octet-stream", "X-CSRF-Token": csrfToken(), "X-Requested-With": "XMLHttpRequest" },
            });

            return true === answer.ok;
        } catch {
            // Not ours to open (a key long gone), or not a message at all:
            // left for the person to see when they open it.
            return false;
        }
    }
}
