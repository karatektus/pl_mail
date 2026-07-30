import { Controller } from "@hotwired/stimulus"

/**
 * Opens the setup wizard by itself, once, for a user who has not been through it.
 *
 * Sits on the same element as ui--modal and drives it, rather than duplicating
 * any of the dialog handling: the src, title and size all live in that
 * controller's values, set in _boot.html.twig.
 *
 * Two things it is careful about:
 *
 *   Turbo caches page snapshots and replays them on a restoration visit, so a
 *   plain "have I run" flag on the instance would re-open the wizard every time
 *   the user pressed Back. The guard is in sessionStorage, keyed per tab, so it
 *   survives those replays and still lets a new session start fresh.
 *
 *   Dismissing the dialog counts as finishing. The wizard opens by itself
 *   exactly once, so a user who closes it has answered the question — anything
 *   else means nagging them on every page load. The user menu is how they get
 *   back to it.
 */
export default class extends Controller {
    static values = {
        finishUrl: String,
        token: String,
    }

    connect() {
        this._onClosed = this._handleClosed.bind(this)
        document.addEventListener("ui--modal:closed", this._onClosed)

        if (sessionStorage.getItem(GUARD_KEY)) {
            return
        }

        const modal = this.application.getControllerForElementAndIdentifier(this.element, "ui--modal")

        if (!modal) {
            console.warn("[onboarding] no ui--modal controller on the boot element")
            return
        }

        sessionStorage.setItem(GUARD_KEY, "1")
        this._opened = true
        modal.open()
    }

    disconnect() {
        document.removeEventListener("ui--modal:closed", this._onClosed)
    }

    _handleClosed() {
        // Only our own dialog: the modal shell is shared, and closing a label
        // form must not end anyone's setup.
        if (!this._opened) {
            return
        }

        this._opened = false

        const body = new FormData()
        body.append("_token", this.tokenValue)

        // keepalive so the write still lands if the close was the user
        // navigating away in the same breath.
        fetch(this.finishUrlValue, { method: "POST", body, keepalive: true })
            .catch(() => {
                // Nothing to recover to — the wizard reopens on the next page
                // load, which is the safe direction to fail in.
            })
    }
}

/** Per-tab, so a Turbo restoration visit does not re-open the wizard. */
const GUARD_KEY = "plmail:onboarding-autostarted"
