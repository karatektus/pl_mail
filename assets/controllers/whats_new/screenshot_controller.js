import { Controller } from "@hotwired/stimulus"

/**
 * "Attach a screenshot of the page you were on", in What's new → Send feedback (#165).
 *
 * Draws the page behind the dialog into a PNG, in the browser, when the box is
 * ticked, and puts it in a hidden field as a data: URL; FeedbackMailer checks
 * the bytes and attaches them. Nothing is taken unless somebody asks, and the
 * picture is shown under the box before it is sent, so what leaves is what
 * they saw.
 *
 * html-to-image is imported here, on the tick, not at page load: almost nobody
 * sends feedback, and nobody else should download a page renderer for it.
 *
 * The dialog itself is left out of the picture — its backdrop is filtered out
 * of the clone — because a screenshot of the feedback form is not what anybody
 * means by "the page I was on".
 */
export default class extends Controller {
    static targets = ["toggle", "data", "status", "preview", "submit"]

    async toggle() {
        if (false === this.toggleTarget.checked) {
            this._clear()
            return
        }

        this.statusTarget.textContent = this.statusTarget.dataset.taking
        this.submitTarget.disabled = true

        try {
            const { toPng } = await import("html-to-image")
            const backdrop = document.getElementById("modal-backdrop")

            const url = await toPng(document.body, {
                pixelRatio: 1,
                // The dialog is fixed over the page; without it the page is
                // what remains.
                filter: (node) => node !== backdrop,
                backgroundColor: getComputedStyle(document.body).backgroundColor,
            })

            if (false === this.toggleTarget.checked) {
                return
            }

            this.dataTarget.value = url
            this.previewTarget.src = url
            this.previewTarget.hidden = false
            this.statusTarget.textContent = ""
        } catch (error) {
            console.warn("[whats-new] screenshot failed", error)
            this.toggleTarget.checked = false
            this._clear()
            this.statusTarget.textContent = this.statusTarget.dataset.failed
        } finally {
            this.submitTarget.disabled = false
        }
    }

    _clear() {
        this.dataTarget.value = ""
        this.previewTarget.hidden = true
        this.previewTarget.removeAttribute("src")
        this.statusTarget.textContent = ""
    }
}
