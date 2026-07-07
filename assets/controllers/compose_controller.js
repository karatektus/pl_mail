// assets/controllers/compose_controller.js
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['ccField', 'bccField', 'body', 'saveStatus', 'toCollection', 'collapsible', 'minimizeIcon', 'expandIcon', 'ccBtn', 'bccBtn', 'title', 'accountSelect', 'fromBtn', 'fromLabel', 'fromChevron', 'fromDropdown', 'fromRow'];
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
        const form = this.element.querySelector('form');
        const formAction = this.element.dataset.composeSendUrlValue;
        form?.addEventListener('input', this._boundAutosave);
        form.action = formAction;
        // Mirror subject into header title
        const subjectInput = this.element.querySelector('[name$="[subject]"]')
        if (subjectInput) {
            this._updateTitle(subjectInput.value)
            subjectInput.addEventListener('input', () => this._updateTitle(subjectInput.value))
        }

        // Close from-dropdown when clicking outside
        this._boundCloseDropdown = this._closeFromDropdown.bind(this)

        // Auto-expand on mobile
        if (window.innerWidth < 768) {
            this.expandedValue = true
        }
        console.log(this.accountSelectTarget.value);
    }

    disconnect() {
        clearTimeout(this.#autosaveTimer)
        this.element.querySelector('form')
            ?.removeEventListener('input', this._boundAutosave)
        document.removeEventListener('click', this._boundCloseDropdown, { capture: true })
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
                height: auto;
                margin: 0;
                z-index: 50;
                display: flex;
                flex-direction: column;
            `

            // The body div wrapper needs to flex-grow too
            if (this.hasBodyTarget) {
                this.bodyTarget.closest('div').style.flex = '1'
                this.bodyTarget.style.flex = '1'
                this.bodyTarget.style.height = '0' // 0 basis so flex-1 can take over
            }

            document.body.style.overflow = 'hidden'

        } else {
            el.style.cssText = ''

            if (this.hasBodyTarget) {
                this.bodyTarget.closest('div').style.flex = ''
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

    // ── From dropdown ─────────────────────────────────────────────────

    toggleFromDropdown() {
        const open = !this.fromDropdownTarget.classList.contains('hidden')
        if (open) {
            this._closeFromDropdown()
        } else {
            this.fromDropdownTarget.classList.remove('hidden')
            this.fromChevronTarget.style.transform = 'rotate(180deg)'
            document.addEventListener('click', this._boundCloseDropdown, { capture: true, once: true })
        }
    }

    selectAccount(event) {
        console.log(this.accountSelectTarget.value);
        const btn = event.currentTarget
        const value = btn.dataset.value
        const label = btn.dataset.label

        // Update hidden select
        this.accountSelectTarget.value = value

        // Update button label
        this.fromLabelTarget.textContent = label

        // Highlight selected option
        this.fromDropdownTarget.querySelectorAll('button').forEach(b => {
            const selected = b.dataset.value === value
            b.classList.toggle('bg-blue-50', selected)
            b.classList.toggle('dark:bg-blue-500/10', selected)
            b.classList.toggle('text-blue-600', selected)
            b.classList.toggle('dark:text-blue-300', selected)
        })

        this._closeFromDropdown()
    }

    _closeFromDropdown() {
        if (this.hasFromDropdownTarget) {
            this.fromDropdownTarget.classList.add('hidden')
        }
        if (this.hasFromChevronTarget) {
            this.fromChevronTarget.style.transform = ''
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
                    const html = await response.text()
                    const doc = new DOMParser().parseFromString(html, 'text/html')
                    const newController = doc.querySelector('[data-compose-draft-url-value]')
                    const oldForm = this.element.querySelector('form')

                    if (newController) {
                        this.draftUrlValue = newController.dataset.composeDraftUrlValue
                    }

                    if (oldForm) {
                        oldForm.action = newController.dataset.composeSendUrlValue
                    }

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
        this.ccFieldTarget.classList.add('flex')
        if (this.hasCcBtnTarget) this.ccBtnTarget.classList.add('hidden')
        this._ensureEntry(this.ccFieldTarget.querySelector('[data-prototype]'))
    }

    showBcc() {
        this.bccFieldTarget.classList.remove('hidden')
        this.bccFieldTarget.classList.add('flex')
        if (this.hasBccBtnTarget) this.bccBtnTarget.classList.add('hidden')
        this._ensureEntry(this.bccFieldTarget.querySelector('[data-prototype]'))
    }

    // ── Helpers ───────────────────────────────────────────────────────

    _updateTitle(value) {
        if (this.hasTitleTarget) {
            this.titleTarget.textContent = value.trim() || 'New Message'
        }
    }

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
