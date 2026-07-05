import { Controller } from "@hotwired/stimulus"

export default class extends Controller {
    static values  = { duration: { type: Number, default: 4000 } }
    static targets = ['countdown']

    connect() {
        this.element.style.opacity   = "0"
        this.element.style.transform = "translateY(8px)"

        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                this.element.style.transition = "opacity 200ms, transform 200ms"
                this.element.style.opacity    = "1"
                this.element.style.transform  = "translateY(0)"
            })
        })

        this._remaining = Math.round(this.durationValue / 1000)
        this._updateCountdown()

        this._interval = setInterval(() => {
            this._remaining--
            this._updateCountdown()
            if (this._remaining <= 0) this.dismiss()
        }, 1000)
    }

    disconnect() {
        clearInterval(this._interval)
    }

    _updateCountdown() {
        if (this.hasCountdownTarget) {
            this.countdownTarget.textContent = this._remaining
        }
    }

    dismiss() {
        clearInterval(this._interval)
        this.element.style.opacity   = "0"
        this.element.style.transform = "translateY(8px)"
        this.element.addEventListener("transitionend", () => this.element.remove(), { once: true })
    }
}
