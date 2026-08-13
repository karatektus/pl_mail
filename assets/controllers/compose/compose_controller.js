// assets/controllers/compose_controller.js
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['toField', 'ccField', 'bccField', 'subject', 'body', 'saveStatus', 'toCollection', 'collapsible', 'minimizeIcon', 'expandIcon', 'ccBtn', 'bccBtn', 'title', 'accountSelect', 'fromBtn', 'fromLabel', 'fromChevron', 'fromDropdown', 'fromRow', 'fields', 'fieldsChevron', 'fileInput', 'imageInput', 'attachments', 'scroller', 'formatBar', 'formatToggle', 'sendBtn', 'errors', 'plainBody', 'plainToggle', 'plainCheck', 'plainWarning', 'plainWarningConfirm', 'sendWarning', 'sendWarningBody', 'sendWarningConfirm', 'richOnly'];

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
        // The send pill's "send later" target. Kept here beside the other two
        // because the three go stale together — see _adoptSavedUrls().
        scheduleUrl: String,
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
        // Every From option's signature block, keyed by the same
        // "accountId|address" token the hidden account <select> carries — so
        // switching From swaps the signature without asking the server.
        signatures: { type: Object, default: {} },
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

        // A draft that arrives with text and no HTML is already a plain-text
        // message (the template works that out), so the format controls have to
        // start hidden — otherwise a bar of buttons that do nothing sits over a
        // textarea.
        //
        // The same three consequences the switch applies, applied to a window
        // that was rendered already in the mode: the bar folded, the "Aa"
        // toggle gone, the HTML input off the wire — plus the rich-only
        // affordances greyed, which is what makes coming back out of the mode
        // symmetric rather than approximate.
        if (true === this._isPlainText()) {
            this._setFormatBar(false);

            if (this.hasFormatToggleTarget) {
                this.formatToggleTarget.classList.add('hidden');
            }

            this._setRichAffordances(false);

            const hidden = this.element.querySelector('[data-compose--compose-toolbar-target="hiddenInput"]');

            if (hidden) {
                hidden.disabled = true;
            }
        }

        // Leaving with writing that has not reached the server yet.
        //
        // The threshold above closes the common case; this closes the rest of
        // it. Between the last keystroke and the debounced save there is always
        // a window in which the draft on the server is older than the one on
        // screen, and a reload inside that window used to take the difference
        // with it in silence — no prompt, nothing in Drafts.
        //
        // Deliberately the NATIVE dialog, which is the opposite of the choice
        // made for the send confirmations: `beforeunload` is the only hook a
        // page gets before a reload, and the browser will not show anything of
        // ours in its place. The text is the browser's too; a custom string has
        // been ignored by every engine for years.
        this._boundBeforeUnload = (event) => {
            if (false === this._hasUnsavedWriting()) {
                return;
            }

            event.preventDefault();
            event.returnValue = '';
        };

        window.addEventListener('beforeunload', this._boundBeforeUnload);

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

            // The original <select> too. Tom Select leaves it in the DOM under
            // `ts-hidden-accessible` — visually hidden, still in the
            // accessibility tree — and the <label for> in the template stops
            // reaching it once the widget is built, so it reported as an unnamed
            // combobox. The audit found it as the second of two.
            const original = row.querySelector('select.ts-hidden-accessible, select');

            if (null !== original) {
                original.setAttribute('aria-label', label.textContent.trim());
            }

            // The error is about the FIELD, so it has to point at the field.
            // Announced-but-unassociated is how this shipped: the alert was
            // read out and the combobox itself still reported aria-invalid
            // null, so anyone arriving at the input afterwards got no hint that
            // it was the thing being complained about. The calendar form does
            // this properly; this is the compose window catching up.
            input.setAttribute('aria-describedby', this._errorsId());

            this._tameAddressField(this._tomSelectFor(row.querySelector('.ts-wrapper')), input);
        }

        this._markInvalid(this.hasErrorsTarget && false === this.errorsTarget.classList.contains('hidden'));
    }

    /** The id of the shared refusal line, minted once so it can be pointed at. */
    _errorsId() {
        if (false === this.hasErrorsTarget) {
            return '';
        }

        this.errorsTarget.id ||= `${this.element.id || 'compose'}_address_errors`;

        return this.errorsTarget.id;
    }

    /** Flag or unflag every address combobox as the one being complained about. */
    _markInvalid(invalid) {
        for (const row of this._addressRows()) {
            const input = row.querySelector('.ts-control input');

            if (null === input) {
                continue;
            }

            if (true === invalid) {
                input.setAttribute('aria-invalid', 'true');
            } else {
                input.removeAttribute('aria-invalid');
            }
        }
    }

    /**
     * Stop the suggestion panel from taking the window over.
     *
     * Two faults, one cause. Tom Select reopens the dropdown whenever the field
     * takes focus, and after Enter it refreshes it against the query that has
     * just been turned into a chip. So committing an address made the panel
     * spring back — covering the Subject row and the top of the body, and
     * offering the contradictory pair "Add test@…" and "No results found" about
     * an address that was by then already a chip sitting inches away. Escape
     * dismissed it and the next focus brought it back, which left Subject
     * unreachable by mouse.
     *
     * Typing still opens it; that is a different code path (`refreshOptions` on
     * input) and it is the one the feature is actually for. What is switched off
     * is opening a panel nobody asked for.
     */
    _tameAddressField(select, input) {
        if (null === select || true === select.plmailTamed) {
            return;
        }

        select.plmailTamed = true;
        select.settings.openOnFocus = false;

        // Remember what is being typed, because by the time Send is handled it
        // is already gone. Clicking the button blurs the field, and Tom Select
        // empties its textbox on blur when the contents cannot become an item —
        // which is precisely the case worth reporting. Reading `input.value` in
        // the submit handler therefore always found an empty box, so a typed
        // address was thrown away and then reported as "no recipient".
        //
        // Listened for on the INPUT rather than through Tom Select's own `type`
        // event: that event comes out of its key handlers, so a value set any
        // other way never reaches it, and "any other way" includes both a paste
        // and how the tests drive the field.
        input?.addEventListener('input', () => {
            select.plmailTyped = input.value;
        });

        select.on('item_add', () => {
            // The query that produced this chip is spent. Left in place it is
            // what the reopened panel searches for.
            select.setTextboxValue('');
            select.plmailTyped = '';
            select.close();

            // The complaint is over the moment the thing complained about is
            // fixed. It used to sit there indefinitely, pushing the Subject row
            // down, until the next Send happened to clear it.
            this._clearError();
        });

        this._reserveRoomForPanel(select, input);
    }

    /**
     * Keep the suggestion panel from being painted over the Subject row.
     *
     * This is the mis-addressing bug, and it is a layout bug rather than
     * anything to do with blur. `.ts-dropdown` is `position: absolute`, so it
     * takes up no room and hangs over whatever follows — and what follows the
     * To row is Subject, then the body. Measured on this window: the Subject
     * input occupies y=346..366 and the open panel y=331..412. It covers it
     * completely, label and all, so `elementFromPoint` at the middle of the
     * Subject field answers `.option` or `.create`, not the input.
     *
     * Everything the tester saw follows from that one fact. A click aimed at
     * Subject lands on the panel instead:
     *
     *  • on the "Add <typed>" row it commits what was typed and, because
     *    Tom Select keeps focus in the control after a selection, leaves the
     *    caret in the To field — so the subject someone then types goes into
     *    the recipient box, and a second click is needed to escape. That is
     *    the "click-out is swallowed" report.
     *  • on a highlighted suggestion it commits THAT CONTACT — an address
     *    nobody chose, on a message the user believes they are addressing
     *    elsewhere. That is the "adds a recipient nobody chose" report, and
     *    it is the reason this is the priority in the batch.
     *
     * So the panel is given real room instead of being allowed to overhang:
     * while it is open the row grows by its height, Subject and the body move
     * down, and the click always reaches whatever the user can actually see.
     * Nothing here second-guesses which suggestion was "really" meant, because
     * with nothing hidden there is no longer a wrong guess to make.
     *
     * Measured rather than assumed: the panel's height changes as results load
     * and as the create row appears and disappears, so a ResizeObserver keeps
     * the reservation in step. The floating variant (`ui--select`, anchored to
     * <body> for the clipping reasons in tom-select.css) is left alone — it is
     * not in this flow and reserving space for it would move the wrong box.
     */
    _reserveRoomForPanel(select, input) {
        const row = this._addressRows().find((candidate) => candidate.contains(input));

        if (undefined === row) {
            return;
        }

        const panel = select.dropdown;

        if (null === panel || undefined === panel) {
            return;
        }

        const clear = () => {
            this._panelObserver?.disconnect();
            row.style.paddingBottom = '';
        };

        // Collapsing the reservation is itself a layout change, and doing it
        // the instant the panel closes moves the form out from under a click
        // that is still happening: mousedown on Subject closes the panel, the
        // row shrinks by its height, and mouseup lands on whatever slid up into
        // that space — so the gesture completes somewhere the user never
        // pressed. Holding the space until the button is released keeps the
        // page still for the whole of the click, which is the point.
        const release = () => {
            if (false === this._pointerDown) {
                clear();

                return;
            }

            document.addEventListener('pointerup', clear, { once: true });
        };

        const reserve = () => {
            if (true === panel.classList.contains('ts-dropdown-floating')) {
                return;
            }

            // offsetHeight, not getBoundingClientRect: the window animates in,
            // and a transformed rect would reserve a scaled-down height.
            const height = panel.offsetHeight;

            row.style.paddingBottom = 0 === height ? '' : `${height + 8}px`;
        };

        select.on('dropdown_open', () => {
            reserve();

            this._panelObserver ??= new ResizeObserver(reserve);
            this._panelObserver.observe(panel);
        });

        select.on('dropdown_close', release);

        // The window can be torn down with the panel still open — sending from
        // a half-typed address does exactly that — and a stale observer on a
        // detached node would keep the callback alive.
        this._panelReleases ??= [];
        this._panelReleases.push(clear);

        // One pair of listeners for the window, however many address rows it
        // has. Capture, so a handler that stops the event lower down cannot
        // leave `_pointerDown` stuck true and the reservation held open.
        if (undefined === this._boundPointerDown) {
            this._pointerDown      = false;
            this._boundPointerDown = () => { this._pointerDown = true; };
            this._boundPointerUp   = () => { this._pointerDown = false; };

            document.addEventListener('pointerdown', this._boundPointerDown, { capture: true });
            document.addEventListener('pointerup',   this._boundPointerUp,   { capture: true });
        }
    }

    disconnect() {
        clearTimeout(this.#autosaveTimer);

        for (const release of this._panelReleases ?? []) {
            release();
        }

        if (undefined !== this._boundPointerDown) {
            document.removeEventListener('pointerdown', this._boundPointerDown, { capture: true });
            document.removeEventListener('pointerup',   this._boundPointerUp,   { capture: true });
        }
        this.element.removeEventListener('keydown', this._boundHandleTab);
        this.element.removeEventListener('keydown', this._boundHandleEnter);
        this.element.removeEventListener('autocomplete:connect', this._boundNameAddressFields);

        if (this.hasBodyTarget && undefined !== this._boundBodyPaste) {
            this.bodyTarget.removeEventListener('paste',    this._boundBodyPaste);
            this.bodyTarget.removeEventListener('drop',     this._boundBodyDrop);
            this.bodyTarget.removeEventListener('dragover', this._boundBodyDragOver);
        }

        window.removeEventListener('beforeunload', this._boundBeforeUnload);
        window.removeEventListener('plmail:integration-attached', this._boundIntegrationAttached);
        const form = this.element.querySelector('form');
            form.removeEventListener('input', this._boundAutosave);
            form.removeEventListener('submit', this._boundHandleSubmit);
        document.removeEventListener('click', this._boundCloseDropdown, { capture: true });
        this._closePlainWarning();
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
     * chrome's state, locking the page behind, and tracking the virtual
     * keyboard.
     *
     * Folding the formatting bar away used to be on that list too: below md it
     * was closed on arrival and had to be asked for with "Aa". It is open at
     * every width now — it wraps onto a second row instead of scrolling, which
     * is what made it affordable, and a bar you have to know about is a bar
     * most people never find. "Aa" still folds it for whoever wants the room
     * back; crossing the breakpoint restores the default, which is the same
     * thing this function has always done to the rest of the window's state.
     */
    _applyMobile() {
        const mobile = this._isMobile();

        if (mobile === this._mobileApplied) {
            return;
        }

        this._mobileApplied = mobile;

        // Not in plain-text mode, where the bar has nothing to act on. The
        // guard rather than an ordering assumption: connect() calls this
        // BEFORE it reads the rendered mode.
        if (false === this._isPlainText()) {
            this._setFormatBar(true);
        }

        if (true === mobile) {
            // Minimize and expand are dock concepts, and expand leaves inline
            // styles behind that would fight the fullscreen rules.
            this.minimizedValue = false;
            this.expandedValue  = false;
            this.element.style.cssText = '';
            this._resetExpandedBody();

            document.body.style.overflow = 'hidden';
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
     * "Aa" — fold the rich-text bar away, or bring it back.
     *
     * It starts open at every width. The bar wraps onto a second row rather
     * than scrolling, so showing it costs a predictable ~32px rather than
     * hiding half of itself off the side; what "Aa" buys now is room, for
     * whoever wants it, rather than access to controls that were unreachable
     * without it.
     */
    toggleFormatBar() {
        this._setFormatBar(false === this._isFormatBarOpen());
    }

    /**
     * Is the bar showing? Read from the inline style _setFormatBar writes, not
     * from a class or from offsetHeight — a window that has never been touched
     * has no inline display at all, and that is the open state.
     */
    _isFormatBarOpen() {
        return this.hasFormatBarTarget
            && 'none' !== this.formatBarTarget.style.display;
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

        // The signature follows the address, and ONLY the signature: the swap
        // is scoped to the [data-pl-signature] block, so a paragraph already
        // typed above it is untouched. Changing From must never cost someone
        // what they have written.
        this._swapSignature(value);

        this._closeFromDropdown();
    }

    // ── Signature ─────────────────────────────────────────────────────
    //
    // One block, marked with `data-pl-signature`, living in the body above the
    // quote. Everything here is the same operation seen from three angles:
    // there is at most one signature block, and writing a signature means
    // replacing that block's contents or creating it where the caret is.

    /**
     * The signature the current From selection signs with, as HTML, or '' when
     * that address signs with nothing.
     */
    _signatureFor(token) {
        const map = this.hasSignaturesValue ? this.signaturesValue : {};

        return map?.[token] ?? '';
    }

    _currentSignature() {
        return this._signatureFor(
            this.hasAccountSelectTarget ? this.accountSelectTarget.value : '',
        );
    }

    /**
     * A click in the empty space below the content lands in writeable text,
     * never in the signature.
     *
     * The bug this fixes: a new message opens as `<p><br></p>` + the signature
     * block, so the signature is the LAST thing in the editor and everything
     * below it is the editor's own padding. A contenteditable resolves a click
     * on its padding to the nearest position in the nearest child — the end of
     * the signature line — so the first sentence a person wrote came out as
     * "-- SIG-OUTLOOK Paul" with their text welded onto it, no separator, and
     * no way to get above it with the mouse at all.
     *
     * `event.target === bodyTarget` is what makes this precise rather than
     * clever. A click that lands ON any content — the signature text included,
     * which people legitimately edit — has that element as its target and is
     * left completely alone. Only a click on the editor's own box, which is by
     * definition a click on empty space, is redirected.
     *
     * It goes UP, to the writing space above the signature, rather than opening
     * a paragraph below it. Below the sign-off is not where the message goes,
     * and the seeded `<p><br></p>` is already sitting there for exactly this.
     */
    claimWritingSpace(event) {
        if (false === this.hasBodyTarget || event.target !== this.bodyTarget) {
            return;
        }

        const signature = this._signatureBlock();

        if (null === signature) {
            return;
        }

        this._focusWritingSpace(signature);
    }

    /**
     * Put the caret at the end of the last writeable block above `signature`,
     * making one if the body has none left.
     */
    _focusWritingSpace(signature) {
        let space = null;

        for (const child of this.bodyTarget.children) {
            if (child === signature) {
                break;
            }

            // A quote is no more writeable than the signature is: landing at
            // the end of a quoted reply is the same bug wearing a hat.
            if (null === child.closest('[data-quote-wrapped], [data-quoted], blockquote')) {
                space = child;
            }
        }

        if (null === space) {
            space = document.createElement('p');
            space.appendChild(document.createElement('br'));
            this.bodyTarget.insertBefore(space, signature);
        }

        const range = document.createRange();
        range.selectNodeContents(space);
        range.collapse(false);

        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);

        this.bodyTarget.focus();
    }

    /** The signature block currently in the body, if there is one. */
    _signatureBlock() {
        return this.hasBodyTarget
            ? this.bodyTarget.querySelector('[data-pl-signature]')
            : null;
    }

    /**
     * Toolbar button: put the signature in the body.
     *
     * Replaces the block in place when one is already there rather than
     * appending a second — clicking twice is a thing people do, and two
     * sign-offs is not what either click asked for.
     */
    insertSignature() {
        if (false === this.hasBodyTarget) { return; }

        const html = this._currentSignature();

        if ('' === html) {
            this._setStatus(this._t('noSignature', 'No signature set for this address'), 'text-ink-faint');

            return;
        }

        const existing = this._signatureBlock();

        if (existing) {
            existing.outerHTML = html;
            this._afterBodyEdit();

            return;
        }

        // No block yet: build one and hand it to the toolbar, which knows
        // where the caret was before this button stole the focus.
        const wrapper = document.createElement('div');
        wrapper.innerHTML = html;

        const node = wrapper.firstElementChild;

        if (!node) { return; }

        const toolbar = this._toolbar();

        if (toolbar) {
            toolbar._insertAtCaret(node);

            return;
        }

        this.bodyTarget.appendChild(node);
        this._afterBodyEdit();
    }

    /**
     * Swap the signature block for the newly selected identity's, and change
     * nothing else.
     *
     * The fiddly case is a body that has no block at all — a draft written
     * before the sender had a signature, or one the user deleted the block out
     * of. Nothing is inserted then: an absent signature is a decision, and
     * changing From is not the moment to overrule it.
     */
    _swapSignature(token) {
        const existing = this._signatureBlock();

        if (!existing) { return; }

        const html = this._signatureFor(token);

        if ('' === html) {
            existing.remove();
        } else {
            existing.outerHTML = html;
        }

        this._afterBodyEdit();
    }

    /** The toolbar controller sharing this window's element, if it is up. */
    _toolbar() {
        return this.application?.getControllerForElementAndIdentifier(
            this.element,
            'compose--compose-toolbar',
        ) ?? null;
    }

    /**
     * Mirror a scripted body change into the hidden input and wake the
     * autosave. Nothing the DOM is changed by script fires `input` on its own,
     * so without this the change is on screen and gone again on reload.
     */
    _afterBodyEdit() {
        this._toolbar()?._syncHiddenInput();

        if (this.hasBodyTarget) {
            this.bodyTarget.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }

    // ── Plain text mode ───────────────────────────────────────────────

    _isPlainText() {
        return this.hasPlainBodyTarget && false === this.plainBodyTarget.disabled;
    }

    /**
     * Switch the body between the rich editor and a plain textarea.
     *
     * WHAT HAPPENS TO FORMATTING. Going to plain text flattens the HTML, and
     * that is not reversible by re-parsing text — so it is warned about first
     * (a confirm the user can decline) and it is undone by REMEMBERING: the
     * HTML is kept, untouched, in the hidden bodyHtml input and in the editor
     * that is merely hidden, so switching back restores exactly what was there.
     * The cost of that choice is that the round trip is only free until the
     * draft is saved in plain mode — the save stores an empty bodyHtml, and
     * reopening the draft later genuinely has no HTML left to come back to.
     * Which is honest: at that point the message really is a plain-text
     * message, and pretending otherwise would be the lie.
     *
     * The mode itself is not stored anywhere. A message with text and no HTML
     * IS a plain-text message — that is the same pair MessageSendService reads
     * — so the window re-derives the mode on render and needs no column.
     */
    togglePlainText() {
        if (false === this.hasPlainBodyTarget || false === this.hasBodyTarget) {
            return;
        }

        const plain = false === this._isPlainText();

        // Going plain, with formatting that flattening would destroy: ask
        // first. The question is a popover over the editor rather than
        // `window.confirm()` — same rule, same reversibility, but a dialog the
        // app drew rather than one the browser did, which on a phone is a
        // system sheet dropped over a half-written message.
        if (true === plain && true === this._plainTextLosesFormatting()) {
            this._openPlainWarning();

            return;
        }

        this._setPlainText(plain);
    }

    /**
     * Is there formatting here that going plain would throw away?
     *
     * Unchanged from when this guarded the `confirm()`: an empty body, or one
     * that is already nothing but text, converts silently. Lifted out only so
     * that the question and the answer are no longer inside the function that
     * performs the switch.
     */
    _plainTextLosesFormatting() {
        const text = this._bodyAsText(this.bodyTarget);

        return this.bodyTarget.innerHTML.trim() !== text.replace(/\n/g, '')
            && text.trim().length > 0;
    }

    _openPlainWarning() {
        if (false === this.hasPlainWarningTarget) {
            // No popover in this window (the settings signature editor renders
            // the toolbar without one): fall back to switching, which is what
            // declining could only ever postpone.
            this._setPlainText(true);

            return;
        }

        this.plainWarningTarget.hidden = false;

        this._boundPlainWarningOutside ??= (event) => {
            if (false === this.plainWarningTarget.contains(event.target)) {
                this.cancelPlainText();
            }
        };
        this._boundPlainWarningEscape ??= (event) => {
            if ('Escape' === event.key) {
                event.stopPropagation();
                this.cancelPlainText();
            }
        };

        document.addEventListener('click', this._boundPlainWarningOutside, { capture: true });
        document.addEventListener('keydown', this._boundPlainWarningEscape);

        // Focus lands on Continue so the dialog is operable from the keyboard
        // at all; Escape and Cancel are both a decline, which is the safe
        // default this guard exists to protect.
        if (this.hasPlainWarningConfirmTarget) {
            this.plainWarningConfirmTarget.focus();
        }
    }

    _closePlainWarning() {
        if (false === this.hasPlainWarningTarget) {
            return;
        }

        this.plainWarningTarget.hidden = true;

        document.removeEventListener('click', this._boundPlainWarningOutside, { capture: true });
        document.removeEventListener('keydown', this._boundPlainWarningEscape);
    }

    /** "Continue" — the switch the warning was standing in front of. */
    confirmPlainText() {
        this._closePlainWarning();
        this._setPlainText(true);
    }

    /** "Cancel", Escape, or a click outside. The body is left exactly as it was. */
    cancelPlainText() {
        this._closePlainWarning();
    }

    _setPlainText(plain) {
        const textarea = this.plainBodyTarget;
        const editor   = this.bodyTarget;

        if (true === plain) {
            textarea.value = this._bodyAsText(editor);
        } else {
            // Coming back: the editor still holds the HTML it always did, so
            // there is nothing to restore unless the user typed into the
            // textarea in the meantime — in which case that text wins, because
            // it is the newer writing.
            if (textarea.value !== this._bodyAsText(editor)) {
                editor.innerHTML = this._textAsHtml(textarea.value);
            }
        }

        textarea.disabled = false === plain;
        textarea.hidden   = false === plain;
        textarea.classList.toggle('hidden', false === plain);
        editor.classList.toggle('hidden', true === plain);

        // The HTML body has to go on the wire empty for the send path to emit
        // text only — that branch is `if ($message->bodyHtml)` in
        // MessageSendService::buildEmail(). The editor keeps its content; only
        // what is submitted changes.
        const hidden = this.element.querySelector('[data-compose--compose-toolbar-target="hiddenInput"]');

        if (hidden) {
            hidden.disabled = true === plain;
        }

        // Formatting controls have nothing to act on in plain text — so going
        // plain folds the bar, and coming back UNFOLDS it. That second half is
        // what was missing: the switch wrote `display: none` on the way in and
        // nothing on the way out, leaving a window in rich mode with no
        // formatting bar and no obvious way to work out why. ("Aa" would have
        // brought it back, but only for someone who already knew that.)
        //
        // Restored to what it was rather than simply opened: whether the bar is
        // open in rich mode is the user's own choice, and a round trip through
        // plain text must not quietly overrule it. `?? true` covers the window
        // that went plain before anyone touched "Aa" — open is the default.
        if (true === plain) {
            this._formatBarWasOpen = this._isFormatBarOpen();
            this._setFormatBar(false);
        } else {
            this._setFormatBar(this._formatBarWasOpen ?? true);
        }

        if (this.hasFormatToggleTarget) {
            this.formatToggleTarget.classList.toggle('hidden', true === plain);
        }

        // Link, emoji, inline image and signature all write into the rich
        // editor, which in plain text is hidden — they were live buttons that
        // silently did nothing. Greyed while plain, and back exactly as they
        // were on the way out. The encrypt button is NOT in this set: it is
        // permanently disabled for reasons of its own and re-enabling it here
        // would be the one lie a mail client must not tell.
        this._setRichAffordances(false === plain);

        if (this.hasPlainToggleTarget) {
            this.plainToggleTarget.setAttribute('aria-pressed', true === plain ? 'true' : 'false');
        }

        if (this.hasPlainCheckTarget) {
            this.plainCheckTarget.classList.toggle('invisible', false === plain);
        }

        (true === plain ? textarea : editor).focus();
        this._scheduleAutosave();
    }

    /**
     * Enable or grey the toolbar buttons that only the rich editor can serve.
     *
     * Marked in the template (`richOnly`) rather than found by selector, so
     * that adding a button to the icon row does not silently opt it in — or,
     * worse, opt the permanently-disabled encrypt button in with it.
     */
    _setRichAffordances(enabled) {
        if (false === this.hasRichOnlyTarget) {
            return;
        }

        this.richOnlyTargets.forEach((button) => {
            button.disabled = false === enabled;
            button.classList.toggle('opacity-40', false === enabled);
            button.classList.toggle('cursor-not-allowed', false === enabled);
        });
    }

    /** The editor's content as text, block boundaries becoming newlines. */
    _bodyAsText(editor) {
        const clone = editor.cloneNode(true);

        clone.querySelectorAll('br').forEach((br) => br.replaceWith('\n'));
        clone.querySelectorAll('p, div, li, tr').forEach((block) => block.append('\n'));

        return clone.textContent.replace(/\n{3,}/g, '\n\n').trim();
    }

    /** Text back into the editor, escaped — never as markup. */
    _textAsHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;

        return div.innerHTML.replace(/\n/g, '<br>');
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

        // Set BEFORE the threshold check, on purpose. Writing that is too short
        // to autosave is exactly the writing that would otherwise vanish
        // without trace, so it is the case the unload guard most needs to know
        // about.
        this._unsaved = this._contentLength() > 0;

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
        //
        // "Nothing written" means the subject as well as the body — this gate
        // asked about the body alone, so a draft whose only content was a
        // subject was refused here even once the threshold above had let it
        // through, and the save never happened. See _contentLength().
        if (false === allowEmpty && 0 === this._contentLength() && null === this._messageId()) {
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
                // Outside the status-target check on purpose. Reading the new
                // URLs used to be conditional on there being somewhere to print
                // "Draft saved", which tied whether the window's routes were
                // correct to whether it had a status line — two unrelated
                // things. A window without one still needs to know its own id.
                const html = await response.text();
                const doc  = new DOMParser().parseFromString(html, 'text/html');
                this._adoptSavedUrls(doc);

                if (status) {
                    this._setStatus(this._t('saved', 'Draft saved'), 'text-success');
                }

                // What is on screen is now what is on the server, so leaving
                // costs nothing and must not be interrupted.
                this._unsaved = false;
            } else {
                throw new Error('Server error');
            }
        } catch (_) {
            this._setStatus(this._t('saveFailed', 'Could not save the draft'), 'text-danger');
        }
    }

    /**
     * Take the id-bearing URLs out of an autosave response.
     *
     * A window opens with no message id, so every route it knows is the
     * "create" one. The first autosave mints the id and answers with a window
     * that knows all the id-bearing routes; this is where the open window
     * catches up with it.
     *
     * The schedule URL is the one that was missing, and its absence was a
     * permanent duplicate draft rather than a broken button. The send pill's
     * items are submit buttons carrying `formaction`, rendered once when the
     * window opened, so they went on pointing at `/compose/schedule` with no
     * id. ComposeController::schedule() takes a null message there and does
     * exactly what it is told — builds a NEW one. The result was two identical
     * rows in Drafts from one composition, one of them scheduled and one of
     * them stranded, and the stranded one never went away because nothing
     * afterwards knew it was a twin.
     *
     * Belt and braces on the form action too: `formaction` on a submit button
     * beats `action` on the form, so both have to be right.
     */
    _adoptSavedUrls(doc) {
        const fresh = doc.querySelector('[data-compose--compose-draft-url-value]');

        if (!fresh) { return; }

        this.draftUrlValue = fresh.getAttribute('data-compose--compose-draft-url-value');
        this.sendUrlValue  = fresh.getAttribute('data-compose--compose-send-url-value');

        const scheduleUrl = fresh.getAttribute('data-compose--compose-schedule-url-value');

        if (scheduleUrl) {
            this.scheduleUrlValue = scheduleUrl;

            // Every schedule submit button in this window: the presets, and the
            // custom picker's confirm. There are two send pills (the header one
            // below md and the action-bar one above it) and both are live.
            for (const button of this.element.querySelectorAll(
                '[data-compose-send-pill] button[formaction]',
            )) {
                button.setAttribute('formaction', scheduleUrl);
            }
        }

        const form = this.element.querySelector('form');

        if (form) {
            form.action = this.sendUrlValue;
        }
    }

    /** Writing on screen that the server has not been told about yet. */
    _hasUnsavedWriting() {
        return true === this._unsaved && this._contentLength() > 0;
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

        return this._contentLength() >= this.minCharsValue;
    }

    /**
     * Everything the user has written that a draft would keep — the subject as
     * well as the body.
     *
     * The threshold used to look at the body alone, so a subject typed on its
     * own was never worth saving no matter how long it got. A tester typed
     * "TEST-RELOAD halbfertig" into Subject, waited past the autosave delay,
     * reloaded, and it was gone: not in the composer, not in Drafts, and no
     * warning on the way out.
     *
     * Counting the subject does not weaken what the threshold is FOR. Its job
     * is to stop a composer that was merely opened from littering the Drafts
     * list — and a composer that was merely opened has an empty subject. The
     * only drafts this newly saves are ones with something deliberately typed
     * in them.
     */
    _contentLength() {
        const subject = this.hasSubjectTarget ? this.subjectTarget.value.trim().length : 0;

        return subject + this._typedLength();
    }

    /** Characters the user has actually written, quote excluded. */
    _typedLength() {
        // Plain-text mode: the textarea IS the body, and none of the markup
        // this method strips below can exist in it.
        if (true === this._isPlainText()) {
            return this.plainBodyTarget.value.trim().length;
        }

        if (false === this.hasBodyTarget) {
            return 0;
        }

        const clone = this.bodyTarget.cloneNode(true);

        // The last two selectors cover drafts written before buildQuotedHtml
        // marked the quote with data-quoted.
        clone.querySelectorAll(
            '[data-quote-wrapped], [data-quoted], blockquote, div[style*="border-top"], div[style*="font-size:0.85em"]',
        ).forEach((node) => node.remove());

        // The signature is dropped for the same reason the quote is: it is not
        // the user's writing. A new window opens with it already in the body,
        // and counting it would put every fresh draft over minChars — so
        // opening the composer and closing it again would leave an autosaved
        // draft containing nothing but a sign-off.
        //
        // ONLY WHILE IT IS UNTOUCHED, though, and that qualifier is the fix.
        // Text typed into the signature block still counts, because it is still
        // text the user wrote. Dropping the block unconditionally is what made
        // Send insist "this message has no text" about a message the user could
        // read on screen — the caret had landed in the signature (see
        // claimWritingSpace) and every word they typed was being subtracted
        // again before the count.
        clone.querySelectorAll('[data-pl-signature]').forEach((node) => {
            if (node.textContent.trim() === this._pristineSignatureText()) {
                node.remove();
            }
        });

        return clone.textContent.trim().length;
    }

    /**
     * The current identity's signature as plain text — what an UNEDITED
     * signature block in the body reads as.
     *
     * Text rather than HTML because the comparison has to survive the round
     * trip through contenteditable, which normalises markup freely (attribute
     * order, `<br>` versus `<br/>`, whitespace between blocks) without anyone
     * having typed a thing. The words are what "untouched" means here.
     */
    _pristineSignatureText() {
        const html = this._currentSignature();

        if ('' === html) {
            return '';
        }

        const probe = document.createElement('div');
        probe.innerHTML = html;

        return probe.textContent.trim();
    }

    /**
     * Below the threshold the status line counts down instead of going quiet,
     * so it is obvious the draft is not being kept yet.
     */
    _reportPending() {
        if (false === this.hasSaveStatusTarget) {
            return;
        }

        const missing = this.minCharsValue - this._contentLength();
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
        this._errorsId();
        this._markInvalid(true);
    }

    _clearError() {
        if (true === this.hasErrorsTarget) {
            this.errorsTarget.classList.add('hidden');
            this.errorsTarget.querySelector('p').textContent = '';
        }

        this._markInvalid(false);
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
    _guardSend(submitter) {
        // What is half-typed in an address field is an address the user
        // believes they have entered. Committing it here is what stops Send
        // from reporting "no recipient" about a field with an address visibly
        // in it — and, when the address is malformed, what makes the refusal
        // say so instead of blaming the count.
        if (false === this._commitPendingAddresses()) {
            return false;
        }

        if (0 === this._recipientCount()) {
            this._reportError(this._t('recipientRequired', 'Add at least one recipient before sending.'));

            return false;
        }

        this._clearError();

        // Answered already, on the panel below. One question, one answer.
        if (true === this._sendConfirmed) {
            this._sendConfirmed = false;

            return true;
        }

        const missing = [];

        if ('' === (this.hasSubjectTarget ? this.subjectTarget.value.trim() : 'x')) {
            missing.push(this._t('confirmNoSubject', 'This message has no subject.'));
        }

        if (0 === this._typedLength()) {
            missing.push(this._t('confirmNoBody', 'This message has no text.'));
        }

        if (0 === missing.length) {
            return true;
        }

        this._openSendWarning(missing, submitter);

        return false;
    }

    /**
     * Turn anything typed-but-uncommitted in To/Cc/Bcc into a chip.
     *
     * @returns {boolean} false when something typed is not an address, having
     *                    said so — and having LEFT IT IN THE FIELD. Tom Select
     *                    drops an uncommittable string on blur, so pressing Send
     *                    with a malformed address in the box used to throw the
     *                    text away and then complain there were no recipients:
     *                    two wrongs, and the user could no longer see what they
     *                    had typed to fix it.
     */
    _commitPendingAddresses() {
        for (const row of this._addressRows()) {
            const input  = row.querySelector('.ts-control input');
            const select = this._tomSelectFor(row.querySelector('.ts-wrapper'));

            // The box first, then what was last typed into it — see the `type`
            // handler in _tameAddressField for why the box is usually empty by
            // the time we get here.
            const typed = (input?.value.trim() || select?.plmailTyped?.trim()) ?? '';

            if ('' === typed) {
                continue;
            }

            if (false === this.constructor.ADDRESS.test(typed)) {
                this._reportError(
                    this._t('invalidAddress', '"%s" is not a valid email address').replace('%s', typed),
                );

                // Put it back where the user left it. A refusal that also
                // deletes the thing being refused leaves nothing to correct.
                select?.setTextboxValue(typed);
                input?.focus();

                return false;
            }

            if (null !== select) {
                select.createItem(typed, false);
                select.setTextboxValue('');
                select.plmailTyped = '';
            }
        }

        return true;
    }

    /** The in-app replacement for the two `window.confirm()` calls. */
    _openSendWarning(reasons, submitter) {
        if (false === this.hasSendWarningTarget) {
            return;
        }

        // Held so the retry submits through the SAME button. The schedule
        // buttons carry `formaction`, and re-submitting without the submitter
        // would quietly turn "send on Monday" into "send now".
        this._pendingSubmitter = submitter ?? null;

        this.sendWarningBodyTarget.textContent =
            `${reasons.join(' ')} ${this._t('confirmSendAnyway', 'Send it anyway?')}`;
        this.sendWarningTarget.hidden = false;

        this._boundSendWarningEscape ??= (event) => {
            if ('Escape' === event.key) {
                event.stopPropagation();
                this.cancelSendAnyway();
            }
        };

        document.addEventListener('keydown', this._boundSendWarningEscape);

        if (this.hasSendWarningConfirmTarget) {
            this.sendWarningConfirmTarget.focus();
        }
    }

    _closeSendWarning() {
        if (true === this.hasSendWarningTarget) {
            this.sendWarningTarget.hidden = true;
        }

        document.removeEventListener('keydown', this._boundSendWarningEscape);
    }

    /** "Send anyway" — go back through the same submit, past the question. */
    confirmSendAnyway() {
        const submitter = this._pendingSubmitter;

        this._closeSendWarning();
        this._sendConfirmed  = true;
        this._pendingSubmitter = null;

        const form = this.element.querySelector('form');

        if (null !== form) {
            form.requestSubmit(submitter ?? undefined);
        }
    }

    /** "Cancel" or Escape. Nothing is sent and nothing is lost. */
    cancelSendAnyway() {
        this._closeSendWarning();
        this._sendConfirmed  = false;
        this._pendingSubmitter = null;
    }

    _handleSubmit(event) {
        if (true === this._submitting) {
            event.preventDefault();
            return;
        }

        if (false === this._guardSend(event.submitter)) {
            event.preventDefault();

            return;
        }

        clearTimeout(this.#autosaveTimer);
        this._submitting = true;

        // The form is on its way to the server with everything in it. Whatever
        // happens next, nothing is being abandoned.
        this._unsaved = false;

        // TWO in the DOM, one on screen: the send pill is rendered twice from
        // one partial — `md:hidden` in the window header for phones, `hidden
        // md:flex` in the action bar for the desktop — and only ever one of
        // them is displayed. A target LIST rather than a single target is what
        // makes that safe: both get disabled and relabelled, so the copy the
        // user cannot see can never come back armed. The fallback below covers
        // the settings signature editor, which renders this toolbar with no
        // pill at all.
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
