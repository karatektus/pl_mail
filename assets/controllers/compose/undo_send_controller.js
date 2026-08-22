import { Controller } from "@hotwired/stimulus"

/**
 * The Undo button in the "Sending…" toast.
 *
 * Wired again, and for the default path. Send has two shapes now, chosen by
 * User::SETTING_COMPOSE_SEND_FEEDBACK: "hold" keeps the composer open and makes
 * its own Send pill the cancel, and "optimistic" — the default — closes the
 * composer at once and moves the cancel here. The wait is what people notice;
 * the undo is what they rarely need.
 *
 * This was unwired for one release while the held shape was tried, and the
 * complaint that brought it back was the obvious one: eight seconds of a window
 * that will not close is a long time when you have finished writing.
 *
 * `hideAfter` IS the cancel window rather than a toast duration. The button
 * goes when the toast goes, so a toast that outlived the window would offer a
 * cancel that silently fails, and one that died early would take away a cancel
 * that still worked.
 */

export default class extends Controller {
    static values = {
        url:       String,
        hideAfter: Number,
    }

    connect() {
        this.hideTimer = setTimeout(() => {
            this.element.style.transition  = "opacity 0.4s"
            this.element.style.opacity     = "0"
            this.element.style.pointerEvents = "none"
        }, this.hideAfterValue)
    }

    disconnect() {
        clearTimeout(this.hideTimer)
    }

    async abort() {
        clearTimeout(this.hideTimer)
        this.element.style.pointerEvents = "none"

        const response = await fetch(this.urlValue, {
            method: "POST",
            headers: { "X-Requested-With": "XMLHttpRequest" },
        })

        if (response.ok) {
            const html = await response.text()
            Turbo.renderStreamMessage(html)

            // Dismiss the parent toast immediately
            this.element.closest("[data-controller~='ui--toast']")
                ?.__stimulusController?.dismiss()
            ?? this.element.closest("[data-controller~='ui--toast']")?.remove()
        }
    }
}
