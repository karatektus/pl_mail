import { Controller } from "@hotwired/stimulus"

/**
 * A scrolling strip that opens with its current item in view.
 *
 * The category tabs overflow sideways on a narrow card, and the strip is
 * re-rendered on every tab change, so each render starts scrolled to the left.
 * Without this, choosing Forums on a phone would answer with Forums off-screen.
 *
 * Sets `scrollLeft` on the strip itself rather than calling `scrollIntoView`,
 * which also scrolls every ancestor — the page included — and the strip is
 * only ever meant to move on its own axis. The strip must be the `offsetParent`
 * of its items (`relative`) for the arithmetic to hold.
 */
export default class extends Controller {
    static targets = ["current"]

    connect() {
        this.center()

        // Not a Stimulus `data-action`: those register passive listeners for
        // wheel events, and a passive listener cannot preventDefault.
        this.onWheel = this.wheel.bind(this)
        this.element.addEventListener("wheel", this.onWheel, { passive: false })
    }

    disconnect() {
        this.element.removeEventListener("wheel", this.onWheel)
    }

    // The strip has no visible scrollbar, and a mouse wheel only ever reports
    // vertical movement, so without this a desktop user on a narrow window has
    // no way to reach the tabs past the edge (touch and a trackpad's sideways
    // swipe already scroll it natively). A sideways delta is left alone, and so
    // is a wheel that has run out of strip in its direction, so the page can
    // still scroll once the strip is at its end.
    wheel(event) {
        if (Math.abs(event.deltaY) <= Math.abs(event.deltaX)) {
            return
        }

        const max = this.element.scrollWidth - this.element.clientWidth
        const next = this.element.scrollLeft + event.deltaY

        if (max <= 0 || (event.deltaY < 0 && this.element.scrollLeft <= 0) || (event.deltaY > 0 && this.element.scrollLeft >= max)) {
            return
        }

        event.preventDefault()
        this.element.scrollLeft = Math.max(0, Math.min(max, next))
    }

    // Layout may not be final when connect() runs (the strip can arrive inside
    // a frame that is still being laid out), so the same call is repeated once
    // the next frame has painted.
    currentTargetConnected() {
        requestAnimationFrame(() => this.center())
    }

    center() {
        if (!this.hasCurrentTarget) {
            return
        }

        const item = this.currentTarget
        const overflow = this.element.scrollWidth - this.element.clientWidth

        if (overflow <= 0) {
            return
        }

        const target = item.offsetLeft - (this.element.clientWidth - item.offsetWidth) / 2

        this.element.scrollLeft = Math.max(0, Math.min(overflow, target))
    }
}
