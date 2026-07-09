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
    ];

    static FONT_SIZES = {
        'Small':  '0.8em',
        'Normal': '1em',
        'Large':  '1.3em',
        'Huge':   '1.8em',
    };

    static FONT_FAMILIES = {
        'Sans Serif': 'Arial, sans-serif',
        'Serif':      'Georgia, serif',
        'Monospace':  '"Courier New", monospace',
    };

    // Saved selection range — populated on editor blur so colour picker
    // and other focus-stealing controls can restore it before acting.
    #savedRange = null;

    connect() {
        this._boundUpdateState = this._updateButtonState.bind(this);
        this._boundSyncInput   = this._syncHiddenInput.bind(this);
        this._boundSaveRange   = this._saveRange.bind(this);

        if (this.hasEditorTarget) {
            this.editorTarget.addEventListener('keyup',   this._boundUpdateState);
            this.editorTarget.addEventListener('mouseup', this._boundUpdateState);
            this.editorTarget.addEventListener('input',   this._boundSyncInput);
            this.editorTarget.addEventListener('blur',    this._boundSaveRange);
        }

        this.element.querySelectorAll('[data-compose-toolbar-action]').forEach(btn => {
            btn.addEventListener('mousedown', e => e.preventDefault());
        });
    }

    disconnect() {
        if (this.hasEditorTarget) {
            this.editorTarget.removeEventListener('keyup',   this._boundUpdateState);
            this.editorTarget.removeEventListener('mouseup', this._boundUpdateState);
            this.editorTarget.removeEventListener('input',   this._boundSyncInput);
            this.editorTarget.removeEventListener('blur',    this._boundSaveRange);
        }
    }

    // ── Selection save / restore ──────────────────────────────────────

    _saveRange() {
        const sel = window.getSelection();
        if (sel && sel.rangeCount > 0) {
            this.#savedRange = sel.getRangeAt(0).cloneRange();
        } else {
            this.#savedRange = null;
        }
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

    insertLink() {
        this._saveRange();
        const url = prompt('Enter URL:');
        if (url) {
            this._restoreRange();
            this._exec('createLink', url);
        }
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
        target.classList.toggle('bg-gray-200',      active);
        target.classList.toggle('dark:bg-gray-600', active);
        target.classList.toggle('text-gray-900',    active);
        target.classList.toggle('dark:text-white',  active);
    }

    // ── Sync contenteditable → hidden input ───────────────────────────

    _syncHiddenInput() {
        if (this.hasEditorTarget && this.hasHiddenInputTarget) {
            this.hiddenInputTarget.value = this.editorTarget.innerHTML;
        }
    }
}
