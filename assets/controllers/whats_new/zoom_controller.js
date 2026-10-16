import { Controller } from "@hotwired/stimulus"
import { openLayer, closeLayer } from "../../history_layer.js"

/**
 * What's new → zooming a highlight's screenshot to fill the dialog (#179).
 *
 * The thumbnail used to be a link with `target="_blank"`, so looking at a
 * picture properly meant a new tab with nothing but a PNG in it, and finding
 * the way back to plMail. Now the picture grows out of its thumbnail until it
 * fills the What's new dialog, and the X, Escape or Back shrink it into place
 * again.
 *
 * The link keeps its href and its `_blank`: without the script it does what it
 * always did, and open() is what takes the click away from it.
 *
 * Inside the dialog rather than over the whole page: the picture stays where
 * the notes are read, and the modal's own header, and its X, stay where they
 * are. (The first version was a <dialog> in the top layer, over everything.)
 *
 * ## Escape and Tab belong to the picture while it is up
 *
 * The shared modal listens for keydown on document — Escape closes it, Tab is
 * trapped in it — so a keydown that reached it would close What's new under
 * the picture, or walk the keyboard onto the notes hidden behind. Ours is on
 * window in the capture phase, which runs before anything on document, and
 * stops both keys there. Tab has only one place to go, the X.
 *
 * ## Back
 *
 * Through history_layer.js, which says why it has to be the first thing
 * app.js loads. The X, Escape, the modal closing around the picture and the
 * frame being drawn again under it all close the layer too, so its history
 * entry never outlives the picture.
 */
let current = null

window.addEventListener("keydown", (event) => {
    if (null === current) return

    if ("Escape" === event.key) {
        event.preventDefault()
        event.stopImmediatePropagation()
        current.close()
    } else if ("Tab" === event.key) {
        event.preventDefault()
        event.stopImmediatePropagation()
        current.closeTarget.focus()
    }
}, true)

export default class extends Controller {
    static targets = ["overlay", "backdrop", "image", "close"]

    connect() {
        // The modal's own X, with the picture still up.
        this._onModalClosed = () => {
            if (this !== current) return

            current = null
            this._reset()
            closeLayer()
        }

        document.addEventListener("ui--modal:closed", this._onModalClosed)
    }

    disconnect() {
        document.removeEventListener("ui--modal:closed", this._onModalClosed)

        // Replaced while open: a release picked a moment before the thumbnail
        // was clicked lands after it, and the frame is drawn again under the
        // picture. Its history entry goes with it, or the next Back would be
        // spent on nothing.
        if (this === current) {
            current = null
            closeLayer()
        }
    }

    async open(event) {
        // Clicked with a modifier, or the middle button: a new tab is what was
        // asked for, and the href still knows how to give it.
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || 0 !== event.button) {
            return
        }

        event.preventDefault()

        // Still shrinking from the last time: the end of that animation hides
        // the layer, and would hide this picture with it.
        if (null !== current || this._shrinking) return

        current = this
        this._thumbnail = event.currentTarget

        openLayer(() => this._shrink())

        this.imageTarget.src = this._thumbnail.getAttribute("href")
        this.overlayTarget.hidden = false

        // Measured only once the picture has a size; it is the thumbnail's own
        // file, so this is the cache answering.
        try {
            await this.imageTarget.decode()
        } catch {
            // Drawn without the growth then, which is still the picture.
        }

        if (this !== current) return

        // Opacity rather than visibility: a hidden thumbnail would leave the
        // accessibility tree, and it is where the keyboard goes back to.
        this._thumbnail.style.opacity = "0"
        this.closeTarget.focus()
        this._animate(false)
    }

    /** The X and Escape. */
    close() {
        if (this !== current) return

        closeLayer()
        this._shrink()
    }

    _shrink() {
        if (this === current) current = null

        const thumbnail = this._thumbnail

        this._shrinking = true

        this._animate(true).finally(() => {
            this._shrinking = false
            this._reset()
            thumbnail?.focus()
        })
    }

    _reset() {
        // The shrink holds its last frame (fill: forwards) so the picture does
        // not flash full size before it is hidden; let go of it here, or the
        // next open would start invisible.
        for (const target of [this.imageTarget, this.backdropTarget, this.closeTarget]) {
            target.getAnimations().forEach((animation) => animation.cancel())
        }

        this.overlayTarget.hidden = true
        this.imageTarget.removeAttribute("src")

        if (this._thumbnail) this._thumbnail.style.opacity = ""

        this._thumbnail = null
    }

    /**
     * The picture between its thumbnail and its full size: one transform, from
     * the thumbnail's box to where the layout put it, and the dim behind it
     * with it. Durations are the motion tokens, so reduced motion — which sets
     * them to 0s — gets the picture in place at once.
     */
    _animate(reverse) {
        const image = this.imageTarget
        const thumbnail = this._thumbnail?.querySelector("img")

        if (!thumbnail) return Promise.resolve()

        const from = thumbnail.getBoundingClientRect()
        const to = image.getBoundingClientRect()

        if (0 === to.width || 0 === to.height) return Promise.resolve()

        const style = getComputedStyle(this.element)
        const duration = this._ms(style.getPropertyValue("--motion-base"))
        const easing = style.getPropertyValue("--motion-ease").trim() || "ease"

        if (0 === duration) return Promise.resolve()

        const small = `translate(${from.left - to.left}px, ${from.top - to.top}px) scale(${from.width / to.width}, ${from.height / to.height})`
        const options = { duration, easing, direction: reverse ? "reverse" : "normal", fill: reverse ? "forwards" : "none" }

        image.style.transformOrigin = "top left"

        const grow = image.animate([{ transform: small }, { transform: "none" }], options)
        this.backdropTarget.animate([{ opacity: 0 }, { opacity: 1 }], options)
        this.closeTarget.animate([{ opacity: 0 }, { opacity: 1 }], options)

        return grow.finished.catch(() => {})
    }

    /** "180ms" or "0.18s" → 180; anything else, including "0s", → 0. */
    _ms(value) {
        const number = parseFloat(value)

        if (Number.isNaN(number)) return 0

        return value.trim().endsWith("ms") ? number : number * 1000
    }
}
