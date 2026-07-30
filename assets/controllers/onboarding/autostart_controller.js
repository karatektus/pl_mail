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
 *   Closing the dialog does NOT finish setup. It used to, on the reasoning that
 *   someone who dismisses it has answered — but a dialog closes for reasons the
 *   user did not choose, and each of those permanently ended setup with no way
 *   back but the user menu. That is a lot to lose to a stray click. Closing now
 *   quietens it for this tab only; the next session offers it again, until it is
 *   actually finished or skipped.
 */
export default class extends Controller {
    static values = {
        /**
         * Identifies this login. sessionStorage belongs to the tab and outlives
         * both the session and the user, so an unkeyed guard meant a tab that
         * had dismissed the wizard once never saw it again — not after signing
         * out and back in, and not after a reset had built a new administrator
         * in that same tab.
         */
        offerKey: String,
    }

    connect() {
        this._onClosed = this._handleClosed.bind(this)
        document.addEventListener("ui--modal:closed", this._onClosed)

        // Shown once per login — except when it was open a moment ago and the
        // page went out from under it. A background refresh replacing the
        // document must not be able to strand someone's setup, so an open
        // wizard comes back after one.
        const wasOpen = sessionStorage.getItem(this._openKey) === "1"

        if (sessionStorage.getItem(this._shownKey) === "1" && wasOpen === false) {
            return
        }

        const modal = this.application.getControllerForElementAndIdentifier(this.element, "ui--modal")

        if (!modal) {
            console.warn("[onboarding] no ui--modal controller on the boot element")
            return
        }

        sessionStorage.setItem(this._shownKey, "1")
        sessionStorage.setItem(this._openKey, "1")
        this._opened = true

        // Reopening lands on the remembered step, so a wizard restored this way
        // picks up where it was rather than starting over.
        modal.open()
    }

    disconnect() {
        document.removeEventListener("ui--modal:closed", this._onClosed)
    }

    _handleClosed() {
        // Only our own dialog: the modal shell is shared, and closing a label
        // form has nothing to do with setup.
        if (!this._opened) {
            return
        }

        this._opened = false

        // Nothing is written to the server. Setup stays unfinished until the
        // user finishes or skips it; the shown-key is what keeps it from
        // reopening on every page load in the meantime.
        sessionStorage.removeItem(this._openKey)
    }

    /** Already offered to this person, this session. */
    get _shownKey() {
        return `plmail:onboarding-autostarted:${this.offerKeyValue}`
    }

    /** On screen right now — the difference is what brings it back. */
    get _openKey() {
        return `plmail:onboarding-open:${this.offerKeyValue}`
    }
}
