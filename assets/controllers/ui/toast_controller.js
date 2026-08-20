import { Controller } from "@hotwired/stimulus"
import { leave } from "../../motion.js"

/**
 * A toast: it arrives on its own, and it leaves on its own.
 *
 * The entrance is declared in the markup (`data-enter="rise"` in
 * _partials/_toast.html.twig) and played by motion.js, because a toast almost
 * always arrives in a turbo-stream — inserted into a document that painted
 * minutes ago, which is precisely the case CSS alone cannot see.
 *
 * The exit is the one this controller has to drive, and it is the reason
 * motion.css keeps an exit animation at all. Everything else in plMail leaves
 * by a jump cut on purpose — an exit animation is time between asking for a
 * thing and getting it. A toast is the exception: nobody asked for it to go, it
 * goes by itself four seconds later, and a strip of text that was in the corner
 * of the eye and then simply is not reads as a glitch rather than as a
 * dismissal.
 *
 * This used to hand-roll both halves in inline styles — opacity and a
 * translateY set from JavaScript, with hardcoded 200ms transitions that no
 * motion setting and no `prefers-reduced-motion` could reach. Both are now the
 * shared tokens, so the toast is as calm as the rest of the app is asked to be.
 */
export default class extends Controller {
    static values  = { duration: { type: Number, default: 4000 } }
    static targets = ['countdown']

    connect() {
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

        // leave() removes the node itself once the animation is done, and
        // removes it in the same tick when motion is off — the `none` tier or
        // reduced motion. That matters beyond taste: the old code waited for a
        // `transitionend` that a zero-duration transition never fires, so a
        // toast could outlive its own dismissal indefinitely.
        leave(this.element)
    }
}
