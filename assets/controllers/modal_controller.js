import { Controller } from "@hotwired/stimulus"

/**
 * Modal controller
 *
 * Usage on a trigger button:
 *   <button
 *     data-controller="modal"                        ← attach here, or use a parent
 *     data-action="click->modal#open"
 *     data-modal-src-value="/some/form/url"
 *     data-modal-title-value="Edit settings"
 *   >Open</button>
 *
 * Or fire open() directly from Turbo events / other controllers.
 */
export default class extends Controller {
    static values = {
        src:   String,   // URL to load into the turbo-frame
        title: String,   // Optional title shown in the modal header
    }

    connect() {
        this._onSubmitEnd = this._handleSubmitEnd.bind(this);
        this.element.addEventListener("turbo:submit-end", this._onSubmitEnd);
        this._onKeydown = this._handleKeydown.bind(this)
    }

    disconnect() {
        this.element.removeEventListener("turbo:submit-end", this._onSubmitEnd);
        document.removeEventListener("keydown", this._onKeydown)
    }

    // ── Public API ──────────────────────────────────────────────────────────────

    open(event) {
        event?.preventDefault()

        const frame  = this._frame
        const dialog = this._dialog

        if (!frame || !dialog) {
            console.warn("[modal] #modal turbo-frame or [data-modal-dialog] not found in DOM")
            return
        }

        // Allow overriding src/title from the triggering element's data attributes
        const triggerSrc   = event?.currentTarget?.dataset?.modalSrcValue   ?? this.srcValue
        const triggerTitle = event?.currentTarget?.dataset?.modalTitleValue ?? this.titleValue

        // Update the frame title if present
        const titleEl = dialog.querySelector("[data-modal-title]")
        if (titleEl && triggerTitle) titleEl.textContent = triggerTitle

        // Point the turbo-frame at the form URL and let Turbo do the fetch
        frame.src = triggerSrc

        // Show the modal
        dialog.removeAttribute("hidden")
        document.body.classList.add("overflow-hidden")
        document.addEventListener("keydown", this._onKeydown)

        // Move focus into the dialog after Turbo has rendered the frame
        frame.addEventListener("turbo:frame-load", () => this._focusFirst(dialog), { once: true })
    }

    close(event) {
        event?.preventDefault()

        const frame  = this._frame
        const dialog = this._dialog

        if (!dialog) return

        dialog.setAttribute("hidden", "")
        document.body.classList.remove("overflow-hidden")
        document.removeEventListener("keydown", this._onKeydown)

        // Clear the frame so the next open always fetches fresh
        if (frame) frame.src = ""
    }

    backdropClick(event) {
        // Only close when clicking the backdrop itself, not its children
        if (event.target === event.currentTarget) this.close(event)
    }

    // ── Private ─────────────────────────────────────────────────────────────────

    get _frame()  { return document.getElementById("modal") }
    get _dialog() { return document.querySelector("[data-modal-dialog]") }

    _handleKeydown(event) {
        if (event.key === "Escape") this.close(event)
    }

    _focusFirst(container) {
        const el = container.querySelector(
            'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )
        el?.focus()
    }

    _handleSubmitEnd(event) {
        if (event.detail.success) {
            this.close();
        }
    }
}
