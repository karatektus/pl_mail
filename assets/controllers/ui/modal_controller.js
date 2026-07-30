import { Controller } from "@hotwired/stimulus"

/**
 * Modal controller
 *
 * Usage on a trigger button:
 *   <button
 *     data-controller="ui--modal"                        ← attach here, or use a parent
 *     data-action="click->ui--modal#open"
 *     data-ui--modal-src-value="/some/form/url"
 *     data-ui--modal-title-value="Edit settings"
 *   >Open</button>
 *
 * Or fire open() directly from Turbo events / other controllers.
 */
export default class extends Controller {
    static values = {
        src:   String,   // URL to load into the turbo-frame
        title: String,   // Optional title shown in the modal header
        size:  String,   // Optional width preset — see SIZES
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
            console.warn("[modal] #modal turbo-frame or [data-ui--modal-dialog] not found in DOM")
            return
        }

        // Allow overriding src/title from the triggering element's data attributes
        const triggerSrc   = event?.currentTarget?.dataset?.modalSrcValue   ?? this.srcValue
        const triggerTitle = event?.currentTarget?.dataset?.modalTitleValue ?? this.titleValue

        // Update the frame title if present
        const titleEl = dialog.querySelector("[data-ui--modal-title]")
        if (titleEl && triggerTitle) titleEl.textContent = triggerTitle

        this._applySize(
            dialog,
            event?.currentTarget?.dataset?.modalSizeValue ?? this.sizeValue,
        )

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
    get _dialog() { return document.querySelector("[data-ui--modal-dialog]") }

    _handleKeydown(event) {
        if (event.key === "Escape") this.close(event)
    }

    _focusFirst(container) {
        const el = container.querySelector(
            'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )
        el?.focus()
    }

    /**
     * Swap the panel's width class.
     *
     * Presets come from the panel's own data-ui--modal-sizes so the class names live
     * in the template, where Tailwind is guaranteed to see them — a width
     * referenced only from here could be purged from the build.
     *
     * Every preset is removed before the chosen one is added: the shell is
     * reused for every dialog, so a wide picker would otherwise leave the next
     * label form stretched across the screen.
     */
    _applySize(dialog, size) {
        const panel = dialog.querySelector("[data-ui--modal-panel]")

        if (!panel) {
            return
        }

        let sizes

        try {
            sizes = JSON.parse(panel.getAttribute("data-ui--modal-sizes") ?? "{}")
        } catch (_) {
            return
        }

        const chosen = sizes[size] ?? sizes.form

        if (undefined === chosen) {
            return
        }

        panel.classList.remove(...Object.values(sizes))
        panel.classList.add(chosen)
    }

    _handleSubmitEnd(event) {
        // Some forms inside a modal are navigation, not completion — the
        // integration picker's search box re-renders the frame and must stay
        // open. Closing on every successful submit made searching look like a
        // cancel.
        if (event.target?.closest?.("[data-ui--modal-keep-open]")) {
            return;
        }

        if (event.detail.success) {
            this.close();
        }
    }
}
