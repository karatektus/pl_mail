// assets/controllers/compose_controller.js
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['ccField', 'bccField', 'body', 'saveStatus', 'toCollection', 'collapsible', 'minimizeIcon', 'expandIcon', 'ccBtn', 'bccBtn', 'title', 'accountSelect', 'fromBtn', 'fromLabel', 'fromChevron', 'fromDropdown', 'fromRow'];
    static values = {
        draftUrl: String,
        sendUrl: String,
        autosaveDelay: { type: Number, default: 2000 },
        minimized:    { type: Boolean, default: false },
        expanded:     { type: Boolean, default: false },
    }

    #autosaveTimer = null

    connect() {
        const input = this.element.querySelector('.compose-to[data-prototype]');
        this._ensureEntry(input);
        this._submitting = false;
        this._boundHandleSubmit = this._handleSubmit.bind(this);
        this._boundAutosave = this._scheduleAutosave.bind(this);


        const form = this.element.querySelector('form');

        form.action = this.sendUrlValue;
        form.addEventListener('submit', this._boundHandleSubmit);
        form.addEventListener('input', this._boundAutosave);

        // Mirror subject into header title
        const subjectInput = this.element.querySelector('[name$="[subject]"]');
        if (null !== subjectInput) {
            this._updateTitle(subjectInput.value);
            subjectInput.addEventListener('input', () => this._updateTitle(subjectInput.value));
        }

        // Close from-dropdown when clicking outside
        this._boundCloseDropdown = this._closeFromDropdown.bind(this);

        // Auto-expand on mobile
        if (window.innerWidth < 768) {
            this.expandedValue = true;
        }

        if (this.hasBodyTarget) {
            this._collapseQuotedContent();
            this._focusCursorAtTop();
        }
    }

    disconnect() {
        clearTimeout(this.#autosaveTimer);
        const form = this.element.querySelector('form');
            form.removeEventListener('input', this._boundAutosave);
            form.removeEventListener('submit', this._boundHandleSubmit);
        document.removeEventListener('click', this._boundCloseDropdown, { capture: true });
        document.body.style.overflow = '';
    }

    // ── Quoted content ────────────────────────────────────────────────

    /**
     * Wraps every top-level blockquote and forwarded-message div in a
     * collapsible toggle so the compose window doesn't grow unbounded.
     */
    _collapseQuotedContent() {
        const editor = this.bodyTarget;

        const quoted = Array.from(editor.querySelectorAll(
            ':scope > blockquote, :scope > div[style*="border-top"]',
        ));

        if (quoted.length === 0) {
            return;
        }

        quoted.forEach((node) => {
            if (node.dataset.quoteWrapped) {
                return;
            }

            const wrapper = document.createElement('div');
            wrapper.dataset.quoteWrapped = '1';
            wrapper.style.cssText = 'margin-top: 0.5em;';

            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.contentEditable = 'false';
            toggle.textContent = '··· (show quoted text)';
            toggle.style.cssText = [
                'display: inline-block',
                'margin-bottom: 0.4em',
                'padding: 0.1em 0.6em',
                'font-size: 0.75em',
                'border: 1px solid #d1d5db',
                'border-radius: 9999px',
                'background: transparent',
                'color: #6b7280',
                'cursor: pointer',
                'user-select: none',
            ].join(';');

            // Prevent clicks on the toggle from moving the editor cursor.
            toggle.addEventListener('mousedown', (e) => {
                e.preventDefault();
                e.stopPropagation();
            });

            toggle.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const isHidden = node.style.display === 'none';
                node.style.display = isHidden ? '' : 'none';
                toggle.textContent = isHidden
                    ? '··· (hide quoted text)'
                    : '··· (show quoted text)';
            });

            node.parentNode.insertBefore(wrapper, node);
            wrapper.appendChild(toggle);
            wrapper.appendChild(node);

            // Start collapsed.
            node.style.display = 'none';
        });
    }

    /**
     * Places the cursor at the very start of the editor (before any
     * quoted content) and scrolls the editor to the top.
     */
    _focusCursorAtTop() {
        const editor = this.bodyTarget;
        editor.focus();

        const firstNode = this._firstEditableNode(editor);
        if (firstNode === null) {
            return;
        }

        try {
            const range = document.createRange();
            const sel   = window.getSelection();
            range.setStart(firstNode, 0);
            range.collapse(true);
            sel.removeAllRanges();
            sel.addRange(range);
        } catch (_) {
            // Silently ignore — editor is still focused.
        }

        editor.scrollTop = 0;
    }

    /** Returns the first child node of `editor` that is not a quote wrapper. */
    _firstEditableNode(editor) {
        for (const child of editor.childNodes) {
            if (child.dataset && child.dataset.quoteWrapped) {
                continue;
            }

            if (child.nodeType === Node.TEXT_NODE) {
                return child;
            }

            if (child.nodeType === Node.ELEMENT_NODE) {
                const inner = child.firstChild;
                if (inner !== null) {
                    return inner;
                }

                return child;
            }
        }

        return editor.firstChild;
    }

    // ── Minimize ──────────────────────────────────────────────────────

    toggleMinimize() {
        // Can't minimize while expanded — collapse first
        if (this.expandedValue) {
            this.expandedValue = false;
            return;
        }
        this.minimizedValue = !this.minimizedValue;
    }

    minimizedValueChanged() {
        const minimized = this.minimizedValue;

        if (this.hasCollapsibleTarget) {
            this.collapsibleTarget.style.display = minimized ? 'none' : '';
        }

        if (this.hasMinimizeIconTarget) {
            this.minimizeIconTarget.className = minimized
                ? 'fa-solid fa-chevron-up text-xs'
                : 'fa-solid fa-minus text-xs';
        }

        this.element.classList.toggle('rounded-b-2xl', minimized);
    }

    // ── Expand / fullscreen ───────────────────────────────────────────

    toggleExpand() {
        this.expandedValue = !this.expandedValue;
    }

    expandedValueChanged() {
        const expanded = this.expandedValue;
        const el = this.element;

        if (expanded) {
            this.minimizedValue = false;

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
            `;

            if (this.hasBodyTarget) {
                this.bodyTarget.closest('div').style.flex = '1';
                this.bodyTarget.style.flex = '1';
                this.bodyTarget.style.height = '0';
            }

            document.body.style.overflow = 'hidden';

        } else {
            el.style.cssText = '';

            if (this.hasBodyTarget) {
                this.bodyTarget.closest('div').style.flex = '';
                this.bodyTarget.style.flex = '';
                this.bodyTarget.style.height = '';
            }

            document.body.style.overflow = '';
        }

        if (this.hasExpandIconTarget) {
            this.expandIconTarget.className = expanded
                ? 'fa-solid fa-down-left-and-up-right-to-center text-[10px]'
                : 'fa-solid fa-up-right-and-down-left-from-center text-[10px]';
        }
    }

    // ── From dropdown ─────────────────────────────────────────────────

    toggleFromDropdown() {
        const open = !this.fromDropdownTarget.classList.contains('hidden');
        if (open) {
            this._closeFromDropdown();
        } else {
            this.fromDropdownTarget.classList.remove('hidden');
            this.fromChevronTarget.style.transform = 'rotate(180deg)';
            document.addEventListener('click', this._boundCloseDropdown, { capture: true, once: true });
        }
    }

    selectAccount(event) {
        const btn   = event.currentTarget;
        const value = btn.dataset.value;
        const label = btn.dataset.label;

        this.accountSelectTarget.value = value;
        this.fromLabelTarget.textContent = label;

        this.fromDropdownTarget.querySelectorAll('button').forEach(b => {
            const selected = b.dataset.value === value;
            b.classList.toggle('bg-blue-50', selected);
            b.classList.toggle('dark:bg-blue-500/10', selected);
            b.classList.toggle('text-blue-600', selected);
            b.classList.toggle('dark:text-blue-300', selected);
        });

        this._closeFromDropdown();
    }

    _closeFromDropdown() {
        if (this.hasFromDropdownTarget) {
            this.fromDropdownTarget.classList.add('hidden');
        }
        if (this.hasFromChevronTarget) {
            this.fromChevronTarget.style.transform = '';
        }
    }

    // ── Close ─────────────────────────────────────────────────────────

    close() {
        document.body.style.overflow = '';
        this.element.closest('turbo-frame').innerHTML = '';
    }

    // ── Save draft ────────────────────────────────────────────────────

    _scheduleAutosave() {
        clearTimeout(this.#autosaveTimer);
        this.#autosaveTimer = setTimeout(
            () => this.saveDraft(),
            this.autosaveDelayValue,
        );
    }

    async saveDraft(event = null) {
        event?.preventDefault();

        const form = this.element.querySelector('form');
        if (!form) { return; }

        const url    = this.hasDraftUrlValue ? this.draftUrlValue : form.action;
        const status = this.hasSaveStatusTarget ? this.saveStatusTarget : null;

        if (status) {
            status.textContent = 'Saving…';
            status.classList.remove('text-red-500', 'text-green-600');
            status.classList.add('text-gray-400');
        }

        try {
            const response = await fetch(url, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
            });

            if (true === response.ok) {
                if (status) {
                    const html = await response.text();
                    const doc  = new DOMParser().parseFromString(html, 'text/html');
                    const newController = doc.querySelector('[data-compose-draft-url-value]');
                    const oldForm = this.element.querySelector('form');

                    if (newController) {
                        this.draftUrlValue = newController.dataset.composeDraftUrlValue;
                        this.sendUrlValue  = newController.dataset.composeSendUrlValue;
                    }

                    if (oldForm) {
                        oldForm.action = this.sendUrlValue;
                    }

                    status.textContent = 'Draft saved';
                    status.classList.remove('text-gray-400', 'text-red-500');
                    status.classList.add('text-green-600');
                }
            } else {
                throw new Error('Server error');
            }
        } catch (_) {
            if (status) {
                status.textContent = 'Failed to save';
                status.classList.remove('text-gray-400', 'text-green-600');
                status.classList.add('text-red-500');
            }
        }
    }

    _handleSubmit(event) {
        if (true === this._submitting) {
            event.preventDefault();
            return;
        }
        clearTimeout(this.#autosaveTimer);
        this._submitting = true;

        const sendBtn = this.element.querySelector('[type="submit"]');
        if (sendBtn) {
            sendBtn.disabled = true;
            sendBtn.textContent = 'Sending…';
        }

        setTimeout(() => {
            this._submitting = false;
            if (sendBtn) {
                sendBtn.disabled = false;
                sendBtn.textContent = 'Send';
            }
        }, 15_000);
    }

    // ── Cc / Bcc ──────────────────────────────────────────────────────

    showCc() {
        this.ccFieldTarget.classList.remove('hidden');
        this.ccFieldTarget.classList.add('flex');
        if (this.hasCcBtnTarget) { this.ccBtnTarget.classList.add('hidden'); }
        this._ensureEntry(this.ccFieldTarget.querySelector('[data-prototype]'));
    }

    showBcc() {
        this.bccFieldTarget.classList.remove('hidden');
        this.bccFieldTarget.classList.add('flex');
        if (this.hasBccBtnTarget) { this.bccBtnTarget.classList.add('hidden'); }
        this._ensureEntry(this.bccFieldTarget.querySelector('[data-prototype]'));
    }

    // ── Helpers ───────────────────────────────────────────────────────

    _updateTitle(value) {
        if (this.hasTitleTarget) {
            this.titleTarget.textContent = value.trim() || 'New Message';
        }
    }

    _ensureEntry(collection) {
        if (!collection || collection.children.length > 0) { return; }

        const index     = collection.dataset.index ?? 0;
        const prototype = collection.dataset.prototype
            .replace(/__cc__|__bcc__|__to__/g, index);

        collection.dataset.index = parseInt(index) + 1;
        collection.insertAdjacentHTML('beforeend', prototype);
        collection.querySelector('input')?.focus();
    }
}
