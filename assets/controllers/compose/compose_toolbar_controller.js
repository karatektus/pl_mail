// assets/controllers/compose-toolbar_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'editor',
        'hiddenInput',
        'fontFamily',
        'fontSize',
        'boldBtn',
        'italicBtn',
        'underlineBtn',
        'colorSwatch',
        // The link popover and its two faces. See #openLinkPopover.
        'linkBtn',
        'linkPopover',
        'linkEdit',
        'linkView',
        'linkHeading',
        'linkUrl',
        'linkText',
        'linkError',
        'linkUnlink',
        'linkHref',
    ];

    static values = {
        // Translated server-side and handed over as one object, exactly as
        // compose--compose and ui--select take theirs. Nothing in this file
        // spells an English string it expects a user to read.
        i18n: { type: Object, default: {} },
    };

    // Keyed by the <option>'s value, not its label. The labels used to be the
    // keys, which meant they could never be translated: "Small" was both what
    // the German user read and what this map had to be looked up by.
    static FONT_SIZES = {
        small:  '0.8em',
        normal: '1em',
        large:  '1.3em',
        huge:   '1.8em',
    };

    static FONT_FAMILIES = {
        sans:      'Arial, sans-serif',
        serif:     'Georgia, serif',
        monospace: '"Courier New", monospace',
    };

    /**
     * The schemes a link may carry, and deliberately the same four as
     * MailBodySanitizer::allowLinkSchemes(). Kept identical so the editor never
     * accepts an href the save would silently drop — a `javascript:` URL that
     * looked fine while writing and vanished on reload is a worse bug than a
     * refusal, because nothing tells the writer it happened.
     */
    static LINK_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    // Saved selection range — populated on editor blur so colour picker
    // and other focus-stealing controls can restore it before acting.
    #savedRange = null;

    // While the link popover is open the saved range is FROZEN. Focusing a
    // text field inside the popover blurs the editor, which fires the blur
    // handler, which would otherwise overwrite the very range the popover was
    // opened to act on — with whatever the selection had collapsed to. This is
    // the whole selection-preservation story in one flag.
    #rangeLocked = false;

    // The <a> the popover is currently about, if any. Non-null means "edit this
    // one in place"; null means "wrap the selection in a new one". Nesting an
    // anchor inside an anchor is not something a mail client should ever
    // produce, and execCommand('createLink') happily would.
    #linkAnchor = null;

    connect() {
        this._boundUpdateState = this._updateButtonState.bind(this);
        this._boundSyncInput   = this._syncHiddenInput.bind(this);
        this._boundSaveRange   = this._saveRange.bind(this);
        this._boundEditorClick = this._handleEditorClick.bind(this);
        this._boundOutside     = this._handleOutside.bind(this);
        this._boundEscape      = this._handleEscape.bind(this);
        this._boundSelection   = this._handleSelectionChange.bind(this);

        if (this.hasEditorTarget) {
            this.editorTarget.addEventListener('keyup',   this._boundUpdateState);
            this.editorTarget.addEventListener('mouseup', this._boundUpdateState);
            this.editorTarget.addEventListener('input',   this._boundSyncInput);
            this.editorTarget.addEventListener('blur',    this._boundSaveRange);
            this.editorTarget.addEventListener('click',   this._boundEditorClick);
        }

        this.element.querySelectorAll('[data-compose--compose-toolbar-action]').forEach(btn => {
            btn.addEventListener('mousedown', e => e.preventDefault());
        });
    }

    disconnect() {
        if (this.hasEditorTarget) {
            this.editorTarget.removeEventListener('keyup',   this._boundUpdateState);
            this.editorTarget.removeEventListener('mouseup', this._boundUpdateState);
            this.editorTarget.removeEventListener('input',   this._boundSyncInput);
            this.editorTarget.removeEventListener('blur',    this._boundSaveRange);
            this.editorTarget.removeEventListener('click',   this._boundEditorClick);
        }

        this._detachLinkListeners();
    }

    // ── Selection save / restore ──────────────────────────────────────

    _saveRange() {
        if (true === this.#rangeLocked) {
            return;
        }

        const sel = window.getSelection();
        if (sel && sel.rangeCount > 0) {
            this.#savedRange = sel.getRangeAt(0).cloneRange();
        } else {
            this.#savedRange = null;
        }
    }

    /**
     * Capture the selection RIGHT NOW, past the lock.
     *
     * Used at the moment a popover opens: the toolbar buttons cancel their own
     * mousedown, so the editor still holds the user's selection when the click
     * handler runs, and this is the last instant at which that is true.
     *
     * It does not itself LEAVE the lock on — _openLinkEditor sets it, before
     * it focuses a field and therefore before anything can blur the editor.
     * Capturing without opening (a surface with no popover in it) must not
     * leave the range frozen for the rest of the session.
     */
    _captureRange() {
        const locked = this.#rangeLocked;

        this.#rangeLocked = false;
        this._saveRange();
        this.#rangeLocked = locked;
    }

    _restoreRange() {
        this._focusEditor();
        if (!this.#savedRange) { return; }
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(this.#savedRange);
    }

    // ── Focus helper ──────────────────────────────────────────────────

    _focusEditor() {
        if (this.hasEditorTarget) {
            this.editorTarget.focus();
        }
    }

    // ── execCommand wrapper ───────────────────────────────────────────

    _exec(command, value = null) {
        this._focusEditor();
        document.execCommand(command, false, value);
        this._updateButtonState();
        this._syncHiddenInput();
    }

    // ── Span style injection ──────────────────────────────────────────
    //
    // Strategy: rather than always wrapping in a new <span>, we:
    //   1. Find all existing <span> nodes inside the selection that carry
    //      the same CSS property and remove that property from them
    //      (cleaning up empty spans afterwards).
    //   2. Check whether the *entire* selection is already inside a single
    //      ancestor <span> with that property — if so, just mutate it.
    //   3. Otherwise wrap the (extracted) contents in one new <span>.
    //
    // For a *collapsed* cursor we look for the nearest ancestor <span>
    // that has the property set and update it; otherwise we insert a
    // zero-width-space span so subsequent typing picks up the style.

    _applySpanStyle(property, value) {
        // Always restore the saved range first — the selection may have been
        // lost if a picker or dropdown stole focus.
        this._restoreRange();

        const sel = window.getSelection();
        if (!sel || sel.rangeCount === 0) { return; }

        const range = sel.getRangeAt(0);

        if (range.collapsed) {
            // Collapsed cursor: update nearest ancestor span that has this
            // property, or insert a carrier span for future typing.
            const ancestor = this._nearestStyledAncestor(range.startContainer, property);
            if (ancestor) {
                ancestor.style[property] = value;
            } else {
                const span = document.createElement('span');
                span.style[property] = value;
                span.innerHTML = '&#8203;'; // zero-width space
                range.insertNode(span);

                const newRange = document.createRange();
                newRange.setStart(span.firstChild, 1);
                newRange.collapse(true);
                sel.removeAllRanges();
                sel.addRange(newRange);
            }
        } else {
            // Non-collapsed selection:
            // Step 1 — strip the property from any existing spans inside the range.
            this._stripPropertyInRange(range, property);

            // Step 2 — re-fetch range (DOM may have changed) and wrap in one span.
            const freshRange = sel.getRangeAt(0);
            const span = document.createElement('span');
            span.style[property] = value;
            span.appendChild(freshRange.extractContents());

            // Clean up any now-empty spans we pulled in.
            this._removeEmptySpans(span);

            freshRange.insertNode(span);

            const newRange = document.createRange();
            newRange.selectNodeContents(span);
            sel.removeAllRanges();
            sel.addRange(newRange);
        }

        this._syncHiddenInput();
    }

    // Walk up from `node` to the editor boundary; return the first <span>
    // that has `property` set in its inline style.
    _nearestStyledAncestor(node, property) {
        let el = (node.nodeType === Node.TEXT_NODE) ? node.parentElement : node;
        while (el && el !== this.editorTarget) {
            if (el.tagName === 'SPAN' && el.style[property]) {
                return el;
            }
            el = el.parentElement;
        }
        return null;
    }

    // Remove inline `property` from every <span> descendant of `root`
    // that intersects `range`, then clean up any spans that become
    // style-less (and therefore invisible wrappers).
    _stripPropertyInRange(range, property) {
        const editor = this.editorTarget;
        // Collect all spans inside the editor that are fully or partially
        // within the range.
        const spans = Array.from(editor.querySelectorAll('span'));
        for (const span of spans) {
            if (span.style[property] && range.intersectsNode(span)) {
                span.style[property] = '';
                // If the span now has no inline style at all, unwrap it.
                if (span.getAttribute('style') === '' || span.style.cssText.trim() === '') {
                    this._unwrapNode(span);
                }
            }
        }
    }

    // Replace `node` in the DOM with its own children (unwrap).
    _unwrapNode(node) {
        const parent = node.parentNode;
        if (!parent) { return; }
        while (node.firstChild) {
            parent.insertBefore(node.firstChild, node);
        }
        parent.removeChild(node);
    }

    // Remove empty <span> elements (no text content, no meaningful children)
    // from inside `root`.
    _removeEmptySpans(root) {
        root.querySelectorAll('span').forEach(span => {
            if (span.textContent === '' && !span.querySelector('img,br,input')) {
                span.remove();
            }
        });
    }

    // ── Formatting actions ────────────────────────────────────────────

    bold()      { this._exec('bold'); }
    italic()    { this._exec('italic'); }
    underline() { this._exec('underline'); }
    undo()      { this._exec('undo'); }
    redo()      { this._exec('redo'); }
    outdent()   { this._exec('outdent'); }
    indent()    { this._exec('indent'); }

    alignLeft()    { this._exec('justifyLeft'); }
    alignCenter()  { this._exec('justifyCenter'); }
    alignRight()   { this._exec('justifyRight'); }
    alignJustify() { this._exec('justifyFull'); }

    // ── Caret insertion ───────────────────────────────────────────────
    //
    // Emoji and inline images are the same operation: put a node where the
    // cursor was, not where the DOM happens to end. Both arrive from a control
    // that stole focus first — a picker panel, a file dialog, an upload that
    // took a second — so the saved range is the only record of where the user
    // was writing, and appending to the editor instead is the bug this exists
    // to avoid.

    /**
     * One emoji, as text.
     *
     * Deliberately the only way an emoji can enter the body: nothing here
     * watches what is typed, so `:)` stays `:)` and `:smile:` stays
     * `:smile:`. Auto-substitution rewrites what someone wrote, and a mail
     * client is the wrong place to guess.
     */
    insertEmoji(emoji) {
        if ('string' !== typeof emoji || '' === emoji) { return; }

        this._insertAtCaret(document.createTextNode(emoji));
    }

    /**
     * An uploaded image, referenced by the part it was stored as.
     *
     * `src` is the attachment route so the editor can render it; `data-cid` is
     * the identity InlineImageRewriter turns back into `cid:` on save, which is
     * what the recipient's client resolves against the embedded part.
     */
    insertInlineImage({ url, contentId }) {
        if ('string' !== typeof url || '' === url) { return; }

        const img = document.createElement('img');

        img.setAttribute('src', url);
        img.setAttribute('style', 'max-width:100%');

        if ('string' === typeof contentId && '' !== contentId) {
            img.setAttribute('data-cid', contentId);
        }

        this._insertAtCaret(img);
    }

    _insertAtCaret(node) {
        if (false === this.hasEditorTarget) { return; }

        this._restoreRange();

        const sel = window.getSelection();
        let range = null;

        // Only a selection that is actually inside this editor may be written
        // to — a range left over in another window's body, or none at all,
        // becomes "the end of this one".
        if (sel && sel.rangeCount > 0 && this.editorTarget.contains(sel.getRangeAt(0).commonAncestorContainer)) {
            range = sel.getRangeAt(0);
        } else {
            range = document.createRange();
            range.selectNodeContents(this.editorTarget);
            range.collapse(false);
        }

        range.deleteContents();
        range.insertNode(node);

        // Cursor after what was just inserted, so a second emoji lands beside
        // the first rather than before it.
        const after = document.createRange();
        after.setStartAfter(node);
        after.collapse(true);

        if (sel) {
            sel.removeAllRanges();
            sel.addRange(after);
        }

        this.#savedRange = after.cloneRange();

        this._syncHiddenInput();

        // The autosave is a listener on the form's `input` event, and nothing
        // the DOM is changed by script fires one. Without this the emoji is on
        // screen and in the hidden input, and gone again on reload.
        this.editorTarget.dispatchEvent(new Event('input', { bubbles: true }));
    }

    /**
     * Grey the editor out while its "inherit" switch is on.
     *
     * Only the settings signature field uses this: ticking inherit means the
     * stored value is about to be REMOVED, so leaving the box typeable would
     * invite the user to write a signature the submit then throws away.
     */
    toggleDisabled(event) {
        const inherit = true === event.target.checked;

        if (this.hasEditorTarget) {
            this.editorTarget.setAttribute('contenteditable', inherit ? 'false' : 'true');
            this.editorTarget.closest('[data-signature-disabled], .rounded-lg')
                ?.classList.toggle('opacity-50', inherit);
        }
    }

    // ── Links ─────────────────────────────────────────────────────────
    //
    // Three jobs, one popover.
    //
    //   • the toolbar button — write a link, or edit the one the caret is in
    //   • a click on a link in the body — say where it goes and offer the three
    //     things you can do to it, because a click in a contenteditable only
    //     places a caret and a link you just wrote is otherwise unreachable
    //   • Change, which is the first of those reached from the second
    //
    // The URL is normalised and screened HERE rather than left to the server.
    // MailBodySanitizer is the backstop and refuses anything outside
    // http/https/mailto/tel on the way out — but a `javascript:` href that the
    // editor accepted and the save silently dropped is an editing experience
    // that lies, and a bare "example.com" that becomes a relative link to
    // /mail/example.com is worse: it survives the sanitiser intact and is
    // simply wrong. Both are decided before anything enters the document.

    /** The toolbar button. */
    insertLink() {
        this._captureRange();

        const anchor    = this._anchorInSelection();
        const selection = this.#savedRange ? this.#savedRange.toString() : '';

        // The caret is inside a link: edit that one, prefilled from it. The
        // alternative — wrapping it in a second anchor — produces markup no
        // mail client renders the way the writer meant.
        if (null !== anchor) {
            this._openLinkEditor(anchor, anchor.getAttribute('href') ?? '', anchor.textContent);

            return;
        }

        this._openLinkEditor(null, '', selection);
    }

    /** Enter submits, Escape closes and gives the selection back. */
    linkKeydown(event) {
        // Only the editing face has anything to submit. Enter on the viewing
        // face's Open button must stay the browser's own "activate this link".
        const editing = this.hasLinkEditTarget && false === this.linkEditTarget.hidden;

        if ('Enter' === event.key && true === editing) {
            // The popover lives inside the compose <form>: an un-cancelled
            // Enter in a text field submits it, which is to say it SENDS the
            // message the user was still writing a link into.
            event.preventDefault();
            this.applyLink();

            return;
        }

        if ('Escape' === event.key) {
            event.preventDefault();
            event.stopPropagation();
            this.closeLink();
        }
    }

    /**
     * Cancel.
     *
     * The selection is handed back only if this was the EDITING face. That
     * face froze the range and took focus away from the body, so giving it
     * back is the courtesy that makes Escape a true no-op. The viewing face
     * did neither — it appears beside a caret the user placed themselves — and
     * restoring anything there would drag the caret back to wherever the last
     * saved range happened to be, which is the popover fighting the caret.
     */
    closeLink() {
        if (false === this.hasLinkPopoverTarget || true === this.linkPopoverTarget.hidden) {
            return;
        }

        const wasEditing = this.hasLinkEditTarget && false === this.linkEditTarget.hidden;

        this.linkPopoverTarget.hidden = true;
        this.#linkAnchor = null;
        this._detachLinkListeners();
        this._clearLinkError();

        if (this.hasLinkBtnTarget) {
            this.linkBtnTarget.setAttribute('aria-expanded', 'false');
        }

        // Unfreeze first, then restore — the restore itself moves the selection
        // and the next genuine blur must be free to record where it ended up.
        this.#rangeLocked = false;

        if (true === wasEditing) {
            this._restoreRange();
        }
    }

    /** Apply, from either the button or Enter. */
    applyLink() {
        if (false === this.hasLinkUrlTarget) {
            return;
        }

        const url = this._normaliseUrl(this.linkUrlTarget.value);

        if (null === url) {
            this._showLinkError(this._t(
                '' === this.linkUrlTarget.value.trim() ? 'linkInvalid' : 'linkUnsafe',
                '' === this.linkUrlTarget.value.trim()
                    ? 'Enter a web address.'
                    : 'Only http, https, mailto and tel addresses can be linked.',
            ));
            this.linkUrlTarget.focus();

            return;
        }

        const text = this.hasLinkTextTarget ? this.linkTextTarget.value.trim() : '';
        const anchor = this.#linkAnchor;

        if (null !== anchor) {
            this._writeAnchor(anchor, url, '' === text ? anchor.textContent : text);
            this.#linkAnchor = null;
            this._closeAndSync();

            return;
        }

        const created = document.createElement('a');
        this._writeAnchor(created, url, '' === text ? url : text);

        // _insertAtCaret restores the saved range and replaces whatever it
        // covers, which is right: the text field was prefilled FROM that
        // selection, so the selection is what the new link stands in for.
        this.#rangeLocked = false;
        this.linkPopoverTarget.hidden = true;
        this._detachLinkListeners();
        this._insertAtCaret(created);
        this._closeAndSync();
    }

    /** Open, from the viewing face. */
    openLink() {
        const href = this.hasLinkHrefTarget ? this.linkHrefTarget.getAttribute('href') : null;

        if (null === href || '#' === href) {
            return;
        }

        // `noopener` as well as the attribute on the anchor: this is a scripted
        // open and the feature string is what governs it.
        window.open(href, '_blank', 'noopener,noreferrer');
    }

    /** Change, from the viewing face — the same popover, other side up. */
    changeLink() {
        const anchor = this.#linkAnchor;

        if (null === anchor) {
            return;
        }

        this._openLinkEditor(anchor, anchor.getAttribute('href') ?? '', anchor.textContent);
    }

    /** Remove — the text stays, the link goes. */
    removeLink() {
        const anchor = this.#linkAnchor;

        if (null === anchor) {
            this.closeLink();

            return;
        }

        // Where to put the caret afterwards, worked out before the node it
        // refers to stops existing.
        const parent = anchor.parentNode;
        const marker = document.createTextNode('');

        parent?.insertBefore(marker, anchor);
        this._unwrapNode(anchor);

        this.#linkAnchor = null;
        this.#rangeLocked = false;

        const range = document.createRange();
        range.setStartAfter(marker);
        range.collapse(true);
        this.#savedRange = range.cloneRange();
        marker.remove();

        this.linkPopoverTarget.hidden = true;
        this._detachLinkListeners();
        this._closeAndSync();
    }

    // ── Link popover internals ────────────────────────────────────────

    /**
     * Show the editing face.
     *
     * `anchor` non-null makes this an edit in place and turns Remove on.
     *
     * The URL field takes focus, and doing so blurs the editor — which is
     * exactly why _captureRange() has already frozen the range. Nothing
     * between here and applyLink() can move the user's place.
     */
    _openLinkEditor(anchor, url, text) {
        if (false === this.hasLinkPopoverTarget
            || false === this.hasLinkEditTarget
            || false === this.hasLinkUrlTarget) {
            return;
        }

        this.#linkAnchor = anchor;
        this.#rangeLocked = true;

        this._clearLinkError();

        this.linkEditTarget.hidden = false;
        if (this.hasLinkViewTarget) { this.linkViewTarget.hidden = true; }

        if (this.hasLinkHeadingTarget) {
            this.linkHeadingTarget.textContent = null === anchor
                ? this._t('linkAdd', 'Insert link')
                : this._t('linkEdit', 'Edit link');
        }

        this.linkUrlTarget.value = url;

        if (this.hasLinkTextTarget) {
            this.linkTextTarget.value = text ?? '';
        }

        if (this.hasLinkUnlinkTarget) {
            this.linkUnlinkTarget.hidden = null === anchor;
        }

        this._showLinkPopover();

        // A new link starts in the URL field; an existing one usually wants its
        // address changed too, so it does the same.
        this.linkUrlTarget.focus();
        this.linkUrlTarget.select();
    }

    /** Show the viewing face for a link the caret has landed in. */
    _openLinkViewer(anchor) {
        if (false === this.hasLinkPopoverTarget
            || false === this.hasLinkViewTarget
            || false === this.hasLinkEditTarget) {
            return;
        }

        const href = anchor.getAttribute('href') ?? '';

        this.#linkAnchor = anchor;

        this.linkEditTarget.hidden = true;
        this.linkViewTarget.hidden = false;

        if (this.hasLinkHrefTarget) {
            this.linkHrefTarget.setAttribute('href', href);
            this.linkHrefTarget.textContent = href;
        }

        this._showLinkPopover();

        // Deliberately NOT focused and NOT range-locked: this face appears
        // while the user is still typing, and stealing the caret out of the
        // body to a popover they did not ask for is the behaviour the report
        // called "fighting the caret".
        document.addEventListener('selectionchange', this._boundSelection);
    }

    _showLinkPopover() {
        this.linkPopoverTarget.hidden = false;

        if (this.hasLinkBtnTarget) {
            this.linkBtnTarget.setAttribute('aria-expanded', 'true');
        }

        // Capture phase, like ui--dropdown's: the menu has to close even when
        // whatever was clicked stops propagation on its way up.
        document.addEventListener('click', this._boundOutside, { capture: true });
        document.addEventListener('keydown', this._boundEscape);
    }

    _detachLinkListeners() {
        document.removeEventListener('click', this._boundOutside, { capture: true });
        document.removeEventListener('keydown', this._boundEscape);
        document.removeEventListener('selectionchange', this._boundSelection);
    }

    _closeAndSync() {
        this.#rangeLocked = false;
        this._detachLinkListeners();

        if (this.hasLinkPopoverTarget) {
            this.linkPopoverTarget.hidden = true;
        }

        if (this.hasLinkBtnTarget) {
            this.linkBtnTarget.setAttribute('aria-expanded', 'false');
        }

        this._syncHiddenInput();

        // Nothing script changes in the DOM fires `input`, and the autosave is
        // a listener on it — without this the link is on screen and gone again
        // on reload. Same reason as _insertAtCaret's.
        if (this.hasEditorTarget) {
            this.editorTarget.dispatchEvent(new Event('input', { bubbles: true }));
        }

        this._focusEditor();
        this._restoreRange();
    }

    /** href, and the two attributes that keep a new tab from reaching back. */
    _writeAnchor(anchor, url, text) {
        anchor.setAttribute('href', url);
        anchor.setAttribute('target', '_blank');
        anchor.setAttribute('rel', 'noopener noreferrer');

        if ('string' === typeof text && '' !== text && anchor.textContent !== text) {
            anchor.textContent = text;
        }
    }

    /**
     * A typed address, as an href — or null if it must not become one.
     *
     * Refusals and rewrites, in order:
     *
     *   • control characters are stripped first, so `java\nscript:alert(1)` —
     *     which browsers happily execute and a naive scheme test reads as
     *     scheme-less — is measured as the `javascript:` URL it is.
     *   • a URL that names a scheme keeps it only if it is one the sanitiser
     *     will also keep. That list is MailBodySanitizer's allowLinkSchemes()
     *     verbatim, so nothing this accepts is dropped on save.
     *   • "example.com" becomes "https://example.com" rather than a relative
     *     link. This is the case the old prompt got wrong every time.
     *   • "someone@example.com" becomes a mailto:, which is what anyone typing
     *     an address into a link field meant.
     */
    _normaliseUrl(raw) {
        if ('string' !== typeof raw) { return null; }

        // Control characters out first. Browsers strip the tab and newline
        // from a URL themselves, which is what makes "java\nscript:alert(1)"
        // execute while reading as scheme-less to a naive test.
        const value = raw.replace(/[\u0000-\u001F\u007F]/g, '').trim();

        if ('' === value) { return null; }

        const scheme = /^([a-zA-Z][a-zA-Z0-9+.-]*):/.exec(value);

        if (null !== scheme) {
            return this.constructor.LINK_SCHEMES.includes(scheme[1].toLowerCase())
                ? value
                : null;
        }

        // Protocol-relative, which is a scheme choice rather than a hostname.
        if (value.startsWith('//')) { return `https:${value}`; }

        // An address, not a host. A slash anywhere means it is a path.
        if (value.includes('@') && false === value.includes('/')) {
            return `mailto:${value}`;
        }

        return `https://${value}`;
    }

    /** The <a> the saved range sits in, or null. */
    _anchorInSelection() {
        if (null === this.#savedRange || false === this.hasEditorTarget) {
            return null;
        }

        const node = this.#savedRange.startContainer;
        const el   = (node.nodeType === Node.TEXT_NODE) ? node.parentElement : node;
        const link = el?.closest?.('a');

        return (link && this.editorTarget.contains(link)) ? link : null;
    }

    /**
     * A click on a link in the body.
     *
     * The caret is left exactly where the browser put it — the popover is an
     * addition to the click, not a replacement for it — except on a modified
     * click, which everywhere else in every application means "follow this",
     * and is honoured as such.
     */
    _handleEditorClick(event) {
        const anchor = event.target?.closest?.('a');

        if (!anchor || false === this.editorTarget.contains(anchor)) {
            return;
        }

        if (true === event.metaKey || true === event.ctrlKey) {
            event.preventDefault();

            const href = this._normaliseUrl(anchor.getAttribute('href') ?? '');

            if (null !== href) {
                window.open(href, '_blank', 'noopener,noreferrer');
            }

            return;
        }

        this._openLinkViewer(anchor);
    }

    /**
     * The caret has moved. If it is no longer in the link the viewing face is
     * about, that face has nothing left to describe.
     *
     * Only ever attached while the viewer is up — the editing face freezes the
     * selection on purpose and must not be dismissed by its own focus move.
     */
    _handleSelectionChange() {
        if (null === this.#linkAnchor || false === this.hasLinkViewTarget) {
            return;
        }

        if (true === this.linkViewTarget.hidden) {
            return;
        }

        const sel = window.getSelection();

        if (!sel || 0 === sel.rangeCount) {
            return;
        }

        const node = sel.getRangeAt(0).startContainer;
        const el   = (node.nodeType === Node.TEXT_NODE) ? node.parentElement : node;

        // Still inside the popover (the user is reaching for one of its
        // buttons) or still inside the link: leave it up.
        if (this.linkPopoverTarget.contains(el) || this.#linkAnchor.contains(el)) {
            return;
        }

        this.linkPopoverTarget.hidden = true;
        this.#linkAnchor = null;
        this._detachLinkListeners();
    }

    _handleOutside(event) {
        if (false === this.linkPopoverTarget.contains(event.target)) {
            this.closeLink();
        }
    }

    _handleEscape(event) {
        if ('Escape' === event.key) {
            event.stopPropagation();
            this.closeLink();
        }
    }

    _showLinkError(message) {
        if (false === this.hasLinkErrorTarget) { return; }

        this.linkErrorTarget.textContent = message;
        this.linkErrorTarget.hidden = false;
    }

    _clearLinkError() {
        if (false === this.hasLinkErrorTarget) { return; }

        this.linkErrorTarget.textContent = '';
        this.linkErrorTarget.hidden = true;
    }

    /** A translated string, falling back to English if the value is absent. */
    _t(key, fallback) {
        return this.i18nValue?.[key] ?? fallback;
    }

    changeFontFamily(event) {
        const family = this.constructor.FONT_FAMILIES[event.target.value];
        if (family) {
            this._applySpanStyle('fontFamily', family);
        }
    }

    changeFontSize(event) {
        const size = this.constructor.FONT_SIZES[event.target.value];
        if (size) {
            this._applySpanStyle('fontSize', size);
        }
    }

    changeColor(event) {
        const color = event.target.value;
        this._applySpanStyle('color', color);
        if (this.hasColorSwatchTarget) {
            this.colorSwatchTarget.style.backgroundColor = color;
        }
    }

    // ── Lists ─────────────────────────────────────────────────────────

    orderedList()   { this._toggleList('OL'); }
    unorderedList() { this._toggleList('UL'); }

    _toggleList(tag) {
        this._focusEditor();

        const sel = window.getSelection();
        if (!sel || sel.rangeCount === 0) { return; }

        const range    = sel.getRangeAt(0);
        const existing = this._closestList(range.startContainer);

        if (existing) {
            if (existing.tagName === tag) {
                this._unwrapList(existing);
            } else {
                const newList = document.createElement(tag);
                while (existing.firstChild) {
                    newList.appendChild(existing.firstChild);
                }
                existing.parentNode.replaceChild(newList, existing);
            }
        } else {
            this._wrapInList(tag, range, sel);
        }

        this._syncHiddenInput();
    }

    _wrapInList(tag, range, sel) {
        const editor = this.editorTarget;
        const blocks = this._getSelectedTopLevelBlocks(range, editor);
        const list   = document.createElement(tag);

        if (blocks.length > 0) {
            blocks.forEach(block => {
                const li = document.createElement('li');
                while (block.firstChild) {
                    li.appendChild(block.firstChild);
                }
                list.appendChild(li);
            });

            blocks.forEach(block => block.remove());

            editor.appendChild(list);
        } else {
            const li = document.createElement('li');
            list.appendChild(li);
            editor.appendChild(list);
        }

        // Place cursor inside the first <li>
        const firstLi = list.querySelector('li');
        if (firstLi) {
            const r = document.createRange();
            r.setStart(firstLi, 0);
            r.collapse(true);
            sel.removeAllRanges();
            sel.addRange(r);
        }
    }

    _unwrapList(list) {
        const parent = list.parentNode;

        Array.from(list.querySelectorAll('li')).forEach(li => {
            const div = document.createElement('div');
            while (li.firstChild) {
                div.appendChild(li.firstChild);
            }
            parent.insertBefore(div, list);
        });

        list.remove();
    }

    _getSelectedTopLevelBlocks(range, editor) {
        const blocks = [];

        for (const child of editor.children) {
            if (range.intersectsNode(child)) {
                blocks.push(child);
            }
        }

        // Collapsed cursor — find the direct-child ancestor of startContainer
        if (blocks.length === 0) {
            let node = range.startContainer;
            while (node && node.parentNode !== editor) {
                node = node.parentNode;
            }
            if (node && node !== editor && editor.contains(node)) {
                blocks.push(node);
            }
        }

        return blocks;
    }

    _closestList(node) {
        let el = (node.nodeType === Node.TEXT_NODE) ? node.parentElement : node;
        while (el && el !== this.editorTarget) {
            if (el.tagName === 'UL' || el.tagName === 'OL') { return el; }
            el = el.parentElement;
        }
        return null;
    }

    // ── Active-state reflection ───────────────────────────────────────

    _updateButtonState() {
        this._setActive('boldBtn',      this._isFormatActive('bold'));
        this._setActive('italicBtn',    this._isFormatActive('italic'));
        this._setActive('underlineBtn', this._isFormatActive('underline'));
    }

    _isFormatActive(format) {
        const sel = window.getSelection();
        if (!sel || sel.rangeCount === 0) { return false; }

        let node = sel.anchorNode;
        if (node.nodeType === Node.TEXT_NODE) { node = node.parentElement; }

        while (node && node !== this.editorTarget) {
            const tag = node.tagName;
            const cs  = window.getComputedStyle(node);

            if (format === 'bold'      && (tag === 'B' || tag === 'STRONG' || parseInt(cs.fontWeight) >= 700)) { return true; }
            if (format === 'italic'    && (tag === 'I' || tag === 'EM'     || cs.fontStyle === 'italic'))       { return true; }
            if (format === 'underline' && (tag === 'U'                     || cs.textDecorationLine.includes('underline'))) { return true; }

            node = node.parentElement;
        }

        return false;
    }

    _setActive(targetName, active) {
        const capitalized = targetName.charAt(0).toUpperCase() + targetName.slice(1);
        if (!this[`has${capitalized}Target`]) { return; }
        const target = this[`${targetName}Target`];
        target.classList.toggle('bg-raised', active);
        target.classList.toggle('text-ink', active);
    }

    // ── Sync contenteditable → hidden input ───────────────────────────

    _syncHiddenInput() {
        if (!this.hasEditorTarget || !this.hasHiddenInputTarget) {
            return;
        }

        this.hiddenInputTarget.value = this._cleanHtml();
    }

    /**
     * Never let dev-tooling markup or editor chrome ride along into a saved
     * draft: the debug toolbar used to end up quoted inside the body, and the
     * "show quoted text" toggle is a UI affordance, not part of the mail.
     * Scripts go too — nothing executable belongs in a mail we're sending.
     */
    _cleanHtml() {
        const html = this.editorTarget.innerHTML;

        if (!/sf-toolbar|<script|sfwdt|data-quote-toggle/i.test(html)) {
            return html;
        }

        const clone = this.editorTarget.cloneNode(true);

        clone.querySelectorAll(
            'script, .sf-toolbar, [id^="sfwdt"], [data-frankenphp-hot-reload-preserve], [data-quote-toggle]',
        ).forEach((node) => node.remove());

        return clone.innerHTML;
    }
}
