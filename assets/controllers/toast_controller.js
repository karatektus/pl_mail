// assets/controllers/toast_controller.js
import { Controller } from "@hotwired/stimulus"

export default class extends Controller {
    static values = { duration: { type: Number, default: 4000 } }

    connect() {
        // Animate in
        this.element.style.opacity = "0"
        this.element.style.transform = "translateY(8px)"
        requestAnimationFrame(() => {
            this.element.style.transition = "opacity 200ms, transform 200ms"
            this.element.style.opacity = "1"
            this.element.style.transform = "translateY(0)"
        })

        this._timer = setTimeout(() => this.dismiss(), this.durationValue)
    }

    disconnect() {
        clearTimeout(this._timer)
    }

    dismiss() {
        this.element.style.opacity = "0"
        this.element.style.transform = "translateY(8px)"
        this.element.addEventListener("transitionend", () => this.element.remove(), { once: true })
    }
}
