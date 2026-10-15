import { Controller } from "@hotwired/stimulus"

/**
 * Opens What's new by itself after an update (#165).
 *
 * Sits on the element whats_new/_boot.html.twig renders, beside ui--modal,
 * and calls its open() once — the pattern onboarding's autostart set.
 *
 * The server stops rendering that element as soon as the dialog has been
 * drawn once (drawing it records the release as seen), so most of the time
 * this runs once and is gone. The guard is for the one case the server cannot
 * see: Turbo replaying a cached snapshot of a page from before, element and
 * all, on Back. Keyed on the release, so the next update opens it again.
 */
export default class extends Controller {
    static values = { release: String }

    connect() {
        const key = `plmail:whats-new-opened:${this.releaseValue}`

        try {
            if (sessionStorage.getItem(key) === "1") {
                return
            }

            sessionStorage.setItem(key, "1")
        } catch (_) {
            // Storage refused (a private window): the server-side record is
            // what really keeps this from repeating, so open anyway.
        }

        const modal = this.application.getControllerForElementAndIdentifier(this.element, "ui--modal")

        if (!modal) {
            console.warn("[whats-new] no ui--modal controller on the boot element")
            return
        }

        modal.open()
    }
}
