import { Controller } from "@hotwired/stimulus"

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
            this.element.closest("[data-controller~='toast']")
                ?.__stimulusController?.dismiss()
            ?? this.element.closest("[data-controller~='toast']")?.remove()
        }
    }
}
