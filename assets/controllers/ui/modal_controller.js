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
/**
 * The frame's own markup — the spinner — as it is before anything is loaded
 * into it.
 *
 * Module-level rather than per-controller because every trigger button in the
 * page mounts one of these, and they all drive the single body-level frame.
 * Re-captured on any page render that still has a pristine frame, so a locale
 * change does not leave a spinner labelled in the old language behind.
 */
let pristineFrame = null

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

        // Whether a backdrop dismissal is in progress — see backdropMousedown.
        this.armed = false

        const frame = this._frame

        if (frame?.querySelector("[data-ui--modal-loading]")) {
            pristineFrame = frame.innerHTML
        }
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

        // A dismissal half-completed against the previous dialog must not carry
        // into this one.
        this.armed = false

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

        // Clear the frame so the next open always fetches fresh — and put the
        // spinner back, because clearing only the src left the CLOSED dialog's
        // form sitting in the frame. The next open then showed the previous
        // dialog until its own fetch landed, and anything typed into that
        // window was thrown away when the real form replaced it: an edit form
        // opened straight after a create one is pre-filled and looks live, so
        // the typing goes somewhere convincing and then silently does not
        // save.
        if (frame) {
            frame.src = ""

            if (pristineFrame !== null) frame.innerHTML = pristineFrame
        }

        // Announced on document, not on this.element: whoever cares about a
        // dialog closing is rarely inside the dialog. The onboarding wizard
        // treats a close as "finished", since it only ever opens by itself once.
        this.dispatch("closed", { target: document })
    }

    /**
     * Arms a backdrop dismissal, and only when the gesture STARTS on the
     * backdrop.
     *
     * A click fires on the nearest common ancestor of where the button went
     * down and where it came up, so selecting text in a field and releasing
     * past the edge of the dialog produces a click whose target is the
     * backdrop — indistinguishable, to a click handler, from a click on the
     * backdrop. That is how a modal disappeared mid-sentence and took what had
     * been typed with it: the user had done nothing but drag over their own
     * text.
     *
     * mousedown is the half of the gesture that says what was meant. Nothing
     * here closes anything; it only records that the press began on the
     * backdrop, and backdropClick() closes only if it did.
     */
    backdropMousedown(event) {
        this.armed = event.target === event.currentTarget
    }

    /**
     * The other end of the same gesture: a press that began on the backdrop but
     * was released over the panel is a drag INTO the dialog, not a dismissal.
     * The click that follows still reports the backdrop — it is the common
     * ancestor of the two — so without this the release point would go unread.
     */
    backdropMouseup(event) {
        if (event.target !== event.currentTarget) this.armed = false
    }

    backdropClick(event) {
        const armed = this.armed

        // Disarmed unconditionally, including on the closing path: a stale
        // `true` would let the *next* gesture close the dialog without ever
        // having touched the backdrop.
        this.armed = false

        // Both halves, not either: the press has to have landed on the
        // backdrop itself rather than on the panel, and so does the release.
        if (armed && event.target === event.currentTarget) this.close(event)
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
