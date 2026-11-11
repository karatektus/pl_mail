/* stimulusFetch: 'lazy' */
import { Controller } from "@hotwired/stimulus";

/**
 * Offers plMail to the browser as its handler for `mailto:` links.
 *
 * `navigator.registerProtocolHandler` only ASKS: the browser shows its own
 * prompt (Firefox a bar under the address bar, Chromium an icon in it), and
 * whether the reader accepted is not something a page is ever told —
 * `isProtocolHandlerRegistered` was specified and never shipped. So this says
 * where to look for the prompt and does not claim an outcome it cannot see.
 *
 * Three states, decided on connect:
 *   - no registerProtocolHandler at all (Safari, every iOS browser, Firefox on
 *     Android): the button stays disabled and says so, rather than doing
 *     nothing when pressed;
 *   - not a secure context (plain http on anything but localhost): the call
 *     exists but throws, which is a setup problem the operator can fix, so it
 *     is named as one;
 *   - otherwise: the button works.
 *
 * Values:
 *   url     — the handler URL with its `%s`, built server-side from the route
 *             ComposeController reads (`/compose/new?mailto=%s`)
 *   labels  — state name → text, translated server-side
 *
 * Targets:
 *   button  — the "Use plMail" button
 *   state   — the line under it that says what happened
 */
export default class extends Controller {
    static targets = ["button", "state"];

    static values = {
        url:    String,
        labels: Object,
    };

    connect() {
        if (typeof navigator.registerProtocolHandler !== "function") {
            this.#disable("unsupported");

            return;
        }

        if (false === window.isSecureContext) {
            this.#disable("insecure");
        }
    }

    register() {
        try {
            // Absolute, because the specification resolves a relative one
            // against the document's base URL, and Firefox has refused
            // anything but a same-origin absolute URL in the past.
            navigator.registerProtocolHandler("mailto", new URL(this.urlValue, window.location.href).href);
            this.#say("asked");
        } catch {
            this.#say("failed");
        }
    }

    // ── Private ───────────────────────────────────────────────────────────

    #disable(state) {
        if (this.hasButtonTarget) {
            this.buttonTarget.disabled = true;
        }

        this.#say(state);
    }

    #say(state) {
        if (this.hasStateTarget) {
            this.stateTarget.textContent = this.labelsValue[state] ?? "";
            this.stateTarget.hidden = "" === this.stateTarget.textContent;
        }
    }
}
