// assets/controllers/compose_controller.js
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['ccField', 'bccField', 'body', 'saveStatus', 'toCollection', 'collapsible', 'minimizeIcon', 'expandIcon']
    static values = {
        draftUrl: String,
        autosaveDelay: { type: Number, default: 2000 },
        minimized:    { type: Boolean, default: false },
        expanded:     { type: Boolean, default: false },
    }

    #autosaveTimer = null

    connect() {
        const toCollection = this.element.querySelector('.compose-to[data-prototype]')
        this._ensureEntry(toCollection)

        this._boundAutosave = this._scheduleAutosave.bind(this)
        this.element.querySelector('form')
            ?.addEventListener('input', this._boundAutosave)

        // Auto-expand on mobile
        if (window.innerWidth < 768) {
            this.expandedValue = true
        }
    }

    disconnect() {
        clearTimeout(this.#autosaveTimer)
        this.element.querySelector('form')
            ?.removeEventListener('input', this._boundAutosave)
        // Make sure we restore body scroll if unmounted while expanded
        document.body.style.overflow = ''
    }

    // ── Minimize ──────────────────────────────────────────────────────

    toggleMinimize() {
        // Can't minimize while expanded — collapse first
        if (this.expandedValue) {
            this.expandedValue = false
            return
        }
        this.minimizedValue = !this.minimizedValue
    }

    minimizedValueChanged() {
        const minimized = this.minimizedValue

        if (this.hasCollapsibleTarget) {
            this.collapsibleTarget.style.display = minimized ? 'none' : ''
        }

        if (this.hasMinimizeIconTarget) {
            this.minimizeIconTarget.className = minimized
                ? 'fa-solid fa-chevron-up text-xs'
                : 'fa-solid fa-minus text-xs'
        }

        // Minimized: fully rounded pill. Normal: rounded top only via rounded-2xl + no bottom radius override.
        this.element.classList.toggle('rounded-b-2xl', minimized)
    }

    // ── Expand / fullscreen ───────────────────────────────────────────

    toggleExpand() {
        this.expandedValue = !this.expandedValue
    }

    expandedValueChanged() {
        const expanded = this.expandedValue
        const el = this.element

        if (expanded) {
            this.minimizedValue = false

            el.style.cssText = `
                position: fixed;
                inset: 1rem;
                width: auto;
                max-width: none;
                margin: 0;
                z-index: 50;
            `

            if (this.hasBodyTarget) {
                this.bodyTarget.style.flex = '1'
                this.bodyTarget.style.height = '0'   // flex-1 needs a 0-basis to expand correctly
            }

            document.body.style.overflow = 'hidden'

        } else {
            el.style.cssText = ''

            if (this.hasBodyTarget) {
                this.bodyTarget.style.flex = ''
                this.bodyTarget.style.height = ''
            }

            document.body.style.overflow = ''
        }

        if (this.hasExpandIconTarget) {
            this.expandIconTarget.className = expanded
                ? 'fa-solid fa-down-left-and-up-right-to-center text-[10px]'
                : 'fa-solid fa-up-right-and-down-left-from-center text-[10px]'
        }
    }

    // ── Close ─────────────────────────────────────────────────────────

    close() {
        document.body.style.overflow = ''
        this.element.closest('turbo-frame').innerHTML = ''
    }

    // ── Save draft ────────────────────────────────────────────────────

    _scheduleAutosave() {
        clearTimeout(this.#autosaveTimer)
        this.#autosaveTimer = setTimeout(
            () => this.saveDraft(),
            this.autosaveDelayValue,
        )
    }

    async saveDraft(event = null) {
        event?.preventDefault()

        const form = this.element.querySelector('form')
        if (!form) return

        const url = this.hasDraftUrlValue ? this.draftUrlValue : form.action
        const status = this.hasSaveStatusTarget ? this.saveStatusTarget : null

        if (status) {
            status.textContent = 'Saving…'
            status.classList.remove('text-red-500', 'text-green-600')
            status.classList.add('text-gray-400')
        }

        try {
            const response = await fetch(url, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
            })

            if (response.ok) {
                if (status) {
                    status.textContent = 'Draft saved'
                    status.classList.remove('text-gray-400', 'text-red-500')
                    status.classList.add('text-green-600')
                }
            } else {
                throw new Error('Server error')
            }
        } catch {
            if (status) {
                status.textContent = 'Failed to save'
                status.classList.remove('text-gray-400', 'text-green-600')
                status.classList.add('text-red-500')
            }
        }
    }

    // ── Cc / Bcc ──────────────────────────────────────────────────────

    showCc() {
        this.ccFieldTarget.classList.remove('hidden')
        this._ensureEntry(this.ccFieldTarget.querySelector('[data-prototype]'))
    }

    showBcc() {
        this.bccFieldTarget.classList.remove('hidden')
        this._ensureEntry(this.bccFieldTarget.querySelector('[data-prototype]'))
    }

    // ── Helpers ───────────────────────────────────────────────────────

    _ensureEntry(collection) {
        if (!collection || collection.children.length > 0) return

        const index = collection.dataset.index ?? 0
        const prototype = collection.dataset.prototype
            .replace(/__cc__|__bcc__|__to__/g, index)

        collection.dataset.index = parseInt(index) + 1
        collection.insertAdjacentHTML('beforeend', prototype)
        collection.querySelector('input')?.focus()
    }
}
