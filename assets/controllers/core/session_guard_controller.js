import { Controller } from "@hotwired/stimulus";

/**
 * What happens when the session ends while a page is still open.
 *
 * Turbo's own answer is to follow the 302 to /login and swap the login form
 * into the application shell. The result is a page that looks like the app has
 * come apart — a login box wearing the sidebar — and, worse, a page whose
 * controllers are all still mounted and still polling with a cookie that no
 * longer means anything. Every one of those requests is refused, so the console
 * fills with failures nobody can act on.
 *
 * ## The one interesting decision
 *
 * "Reload the page" is the obvious response and it is wrong exactly when it
 * matters most. A mail half-written in the composer lives in a contenteditable;
 * the autosave that would have preserved it is a POST, and a POST is the thing
 * that has just stopped working. So reloading at the moment the session ends is
 * reloading at the one moment the server copy is guaranteed to be behind.
 *
 * So the bar offers two different things depending on what is on screen:
 *
 *   - nothing being written  → sign in, straightforwardly, in this tab.
 *   - something being written → sign in in a NEW tab, then come back. This tab
 *     is left exactly as it is, text and all, and the next autosave lands
 *     because the cookie is shared across tabs.
 *
 * Reload stays available either way, for someone who would rather start clean.
 *
 * ## How the end of a session is noticed
 *
 * Turbo reports every fetch it makes, and an expired session shows up in two
 * shapes: a redirect to the login page (Turbo Drive visits, frame loads) or a
 * bare 401/403 (the JSON POSTs the Stimulus controllers make). Both are watched.
 * A redirect is matched by the URL Turbo ended up at rather than by status,
 * because the fetch API follows redirects before Turbo ever sees them — the
 * response reports 200 and a location of /login.
 */
export default class extends Controller {
    static targets = ["body", "signIn"];
    static values = { loginUrl: String };

    connect() {
        this._onResponse = (event) => this._inspect(event);
        document.addEventListener("turbo:before-fetch-response", this._onResponse);

        // Anything not going through Turbo — the controllers that fetch() for
        // themselves — reports through this instead. announceSignedOut() below
        // is what they call.
        this._onSignedOut = () => this.show();
        document.addEventListener(SIGNED_OUT_EVENT, this._onSignedOut);
    }

    disconnect() {
        document.removeEventListener("turbo:before-fetch-response", this._onResponse);
        document.removeEventListener(SIGNED_OUT_EVENT, this._onSignedOut);
    }

    /**
     * @param {CustomEvent} event
     */
    _inspect(event) {
        const response = event.detail?.fetchResponse;

        if (!response) {
            return;
        }

        const status = response.statusCode;
        const url = response.response?.url ?? "";

        const landedOnLogin = url.length > 0 && new URL(url, window.location.origin).pathname === this.loginUrlValue;

        if (false === landedOnLogin && 401 !== status && 403 !== status) {
            return;
        }

        this.show();

        // Only the redirect is cancelled, and only because Turbo would
        // otherwise swap a login form into the application shell behind the
        // bar. A bare 401 or 403 is left to travel: the controller that made
        // that request usually has something better to say about it than this
        // does — the compose dock answers one with "your session has expired,
        // reload and sign in", in the place the user just clicked. Cancelling
        // those too made the bar the only feedback and took the specific
        // message away, which the specs caught.
        if (true === landedOnLogin) {
            event.preventDefault();
        }
    }

    show() {
        if (false === this.element.classList.contains("hidden")) {
            return;
        }

        const writing = this._isWriting();

        this.bodyTarget.textContent = writing
            ? this.bodyTarget.dataset.bodyWriting
            : this.bodyTarget.dataset.body;

        this.signInTarget.textContent = writing
            ? this.signInTarget.dataset.labelNewTab
            : this.signInTarget.dataset.label;

        // A new tab only in the case that needs one. Opening one when this tab
        // holds nothing worth keeping just leaves the user with two tabs.
        if (true === writing) {
            this.signInTarget.target = "_blank";
            this.signInTarget.rel = "noopener";
        } else {
            this.signInTarget.removeAttribute("target");
        }

        this.element.classList.remove("hidden");
    }

    reload() {
        window.location.reload();
    }

    /**
     * Is there a composer on screen with something in it?
     *
     * Deliberately generous: any open compose window that is not empty counts,
     * including one holding only a quote or a signature. Being wrong in this
     * direction costs a tab; being wrong in the other costs what somebody
     * wrote.
     */
    _isWriting() {
        const editors = document.querySelectorAll(
            '.compose-window [data-compose--compose-toolbar-target="editor"], .compose-window textarea',
        );

        return [...editors].some((editor) => (editor.value ?? editor.textContent ?? "").trim().length > 0);
    }
}

/** Dispatched by anything that fetches outside Turbo and gets a 401/403. */
export const SIGNED_OUT_EVENT = "core--session:signed-out";

/**
 * Tell the guard the session has gone.
 *
 * Exported so the controllers that POST JSON for themselves have one line to
 * call rather than each inventing their own idea of what an expired session
 * looks like — the same argument as announceWrite() in mail_writes.js.
 */
export function announceSignedOut() {
    document.dispatchEvent(new CustomEvent(SIGNED_OUT_EVENT, { bubbles: true }));
}
