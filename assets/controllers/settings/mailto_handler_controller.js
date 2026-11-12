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
 * Four states, decided on connect:
 *   - running as the INSTALLED app (#587): the button is the wrong tool here.
 *     registerProtocolHandler registers with the browser, which only covers
 *     links clicked inside Chrome; a link from another program reaches the app
 *     only when the SYSTEM's default mail program is the app, and no web API
 *     can ask the system for that — not on macOS, where native apps can, and
 *     not on Windows, where nothing but the user can. So the button goes and
 *     the steps for this system are shown in its place;
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
        if (this.#installed()) {
            if (this.hasButtonTarget) {
                this.buttonTarget.hidden = true;
            }

            this.#say(`app_${this.#system()}`);

            return;
        }

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

    /**
     * Whether this page is the installed app's window rather than a tab. The
     * manifest asks for `standalone`; the other two are what a browser may
     * give an installed app instead, and none of them is ever a tab.
     */
    #installed() {
        return ["standalone", "minimal-ui", "window-controls-overlay"]
            .some((mode) => window.matchMedia(`(display-mode: ${mode})`).matches);
    }

    /**
     * Which system's steps to show: macos, windows, linux, or other. Client
     * hints first (Chromium, which is the only engine that installs apps with
     * protocol handlers), the legacy platform string as the fallback.
     */
    #system() {
        const platform = String(navigator.userAgentData?.platform || navigator.platform || "").toLowerCase();

        if (platform.startsWith("mac")) return "macos";
        if (platform.startsWith("win")) return "windows";
        if (platform.startsWith("linux")) return "linux";

        return "other";
    }

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
