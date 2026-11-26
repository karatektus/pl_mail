/* stimulusFetch: 'lazy' */
import { Controller } from "@hotwired/stimulus"

/**
 * The icon picker on Settings → Unified inboxes (#265).
 *
 * The set is a native popover — the `popover` attribute, opened by its button's
 * `popovertarget` — because the settings card clips its contents
 * (`overflow: hidden`, for its rounded corners), and a dropdown positioned
 * inside it was cut off at the card's edge. A popover is drawn in the top
 * layer, above every clip, and closes on Escape or a click outside without any
 * code here.
 *
 * What this adds is the answer to a pick: the button shows the chosen glyph
 * and the popover closes. The radio buttons are part of the row's form either
 * way, so the choice is saved with the name, by the row's Save — and without
 * this controller it still is, only less visibly.
 *
 * Targets:
 *   glyph  — the <i> in the button
 *   panel  — the popover
 */
export default class extends Controller {
    static targets = ["glyph", "panel"]

    pick(event) {
        const value = event.target.value

        if (this.hasGlyphTarget && value) {
            this.glyphTarget.className = this.glyphTarget.className.replace(/\bfa-(?!solid\b)[a-z0-9-]+/g, "").trim() + ` fa-${value}`
        }

        this.panelTarget.hidePopover?.()
    }
}
