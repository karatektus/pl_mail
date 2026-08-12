// assets/controllers/compose_controller.js
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['toField', 'ccField', 'bccField', 'subject', 'body', 'saveStatus', 'toCollection', 'collapsible', 'minimizeIcon', 'expandIcon', 'ccBtn', 'bccBtn', 'title', 'accountSelect', 'fromBtn', 'fromLabel', 'fromChevron', 'fromDropdown', 'fromRow', 'fields', 'fieldsChevron', 'fileInput', 'imageInput', 'attachments', 'scroller', 'formatBar', 'formatToggle', 'sendBtn', 'errors'];

    /** Below this the dock window is the whole screen — matches Tailwind's md. */
    static MOBILE_QUERY = '(max-width: 767px)';
    /**
     * An address the window will accept as a chip.
     *
     * Deliberately the same shape as the `createFilter` ContactAutocompleteField
     * hands Tom Select, because they are two enforcements of one rule and a
     * window that chipped what the field would refuse — or the other way round
     * — is worse than either rule alone.
     */
    static ADDRESS = /^[^@\s]+@[^@\s]+\.[^@\s]+$/;

    static values = {
        // Every user-facing string the controller writes, translated
        // server-side. Defaults are English so a window rendered without the
        // attribute still says something, but the template always supplies it.
        i18n: { type: Object, default: {} },
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
        // Mirrors ComposeController::MAX_ATTACHMENT_BYTES so an oversized file
        // is refused here rather than after the whole upload.
        maxAttachmentBytes: { type: Number, default: 25 * 1024 * 1024 },
    }

    #autosaveTimer = null

    connect() {
        const input = this.element.querySelector('.compose-to[data-prototype]');
        this._ensureEntry(input);
        this._submitting = false;
        this._boundHandleSubmit = this._handleSubmit.bind(this);
        this._boundAutosave = this._scheduleAutosave.bind(this);
        this._boundHandleTab = this._handleTab.bind(this);

        this._boundHandleEnter = this._handleEnter.bind(this);

        // Bubbles up from the Tom Select textboxes, so it runs after Tom
        // Select's own key handler has had its say — see _handleTab().
        this.element.addEventListener('keydown', this._boundHandleTab);
        this.element.addEventListener('keydown', this._boundHandleEnter);

        // The integration picker lives in the modal frame, which is outside
        // this element entirely, so it reports back on window rather than
        // through the DOM tree.
        this._boundIntegrationAttached = this._handleIntegrationAttached.bind(this);
        window.addEventListener('plmail:integration-attached', this._boundIntegrationAttached);

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

        // Fullscreen on a phone — both the dock and an inline reply.
        this._mq = window.matchMedia(this.constructor.MOBILE_QUERY);
        this._boundBreakpoint = this._applyMobile.bind(this);
        this._boundViewport   = this._trackViewport.bind(this);
        this._mq.addEventListener('change', this._boundBreakpoint);
        this._applyMobile();

        if (this.hasBodyTarget) {
            this._collapseQuotedContent();
            this._focusCursorAtTop();

            // Pasted and dropped images become real parts instead of data:
            // URIs — see _handleBodyPaste(). Listeners rather than
            // data-action because both have to be able to preventDefault
            // before the browser inserts its own version.
            this._boundBodyPaste    = this._handleBodyPaste.bind(this);
            this._boundBodyDrop     = this._handleBodyDrop.bind(this);
            this._boundBodyDragOver = this._allowBodyDrop.bind(this);

            this.bodyTarget.addEventListener('paste',    this._boundBodyPaste);
            this.bodyTarget.addEventListener('drop',     this._boundBodyDrop);
            this.bodyTarget.addEventListener('dragover', this._boundBodyDragOver);
        }

        // Tom Select is built by the autocomplete bundle, which may connect
        // after this controller does — hence both the immediate pass and the
        // event.
        this._boundNameAddressFields = this._nameAddressFields.bind(this);
        this.element.addEventListener('autocomplete:connect', this._boundNameAddressFields);
        requestAnimationFrame(this._boundNameAddressFields);

        // Inline: the thread's reply buttons step aside while we're open.
        // Cached — by the time disconnect() runs the card is already detached
        // and closest() would find nothing.
        this._zone = this._replyZone();
        this._zone?.classList.add('composing');
    }

    /**
     * Give each recipient combobox the name its label already carries.
     *
     * Tom Select replaces the <select> with a text input of its own making,
     * and an accessible name does not follow across that swap on its own — so
     * the To field reported as a bare "combobox" with nothing to say what it
     * was for. The <label for> in the template is the source of truth; this
     * copies it onto whatever Tom Select actually built.
     */
    _nameAddressFields() {
        for (const row of this._addressRows()) {
            const label = row.querySelector('label');
            const input = row.querySelector('.ts-control input');

            if (null === label || null === input) {
                continue;
            }

            input.setAttribute('aria-label', label.textContent.trim());
        }
    }

    disconnect() {
        clearTimeout(this.#autosaveTimer);
        this.element.removeEventListener('keydown', this._boundHandleTab);
        this.element.removeEventListener('keydown', this._boundHandleEnter);
        this.element.removeEventListener('autocomplete:connect', this._boundNameAddressFields);

        if (this.hasBodyTarget && undefined !== this._boundBodyPaste) {
            this.bodyTarget.removeEventListener('paste',    this._boundBodyPaste);
            this.bodyTarget.removeEventListener('drop',     this._boundBodyDrop);
            this.bodyTarget.removeEventListener('dragover', this._boundBodyDragOver);
        }

        window.removeEventListener('plmail:integration-attached', this._boundIntegrationAttached);
        const form = this.element.querySelector('form');
            form.removeEventListener('input', this._boundAutosave);
            form.removeEventListener('submit', this._boundHandleSubmit);
        document.removeEventListener('click', this._boundCloseDropdown, { capture: true });
        this._mq?.removeEventListener('change', this._boundBreakpoint);
        this._unwatchViewport();
        document.body.style.overflow = '';
        this._zone?.classList.remove('composing');
    }

    // ── Fullscreen on a phone ─────────────────────────────────────────

    /**
     * True while this window owns the whole screen.
     *
     * Inline replies included: on a phone there is no room to wedge a compose
     * card into a thread, so replying gets the same fullscreen window as
     * composing. Above md an inline card stays an inline card.
     */
    _isMobile() {
        return true === (this._mq?.matches ?? false);
    }

    /**
     * Enter or leave fullscreen. Called on connect and again whenever the
     * breakpoint flips, so a rotation lands in the right mode rather than
     * keeping the layout it was opened in.
     *
     * The layout itself is CSS (see the root element's `md:` classes); what
     * has to happen here is everything CSS cannot express — dropping the dock
     * chrome's state, locking the page behind, folding the formatting bar
     * away, and tracking the virtual keyboard.
     */
    _applyMobile() {
        const mobile = this._isMobile();

        if (mobile === this._mobileApplied) {
            return;
        }

        this._mobileApplied = mobile;

        if (true === mobile) {
            // Minimize and expand are dock concepts, and expand leaves inline
            // styles behind that would fight the fullscreen rules.
            this.minimizedValue = false;
            this.expandedValue  = false;
            this.element.style.cssText = '';
            this._resetExpandedBody();

            document.body.style.overflow = 'hidden';
            this._setFormatBar(false);
            this._watchViewport();

            return;
        }

        this._unwatchViewport();
        // Every property _trackViewport sets, or the desktop window keeps a
        // phone's width the next time the viewport crosses the breakpoint.
        this.element.style.width     = '';
        this.element.style.height    = '';
        this.element.style.transform = '';
        document.body.style.overflow = '';
        this._setFormatBar(true);
    }

    _watchViewport() {
        // Both events fire: `resize` when the keyboard opens or closes,
        // `scroll` when the page is panned while it is open.
        window.visualViewport?.addEventListener('resize', this._boundViewport);
        window.visualViewport?.addEventListener('scroll', this._boundViewport);
        this._trackViewport();
    }

    _unwatchViewport() {
        window.visualViewport?.removeEventListener('resize', this._boundViewport);
        window.visualViewport?.removeEventListener('scroll', this._boundViewport);
    }

    /**
     * Size and place the window against the *visual* viewport, on both axes.
     *
     * The virtual keyboard shrinks that but not the layout viewport a fixed
     * element is measured against, so a `100dvh` window keeps its bottom rows
     * — the action bar, the send button — underneath the keyboard, which is
     * exactly the thing that made composing on a phone unusable. Taking the
     * size from `visualViewport` instead puts the keyboard *below* the window.
     *
     * Width and offsetLeft matter as much as height: with the keyboard up the
     * browser can pan and scale the visual viewport, and a window that only
     * tracked the vertical axis left a strip of the page showing down the right
     * edge as well as above the keyboard.
     *
     * Rounded up, because these are fractional CSS pixels. Flooring — or
     * leaving them fractional — is what turns a rounding error into a visible
     * hairline of whatever is behind the window.
     */
    _trackViewport() {
        const viewport = window.visualViewport;

        if (null === viewport || undefined === viewport) {
            return;
        }

        this.element.style.width     = `${Math.ceil(viewport.width)}px`;
        this.element.style.height    = `${Math.ceil(viewport.height)}px`;
        this.element.style.transform = `translate(${viewport.offsetLeft}px, ${viewport.offsetTop}px)`;

        // Half the screen just became keyboard: put the caret back on screen.
        requestAnimationFrame(() => this._revealCaret());
    }

    /** Scroll the caret back into the visible part of the body scroller. */
    _revealCaret() {
        if (false === this.hasScrollerTarget) {
            return;
        }

        const selection = window.getSelection();

        if (null === selection || 0 === selection.rangeCount) {
            return;
        }

        if (false === this.scrollerTarget.contains(selection.anchorNode)) {
            return;
        }

        const caret = selection.getRangeAt(0).getBoundingClientRect();
        const box   = this.scrollerTarget.getBoundingClientRect();

        // A range with no box at all (some browsers, empty editor) tells us
        // nothing — leave the scroll where it is.
        if (0 === caret.top && 0 === caret.bottom) {
            return;
        }

        if (caret.bottom > box.bottom) {
            this.scrollerTarget.scrollTop += caret.bottom - box.bottom + 24;
        } else if (caret.top < box.top) {
            this.scrollerTarget.scrollTop -= box.top - caret.top + 24;
        }
    }

    // ── Formatting bar ────────────────────────────────────────────────

    /**
     * The rich-text bar is one more row competing with the keyboard, so on a
     * phone it stays folded until asked for — the "Aa" button, same as Gmail.
     */
    toggleFormatBar() {
        if (false === this.hasFormatBarTarget) {
            return;
        }

        this._setFormatBar('none' === this.formatBarTarget.style.display);
    }

    _setFormatBar(open) {
        if (false === this.hasFormatBarTarget) {
            return;
        }

        // '' rather than a display value: the element's own responsive
        // classes decide whether it is a wrapping row or a scroller.
        this.formatBarTarget.style.display = true === open ? '' : 'none';

        if (true === this.hasFormatToggleTarget) {
            this.formatToggleTarget.classList.toggle('bg-hover', open);
            this.formatToggleTarget.classList.toggle('text-ink', open);
            this.formatToggleTarget.setAttribute('aria-pressed', true === open ? 'true' : 'false');
        }

        if (true === open) {
            this._revealCaret();
        }
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

    // ── Keyboard ──────────────────────────────────────────────────────

    /**
     * Tab out of an address field.
     *
     * With its dropdown open Tom Select consumes the keystroke itself
     * (selectOnTab: commit the highlighted suggestion, or the typed address)
     * and calls preventDefault — nothing left to do here. Closed, the browser
     * would move focus to the little Cc/Bcc buttons that follow the To row in
     * the DOM, which is never where you want to go next: send it to the next
     * visible address row, or to the subject.
     */
    _handleTab(event) {
        if ('Tab' !== event.key || true === event.shiftKey || true === event.defaultPrevented) {
            return;
        }

        const wrapper = event.target.closest?.('.ts-wrapper');

        if (null === wrapper || undefined === wrapper) {
            return;
        }

        if (true === this._focusAfterAddressRow(wrapper)) {
            event.preventDefault();
        }
    }

    /**
     * Enter must never be a send.
     *
     * This is the accidental-send bug, and the mechanism is plain implicit form
     * submission. A single-line <input> inside a <form> submits that form on
     * Enter unless something calls preventDefault, and this form's action is
     * the send URL — so any Enter the address field did not consume sent the
     * message, with whatever was (or was not) typed.
     *
     * Tom Select consumes the key only when it has a use for it: an active
     * suggestion to commit, or a createItem() that succeeds. Type something
     * with no `@` — it matches no contact, and createFilter refuses to make a
     * chip of it — and neither applies, so the keystroke fell through to the
     * browser and the mail went out to nobody with no subject and no body.
     * That is why every existing test missed it: they all type real addresses,
     * which Tom Select always swallows.
     *
     * So: Enter is claimed here unconditionally (bar the explicit Ctrl/Cmd
     * shortcut), and *then* asked what it should have meant. In an address
     * field that is "commit a chip", and a value that cannot become one is
     * reported rather than silently dropped.
     *
     * Runs on the container so Tom Select's own handler goes first;
     * defaultPrevented is how we know it already dealt with the key.
     */
    _handleEnter(event) {
        if ('Enter' !== event.key || true === event.isComposing) {
            return;
        }

        // The one deliberate keyboard send, Gmail's shortcut. Explicit, so it
        // is not the footgun the bare key was.
        if (true === event.ctrlKey || true === event.metaKey) {
            event.preventDefault();
            this._requestSend();

            return;
        }

        // Tom Select committed a chip, or opened its menu — either way it has
        // already stopped this reaching the form.
        if (true === event.defaultPrevented) {
            return;
        }

        const target = event.target;

        // The body is a contenteditable, where Enter is a newline and there is
        // no implicit submission to guard against.
        if (true === target?.isContentEditable) {
            return;
        }

        if (false === (target instanceof HTMLInputElement)) {
            return;
        }

        // Nothing below this line may fall through to the browser.
        event.preventDefault();

        // An address row, specifically — not merely a Tom Select. The typeface
        // and size pickers are Tom Selects too now, and a filter string typed
        // into one of those is not an address anybody failed to enter.
        const row = this._addressRows().find((candidate) => candidate.contains(target));

        if (undefined === row) {
            // Subject, and anything else single-line: Enter moves on rather
            // than sending. Gmail does the same.
            this._focusBody();

            return;
        }

        this._commitTypedAddress(target.closest('.ts-wrapper'), target);
    }

    /**
     * Turn what is typed in an address field into a chip, or say why not.
     *
     * Tom Select is asked to do it rather than being worked around: createItem
     * applies the same createFilter the field is configured with, so the answer
     * here and the answer to a blur or a Tab are the same answer.
     */
    _commitTypedAddress(wrapper, input) {
        const typed = input.value.trim();

        if ('' === typed || null === wrapper) {
            return;
        }

        const select = this._tomSelectFor(wrapper);

        if (false === this.constructor.ADDRESS.test(typed)) {
            this._reportError(this._t('invalidAddress', '"%s" is not a valid email address').replace('%s', typed));

            return;
        }

        this._clearError();

        if (null === select) {
            return;
        }

        select.createItem(typed, false);
        select.setTextboxValue('');
        select.focus();
    }

    /** The Tom Select instance behind a rendered `.ts-wrapper`. */
    _tomSelectFor(wrapper) {
        // The bundle hangs the instance off the original <select>, which Tom
        // Select leaves in the DOM (hidden) next to the wrapper it built.
        const select = wrapper.parentElement?.querySelector('select');

        return select?.tomselect ?? null;
    }

    /** The To/Cc/Bcc rows, in tab order. */
    _addressRows() {
        return [
            this.hasToFieldTarget ? this.toFieldTarget : null,
            this.hasCcFieldTarget ? this.ccFieldTarget : null,
            this.hasBccFieldTarget ? this.bccFieldTarget : null,
        ].filter((row) => null !== row);
    }

    /** Focus the first visible address row after `wrapper`, else the subject. */
    _focusAfterAddressRow(wrapper) {
        const rows  = this._addressRows();
        const index = rows.findIndex((row) => row.contains(wrapper));

        if (-1 === index) {
            return false;
        }

        for (const row of rows.slice(index + 1)) {
            if (true === row.classList.contains('hidden')) {
                continue;
            }

            const input = row.querySelector('.ts-control input');

            if (null !== input) {
                input.focus();

                return true;
            }
        }

        if (false === this.hasSubjectTarget) {
            return false;
        }

        this.subjectTarget.focus();

        return true;
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
            toggle.textContent = `··· (${this._t('quoteShow', 'show quoted text')})`;
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
                    ? `··· (${this._t('quoteHide', 'hide quoted text')})`
                    : `··· (${this._t('quoteShow', 'show quoted text')})`;
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

        // Fullscreen is fixed to the viewport — there is nothing to scroll to.
        if (true === this.inlineValue && false === this._isMobile()) {
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

        // The address rows and the editor share one scroll region, so the
        // thing to rewind is that region, not the editor.
        if (this.hasScrollerTarget) {
            this.scrollerTarget.scrollTop = 0;
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
        // Fullscreen has nothing to minimize into — the dock is not on screen.
        if (true === this._isMobile()) {
            return;
        }

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

        // Must track the header's rounded-t-pane: both follow the theme radius,
        // so a fixed value here disagrees at every setting but the default.
        this.element.classList.toggle('rounded-b-pane', minimized);
    }

    // ── Expand / fullscreen ───────────────────────────────────────────

    toggleExpand() {
        if (true === this._isMobile()) {
            return;
        }

        this.expandedValue = !this.expandedValue;
    }

    expandedValueChanged() {
        // Mobile is already fullscreen and sizes itself from the visual
        // viewport; the inline styles below would fight that.
        if (true === this.inlineValue || true === this._isMobile()) {
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
            this._resetExpandedBody();

            document.body.style.overflow = '';
        }

        this._syncExpandIcon(expanded);
    }

    /** Undo the inline sizing the expanded dock puts on the editor. */
    _resetExpandedBody() {
        if (false === this.hasBodyTarget) {
            return;
        }

        this.bodyTarget.closest('div').style.flex = '';
        this.bodyTarget.style.flex   = '';
        this.bodyTarget.style.height = '';
    }

    _syncExpandIcon(expanded) {
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
        if (null !== id && true === await this._restoreDraftRow(frame, id)) {
            return;
        }

        // Reply box: a draft the autosave created has no row yet — add one,
        // otherwise it only turns up after a reload. Keyed on the thread
        // rather than on `inline`, because a reply below md is a dock window
        // that still belongs to the conversation behind it.
        if (null !== id && true === this.hasThreadValue) {
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

        // Which list is behind the window. The Drafts view shows a row per
        // draft, so a conversation that loses its last one has to lose its row
        // there — and only there.
        const scope = document.getElementById('message-list')?.dataset.syncScope;

        if (undefined !== scope) {
            params.set('scope', scope);
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

        // Put the row back rather than emptying the frame. Popping out moves
        // where the draft is edited; it does not remove the draft from the
        // conversation, and the row is how the conversation shows it.
        //
        // Emptying was invisible until the window was closed: close() restores
        // the row only when its own frame is still compose_draft_*, and by then
        // the editor lives in the dock. So the draft survived, its row did not,
        // and the thread only told the truth again after a reload.
        const frame = this.element.closest('turbo-frame');

        if (false === await this._restoreDraftRow(frame, id)) {
            frame.innerHTML = '';
        }
    }

    /**
     * Swap an in-place editor back for the draft's row.
     *
     * Server-rendered so the snippet reflects what was just saved. Answers
     * false when this is not an in-place editor, or the row or the fetch is not
     * there — the caller then decides what to leave behind.
     */
    async _restoreDraftRow(frame, id) {
        if (false === frame?.id.startsWith('compose_draft_')) {
            return false;
        }

        const row  = frame.closest('[id^="thread_message_"]');
        const html = await this._fetchRow(id);

        if (null === row || null === html) {
            return false;
        }

        row.outerHTML = html;

        return true;
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
     * the body having real content. `allowEmpty` additionally lets an empty
     * draft be created: attaching a file needs a row to hang it off.
     */
    async saveDraft(event = null, { force = false, allowEmpty = false } = {}) {
        event?.preventDefault();

        const form = this.element.querySelector('form');
        if (!form) { return; }

        if (false === force && null === event && false === this._worthSaving()) {
            return;
        }

        // Nothing written and nothing saved yet: closing an untouched reply
        // box must not leave an empty draft behind.
        if (false === allowEmpty && 0 === this._typedLength() && null === this._messageId()) {
            return;
        }

        const url    = this.hasDraftUrlValue ? this.draftUrlValue : form.action;
        const status = this.hasSaveStatusTarget ? this.saveStatusTarget : null;
        this._setStatus(this._t('saving', 'Saving…'), 'text-ink-faint');

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
                    const newController = doc.querySelector('[data-compose--compose-draft-url-value]');
                    const oldForm = this.element.querySelector('form');

                    if (newController) {
                        this.draftUrlValue = newController.getAttribute('data-compose--compose-draft-url-value');
                        this.sendUrlValue  = newController.getAttribute('data-compose--compose-send-url-value');
                    }

                    if (oldForm) {
                        oldForm.action = this.sendUrlValue;
                    }

                    this._setStatus(this._t('saved', 'Draft saved'), 'text-success');
                }
            } else {
                throw new Error('Server error');
            }
        } catch (_) {
            this._setStatus(this._t('saveFailed', 'Could not save the draft'), 'text-danger');
        }
    }

    /** The status line next to the window controls. */
    _setStatus(text, className) {
        if (false === this.hasSaveStatusTarget) {
            return;
        }

        const status = this.saveStatusTarget;

        status.textContent = text;
        status.classList.remove('text-ink-faint', 'text-danger', 'text-success');
        status.classList.add(className);
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
            : this._pendingText(missing);
    }

    /**
     * The countdown line, in the right plural form.
     *
     * Two keys rather than one plural string: the catalogue's pluralisation is
     * Symfony's, evaluated server-side, and there is no count to evaluate it
     * against until the user is typing.
     */
    _pendingText(missing) {
        return 1 === missing
            ? this._t('pendingOne', 'one more character to save')
            : this._t('pendingMany', '%d more characters to save').replace('%d', String(missing));
    }

    /** A translated string, falling back to English if the value is absent. */
    _t(key, fallback) {
        return this.i18nValue?.[key] ?? fallback;
    }

    /** Show a refusal next to the address rows. */
    _reportError(text) {
        if (false === this.hasErrorsTarget) {
            this._setStatus(text, 'text-danger');

            return;
        }

        this.errorsTarget.classList.remove('hidden');
        this.errorsTarget.querySelector('p').textContent = text;
    }

    _clearError() {
        if (true === this.hasErrorsTarget) {
            this.errorsTarget.classList.add('hidden');
            this.errorsTarget.querySelector('p').textContent = '';
        }
    }

    /** Move the caret into the body — where Enter on a header row leads. */
    _focusBody() {
        if (true === this.hasBodyTarget) {
            this.bodyTarget.focus();
        }
    }

    /**
     * The committed recipients across To, Cc and Bcc.
     *
     * Counted per address row rather than by sweeping the window for
     * `.ts-control .item`: the typeface and size pickers are Tom Selects too
     * now, and each renders its *selected option* as an `.item`. Swept, an
     * empty To read as two recipients and the send guard waved it through.
     */
    _recipientCount() {
        return this._addressRows()
            .reduce((total, row) => total + row.querySelectorAll('.ts-control .item').length, 0);
    }

    /** Ask the form to submit, going through every guard in _handleSubmit. */
    _requestSend() {
        this.element.querySelector('form')?.requestSubmit();
    }

    /**
     * The last gate before a message leaves.
     *
     * Everything here is a question the user would rather be asked than have
     * answered for them — and the first one is not a question at all, because
     * a send with no recipient cannot be what anyone meant. The server refuses
     * the same three things (ComposeType's `send` group, and
     * ComposeController::send); this is only the half that can still be
     * friendly about it.
     */
    _guardSend() {
        if (0 === this._recipientCount()) {
            this._reportError(this._t('recipientRequired', 'Add at least one recipient before sending.'));

            return false;
        }

        this._clearError();

        const subject = this.hasSubjectTarget ? this.subjectTarget.value.trim() : '';

        if ('' === subject && false === window.confirm(this._t('confirmNoSubject', 'This message has no subject. Send it anyway?'))) {
            return false;
        }

        if (0 === this._typedLength() && false === window.confirm(this._t('confirmNoBody', 'This message has no text. Send it anyway?'))) {
            return false;
        }

        return true;
    }

    _handleSubmit(event) {
        if (true === this._submitting) {
            event.preventDefault();
            return;
        }

        if (false === this._guardSend()) {
            event.preventDefault();

            return;
        }

        clearTimeout(this.#autosaveTimer);
        this._submitting = true;

        // Two of them below md: the header's icon button and the pill in the
        // action bar, only one of which is on screen at a time.
        const sendButtons = this.hasSendBtnTarget
            ? this.sendBtnTargets
            : Array.from(this.element.querySelectorAll('[type="submit"]'));

        sendButtons.forEach((button) => {
            button.disabled = true;
            // The icon button has no text to replace — keep its markup.
            if ('' !== button.textContent.trim()) {
                button.dataset.sendLabel ??= button.textContent;
                button.textContent = this._t('sending', 'Sending…');
            }
        });

        setTimeout(() => {
            this._submitting = false;

            sendButtons.forEach((button) => {
                button.disabled = false;

                if (undefined !== button.dataset.sendLabel) {
                    button.textContent = button.dataset.sendLabel;
                }
            });
        }, 15_000);
    }

    // ── Attachments ───────────────────────────────────────────────────

    openFilePicker() {
        if (true === this.hasFileInputTarget) {
            this.fileInputTarget.click();
        }
    }

    /**
     * Open a connected service's file picker.
     *
     * The draft is force-saved first for the same reason uploadFiles() does
     * it: attachments are rows hanging off a Message, so one has to exist
     * before the picker can add to it. Saving here rather than inside the
     * picker keeps the id in the URL correct from the moment the modal opens.
     */
    async openIntegrationPicker(event) {
        const button = event.currentTarget;
        const integrationId = button.dataset.integrationId;

        if (undefined === integrationId) {
            return;
        }

        await this.saveDraft(null, { force: true, allowEmpty: true });

        const id = this._messageId();

        if (null === id) {
            this._setStatus(this._t('attachFailed', 'Could not attach'), 'text-danger');

            return;
        }

        // The modal controller takes its URL from the trigger's own values, and
        // the draft id is only known once the save above has run — so the
        // button carries data-controller="ui--modal" and gets pointed at the right
        // URL here, immediately before being opened.
        button.setAttribute('data-ui--modal-src-value', `/integrations/${integrationId}/browse?draft=${id}`);

        this.application
            .getControllerForElementAndIdentifier(button, 'ui--modal')
            ?.open();
    }

    /**
     * Result of an integration pick: the re-rendered attachment strip, plus
     * any share links to drop into the body.
     *
     * Every open compose window hears this, so windows that are not the one
     * that opened the picker have to ignore it — the picker names the draft it
     * acted on and that is the only window allowed to act.
     */
    _handleIntegrationAttached(event) {
        const { attachmentsHtml, links, draftId } = event.detail ?? {};

        if (undefined !== draftId && String(draftId) !== String(this._messageId())) {
            return;
        }

        if (undefined !== attachmentsHtml && true === this.hasAttachmentsTarget) {
            this.attachmentsTarget.innerHTML = attachmentsHtml;
        }

        if (0 < (links ?? []).length) {
            this._insertLinks(links);
        }

        this._setStatus(this._t('saved', 'Draft saved'), 'text-success');

        // The links went into the body directly, so the form has not fired an
        // input event — without this the draft would keep the pre-link body
        // until the next keystroke.
        this.saveDraft(null, { force: true, allowEmpty: true });
    }

    /** Append share links as anchors at the top of the user's own writing. */
    _insertLinks(links) {
        if (false === this.hasBodyTarget) {
            return;
        }

        const block = document.createElement('div');

        links.forEach(({ name, url }) => {
            const line = document.createElement('div');
            const anchor = document.createElement('a');

            anchor.href = url;
            anchor.textContent = name;
            anchor.rel = 'noopener noreferrer';

            line.append(anchor);
            block.append(line);
        });

        // Before any quoted original, so the links sit with the reply rather
        // than being buried under the thread history.
        this.bodyTarget.prepend(block);
    }

    /**
     * Attachments are rows hanging off the draft, so an unsaved window has to
     * become one first — hence the forced save before the upload.
     */
    async uploadFiles(event) {
        const input = event.currentTarget;
        const files = Array.from(input.files ?? []);

        if (0 === files.length) {
            return;
        }

        // Clearing it early keeps the picked files out of every later autosave
        // (the input sits inside the form) and lets the same file be picked
        // again after a removal.
        input.value = '';

        const tooBig = files.find((file) => file.size > this.maxAttachmentBytesValue);

        if (undefined !== tooBig) {
            const limit = Math.round(this.maxAttachmentBytesValue / (1024 * 1024));
            this._setStatus(
                this._t('tooLarge', '%s is over %d MB')
                    .replace('%s', tooBig.name)
                    .replace('%d', String(limit)),
                'text-danger',
            );

            return;
        }

        await this.saveDraft(null, { force: true, allowEmpty: true });

        const id = this._messageId();

        if (null === id) {
            this._setStatus(this._t('attachFailed', 'Could not attach'), 'text-danger');

            return;
        }

        const body = new FormData();
        files.forEach((file) => body.append('files[]', file));

        this._setStatus(this._t('uploading', 'Uploading…'), 'text-ink-faint');

        await this._renderAttachments(
            fetch(`/compose/attachments/${id}`, {
                method: 'POST',
                body,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            }),
            this._t('attachFailed', 'Could not attach'),
        );
    }

    async removeAttachment(event) {
        const partId = event.currentTarget.dataset.partId;

        if (undefined === partId) {
            return;
        }

        await this._renderAttachments(
            fetch(`/compose/attachment/${partId}/remove`, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            }),
            this._t('attachRemoveFailed', 'Could not remove attachment'),
        );
    }

    /** Swap in the server-rendered attachment strip, or report why not. */
    async _renderAttachments(request, failureMessage) {
        let response;

        try {
            response = await request;
        } catch (_) {
            this._setStatus(failureMessage, 'text-danger');

            return;
        }

        if (false === response.ok) {
            this._setStatus(await this._errorText(response, failureMessage), 'text-danger');

            return;
        }

        if (true === this.hasAttachmentsTarget) {
            this.attachmentsTarget.innerHTML = await response.text();
        }

        this._setStatus(this._t('saved', 'Draft saved'), 'text-success');
    }

    /**
     * The server states why an upload was refused in plain text. Anything else
     * — an HTML error page, a proxy cutting the request off — falls back to the
     * generic message rather than dumping markup into the status line.
     */
    async _errorText(response, fallback) {
        if (false === (response.headers.get('Content-Type') ?? '').startsWith('text/plain')) {
            return fallback;
        }

        const detail = (await response.text()).trim();

        return '' === detail ? fallback : detail;
    }

    // ── Inline images ─────────────────────────────────────────────────

    openImagePicker() {
        if (true === this.hasImageInputTarget) {
            this.imageInputTarget.click();
        }
    }

    /** The hidden image input's own change event. */
    async uploadInlineImage(event) {
        const input = event.currentTarget;
        const files = Array.from(input.files ?? []);

        // Cleared early for the same reason uploadFiles() does it: the input
        // sits inside the form, and a file left on it rides along on every
        // later autosave.
        input.value = '';

        for (const file of files) {
            await this._placeImage(file);
        }
    }

    /**
     * An image pasted into the body.
     *
     * Without this the browser drops a data: URI into the contenteditable, and
     * every autosave from then on posts the whole picture back as base64 text
     * inside bodyHtml — which is how a screenshot turns a 2 KB draft into a
     * 4 MB one that no mail server will take.
     */
    _handleBodyPaste(event) {
        const files = Array.from(event.clipboardData?.files ?? [])
            .filter((file) => file.type.startsWith('image/'));

        if (0 === files.length) {
            return;
        }

        // Only the images are ours; text pasted alongside them still goes
        // through the browser's own handling.
        event.preventDefault();

        this._placeImages(files);
    }

    /** Same again for a file dragged onto the body. */
    _handleBodyDrop(event) {
        const files = Array.from(event.dataTransfer?.files ?? [])
            .filter((file) => file.type.startsWith('image/'));

        if (0 === files.length) {
            return;
        }

        event.preventDefault();

        // The caret follows the pointer on a drop, and the toolbar's saved
        // range is what the insertion uses — so record where this landed
        // before the upload's await gives focus away.
        this._toolbar()?._saveRange();

        this._placeImages(files);
    }

    /** Dropping onto a contenteditable is refused unless dragover says yes. */
    _allowBodyDrop(event) {
        if (Array.from(event.dataTransfer?.items ?? []).some((item) => 'file' === item.kind)) {
            event.preventDefault();
        }
    }

    async _placeImages(files) {
        for (const file of files) {
            await this._placeImage(file);
        }
    }

    /**
     * Upload one image and put it where the cursor is.
     *
     * The forced save is the same rule attachments follow: a part is a row
     * hanging off a Message, so the draft has to exist before there is
     * anything to hang it off.
     */
    async _placeImage(file) {
        if (file.size > this.maxAttachmentBytesValue) {
            const limit = Math.round(this.maxAttachmentBytesValue / (1024 * 1024));

            this._setStatus(
                this._t('tooLarge', '%s is over %d MB')
                    .replace('%s', file.name)
                    .replace('%d', String(limit)),
                'text-danger',
            );

            return;
        }

        await this.saveDraft(null, { force: true, allowEmpty: true });

        const id = this._messageId();

        if (null === id) {
            this._setStatus(this._t('imageFailed', 'Could not insert the image'), 'text-danger');

            return;
        }

        const body = new FormData();
        body.append('file', file);

        this._setStatus(this._t('uploading', 'Uploading…'), 'text-ink-faint');

        const failure = this._t('imageFailed', 'Could not insert the image');
        let payload = null;

        try {
            const response = await fetch(`/compose/inline-image/${id}`, {
                method: 'POST',
                body,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
            });

            // Anything that is not our JSON — a proxy's error page, a request
            // PHP discarded whole for exceeding post_max_size — is a failure
            // we cannot explain, and a parser's complaint is not an
            // explanation to show anybody.
            payload = await response.json().catch(() => null);

            if (false === response.ok || 'string' !== typeof payload?.url) {
                this._setStatus(payload?.error ?? failure, 'text-danger');

                return;
            }
        } catch (_) {
            this._setStatus(failure, 'text-danger');

            return;
        }

        this._toolbar()?.insertInlineImage(payload);

        // Saved straight away rather than left to the debounced autosave. The
        // part row already exists; if the body that references it does not, a
        // window closed in the next two seconds leaves a draft with an orphan
        // image in it and no picture.
        await this.saveDraft(null, { force: true, allowEmpty: true });
    }

    /**
     * The toolbar controller on this same window — it owns the editor's saved
     * selection, so every caret operation goes through it rather than being
     * written twice.
     */
    _toolbar() {
        return this.application.getControllerForElementAndIdentifier(
            this.element,
            'compose--compose-toolbar',
        );
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
            this.titleTarget.textContent = value.trim() || this._t('newMessage', 'New Message');
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
