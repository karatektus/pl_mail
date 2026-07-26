// assets/controllers/compose_controller.js
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['ccField', 'bccField', 'body', 'saveStatus', 'toCollection', 'collapsible', 'minimizeIcon', 'expandIcon', 'ccBtn', 'bccBtn', 'title', 'accountSelect', 'fromBtn', 'fromLabel', 'fromChevron', 'fromDropdown', 'fromRow', 'fields', 'fieldsChevron'];
    static values = {
        draftUrl: String,
        sendUrl: String,
        autosaveDelay: { type: Number, default: 2000 },
        minimized:    { type: Boolean, default: false },
        expanded:     { type: Boolean, default: false },
        // Rendered inline at the bottom of a thread rather than in the
        // floating dock: no fullscreen, no mobile auto-expand, pop-out button,
        // and the address/subject rows start folded away.
        inline:       { type: Boolean, default: false },
        // Body characters needed before an autosave may create a draft —
        // every stray keystroke used to mint one.
        minChars:     { type: Number, default: 5 },
        // The conversation this window belongs to, for the draft row.
        thread:       Number,
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

        // Auto-expand on mobile — the dock only; an inline card must not
        // take over the viewport or lock the thread's scroll.
        if (false === this.inlineValue && window.innerWidth < 768) {
            this.expandedValue = true;
        }

        if (this.hasBodyTarget) {
            this._collapseQuotedContent();
            this._focusCursorAtTop();
        }

        // Inline: the thread's reply buttons step aside while we're open.
        // Cached — by the time disconnect() runs the card is already detached
        // and closest() would find nothing.
        this._zone = this._replyZone();
        this._zone?.classList.add('composing');
    }

    disconnect() {
        clearTimeout(this.#autosaveTimer);
        const form = this.element.querySelector('form');
            form.removeEventListener('input', this._boundAutosave);
            form.removeEventListener('submit', this._boundHandleSubmit);
        document.removeEventListener('click', this._boundCloseDropdown, { capture: true });
        document.body.style.overflow = '';
        this._zone?.classList.remove('composing');
    }

    /** The thread reply zone this card lives in, when rendered inline. */
    _replyZone() {
        return true === this.inlineValue
            ? this.element.closest('[data-reply-zone]')
            : null;
    }

    /** The message id this window is editing, once the draft exists. */
    _messageId() {
        return this.sendUrlValue.match(/\/send\/(\d+)/)?.[1] ?? null;
    }

    // ── Address / subject fields ──────────────────────────────────────

    /**
     * Inline replies keep From/To/Cc/Bcc/Subject folded away — you rarely
     * retarget a reply — behind the recipient summary in the header.
     */
    toggleFields() {
        if (false === this.hasFieldsTarget) {
            return;
        }

        const hidden = this.fieldsTarget.classList.toggle('hidden');

        if (this.hasFieldsChevronTarget) {
            this.fieldsChevronTarget.classList.toggle('rotate-180', false === hidden);
        }
    }

    // ── Quoted content ────────────────────────────────────────────────

    /**
     * Wraps every top-level blockquote and forwarded-message div in a
     * collapsible toggle so the compose window doesn't grow unbounded.
     */
    _collapseQuotedContent() {
        const editor = this.bodyTarget;

        // [data-quoted] is what buildQuotedHtml emits today; the other two
        // match drafts saved before that marker existed.
        const quoted = Array.from(editor.querySelectorAll(
            ':scope > [data-quoted], :scope > blockquote, :scope > div[style*="border-top"]',
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
            toggle.dataset.quoteToggle = '1';
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

        // preventScroll: inside the thread pane, a plain focus() yanks the
        // conversation to the top the moment the card mounts.
        editor.focus({ preventScroll: true });

        if (true === this.inlineValue) {
            this.element.scrollIntoView({ block: 'nearest' });
        }

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
        if (true === this.inlineValue) {
            return;
        }

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
            b.classList.toggle('bg-accent-soft', selected);
            b.classList.toggle('text-accent', selected);
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

    async close() {
        document.body.style.overflow = '';

        const frame = this.element.closest('turbo-frame');
        const id    = this._messageId();

        // In-place draft: the frame IS the row, so put the row markup back
        // rather than leaving a hole in the conversation. Rendered server
        // side so the snippet reflects what was just saved.
        if (null !== id && frame?.id.startsWith('compose_draft_')) {
            const row = frame.closest('[id^="thread_message_"]');
            const html = await this._fetchRow(id);

            if (null !== row && null !== html) {
                row.outerHTML = html;

                return;
            }
        }

        // Reply box: a draft the autosave created has no row yet — add one,
        // otherwise it only turns up after a reload.
        if (null !== id && true === this.inlineValue) {
            this._insertDraftRow(id);
        }

        frame.innerHTML = '';
    }

    /**
     * Really delete the draft — the trash button used to just close the
     * window and leave it behind.
     */
    async discard() {
        const id = this._messageId();

        if (null === id) {
            this.close();

            return;
        }

        clearTimeout(this.#autosaveTimer);

        const frame  = this.element.closest('turbo-frame');
        const params = new URLSearchParams({ frame: frame?.id ?? 'compose_dock' });

        if (this.hasThreadValue) {
            params.set('thread', String(this.threadValue));
        }

        const response = await fetch(`/compose/discard/${id}?${params}`, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (false === response.ok) {
            console.error('[compose] discard failed', response.status);

            return;
        }

        Turbo.renderStreamMessage(await response.text());
    }

    _rowUrl(id) {
        const params = this.hasThreadValue ? `?thread=${this.threadValue}` : '';

        return `/compose/draft-row/${id}${params}`;
    }

    async _fetchRow(id) {
        const response = await fetch(this._rowUrl(id), {
            headers: { 'X-Requested-With': 'fetch' },
        });

        return true === response.ok ? await response.text() : null;
    }

    /** Append the row for a freshly created draft to the open conversation. */
    async _insertDraftRow(id) {
        const list = this.hasThreadValue
            ? document.getElementById(`thread_messages_${this.threadValue}`)
            : null;

        if (null === list || null !== document.getElementById(`thread_message_${id}`)) {
            return;
        }

        const html = await this._fetchRow(id);

        if (null !== html) {
            list.insertAdjacentHTML('beforeend', html);
        }
    }

    /**
     * Move the inline draft into the floating dock. The draft has to be saved
     * first so the dock can load it by id — saveDraft() rewrites sendUrlValue
     * to /compose/send/{id}, which is where the id comes from.
     */
    async popOut() {
        await this.saveDraft(null, { force: true });

        const id = this.sendUrlValue.match(/\/send\/(\d+)/)?.[1];
        const dock = document.querySelector('turbo-frame#compose_dock');

        if (undefined === id || null === dock) {
            return;
        }

        dock.src = `/compose/edit/${id}`;
        this.element.closest('turbo-frame').innerHTML = '';
    }

    // ── Save draft ────────────────────────────────────────────────────

    _scheduleAutosave() {
        clearTimeout(this.#autosaveTimer);

        if (false === this._worthSaving()) {
            this._reportPending();

            return;
        }

        if (this.hasSaveStatusTarget && this.saveStatusTarget.textContent.includes('to save')) {
            this.saveStatusTarget.textContent = '';
        }

        this.#autosaveTimer = setTimeout(
            () => this.saveDraft(),
            this.autosaveDelayValue,
        );
    }

    /**
     * `force` covers the deliberate saves — the close button (which passes its
     * click event) and pop-out — so only the debounced autosave is gated on
     * the body having real content.
     */
    async saveDraft(event = null, { force = false } = {}) {
        event?.preventDefault();

        const form = this.element.querySelector('form');
        if (!form) { return; }

        if (false === force && null === event && false === this._worthSaving()) {
            return;
        }

        // Nothing written and nothing saved yet: closing an untouched reply
        // box must not leave an empty draft behind.
        if (0 === this._typedLength() && null === this._messageId()) {
            return;
        }

        const url    = this.hasDraftUrlValue ? this.draftUrlValue : form.action;
        const status = this.hasSaveStatusTarget ? this.saveStatusTarget : null;
        const STATUS_CLASSES = ['text-ink-faint', 'text-danger', 'text-success'];
        if (status) {
            status.textContent = 'Saving…';
            status.classList.remove(...STATUS_CLASSES);
            status.classList.add('text-ink-faint');
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
                    status.classList.remove(...STATUS_CLASSES);
                    status.classList.add('text-success');
                }
            } else {
                throw new Error('Server error');
            }
        } catch (_) {
            if (status) {
                status.textContent = 'Failed to save';
                status.classList.remove(...STATUS_CLASSES);
                status.classList.add('text-danger');
            }
        }
    }

    /**
     * Typed body length, ignoring the quoted original — replying to a mail
     * always starts with a screenful of quote that isn't the user's writing.
     */
    _worthSaving() {
        if (false === this.hasBodyTarget) {
            return true;
        }

        return this._typedLength() >= this.minCharsValue;
    }

    /** Characters the user has actually written, quote excluded. */
    _typedLength() {
        if (false === this.hasBodyTarget) {
            return 0;
        }

        const clone = this.bodyTarget.cloneNode(true);

        // The last two selectors cover drafts written before buildQuotedHtml
        // marked the quote with data-quoted.
        clone.querySelectorAll(
            '[data-quote-wrapped], [data-quoted], blockquote, div[style*="border-top"], div[style*="font-size:0.85em"]',
        ).forEach((node) => node.remove());

        return clone.textContent.trim().length;
    }

    /**
     * Below the threshold the status line counts down instead of going quiet,
     * so it is obvious the draft is not being kept yet.
     */
    _reportPending() {
        if (false === this.hasSaveStatusTarget) {
            return;
        }

        const missing = this.minCharsValue - this._typedLength();
        const status  = this.saveStatusTarget;

        status.classList.remove('text-danger', 'text-success');
        status.classList.add('text-ink-faint');
        status.textContent = missing <= 0
            ? ''
            : `${missing} more character${1 === missing ? '' : 's'} to save`;
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
        if (this.hasCcBtnTarget) {
            this.ccBtnTarget.classList.add('hidden');
        }
    }

    showBcc() {
        this.bccFieldTarget.classList.remove('hidden');
        if (this.hasBccBtnTarget) {
            this.bccBtnTarget.classList.add('hidden');
        }
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
