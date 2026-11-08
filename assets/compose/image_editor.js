import { MIN_WIDTH, cropEdits, readEdits, renderImage, rotateEdits, writeEdits } from './image_edit.js';

/**
 * Resize, crop and rotate a picture in the compose body (#515).
 *
 * Click a picture and it is selected: a frame with four corner handles, and a
 * bar under it with sizes, a quarter turn either way and Crop. Dragging a
 * handle changes the width, keeping the shape. Everything lands on the <img>
 * as a recipe (see image_edit.js), and the compose window turns the recipe
 * into bytes — this class only edits the body.
 *
 * WHY A PLAIN CLASS AND NOT A CONTROLLER
 * ──────────────────────────────────────
 * It has no markup of its own to hang off: the frame and the bar are built
 * here, beside the editor rather than in it, because anything inside a
 * contenteditable is part of the message — it would be saved, typed over and
 * sent. And it answers to exactly one owner, compose--compose, which knows
 * which pictures can be edited and how to fetch an original. An outlet or an
 * event protocol between two controllers would be more moving parts for one
 * caller.
 *
 * UNDO
 * ────
 * Every edit replaces the <img> with an edited copy through
 * execCommand('insertHTML'), not by setting attributes. That is what puts it
 * on the browser's own undo stack, in order with typing: write a line, make a
 * picture smaller, write another, and Ctrl+Z three times takes back exactly
 * those three things. An undo stack of our own could not interleave with the
 * browser's, and the browser's is the one Ctrl+Z reaches. compose--compose
 * listens for the undo and brings the stored bytes back in line.
 *
 * The browser's own handles are switched off (Firefox draws them on any
 * picture in a contenteditable): they write a height as well as a width, and
 * do it as an untracked attribute change.
 */
export class InlineImageEditor {
    /**
     * @param {object} options
     * @param {HTMLElement} options.editor   the contenteditable body
     * @param {HTMLElement} options.host     a positioned element around it, for the frame and bar
     * @param {(key: string, fallback: string) => string} options.t
     * @param {(image: HTMLImageElement) => boolean} options.editable  whether the tools apply
     * @param {(image: HTMLImageElement) => Promise<?Blob>} options.original  the picture as uploaded
     * @param {(image: HTMLImageElement) => void} options.changed  an edit landed on this <img>
     * @param {(message: string) => void} options.failed  something could not be done; say so
     */
    constructor({ editor, host, t, editable, original, changed, failed }) {
        this.editor = editor;
        this.host = host;
        this.t = t;
        this.editable = editable;
        this.original = original;
        this.changed = changed;
        this.failed = failed;
        this.image = null;

        this._build();

        this._onClick = (event) => this._click(event);
        this._onPointerDown = (event) => this._outside(event);
        this._onKeyDown = (event) => this._key(event);
        this._onInput = () => this._follow();
        this._onResize = () => this.place();

        editor.addEventListener('click', this._onClick);
        editor.addEventListener('input', this._onInput);
        editor.addEventListener('keydown', this._onKeyDown);
        document.addEventListener('pointerdown', this._onPointerDown, true);
        window.addEventListener('resize', this._onResize);

        this._observer = 'function' === typeof ResizeObserver ? new ResizeObserver(this._onResize) : null;
        this._observer?.observe(editor);

        // The window's scroller, which decides what of the picture is on
        // screen; the bar follows it as it scrolls.
        this.scroller = scrollParent(host);
        this.scroller?.addEventListener('scroll', this._onResize, { passive: true });

        try {
            document.execCommand('enableObjectResizing', false, 'false');
        } catch (_) {
            // Chrome and Safari never had it; nothing to switch off there.
        }
    }

    destroy() {
        this.editor.removeEventListener('click', this._onClick);
        this.editor.removeEventListener('input', this._onInput);
        this.editor.removeEventListener('keydown', this._onKeyDown);
        document.removeEventListener('pointerdown', this._onPointerDown, true);
        window.removeEventListener('resize', this._onResize);
        this._observer?.disconnect();
        this.scroller?.removeEventListener('scroll', this._onResize);
        this.frame.remove();
        this.bar.remove();
        this.dialog?.remove();
    }

    /** Select a picture: the frame, the bar, and the picture as the selection. */
    select(image) {
        this.image = image;
        this.frame.hidden = false;
        this.bar.hidden = false;

        // The picture becomes the editor's selection, so Backspace or Delete
        // remove it and typing replaces it, as in any word processor.
        const range = document.createRange();
        const selection = window.getSelection();

        range.selectNode(image);
        selection?.removeAllRanges();
        selection?.addRange(range);

        this._refreshBar();
        // Nothing scrolls: selecting a picture leaves the message where the
        // person was reading it. place() puts the bar where it can be seen.
        this.place();

        if (false === image.complete) {
            image.addEventListener('load', this._onResize, { once: true });
        }
    }

    deselect() {
        this.image = null;
        this.frame.hidden = true;
        this.bar.hidden = true;
    }

    // ── Building ──────────────────────────────────────────────────────

    _build() {
        this.frame = document.createElement('div');
        this.frame.hidden = true;
        this.frame.dataset.imageFrame = '';
        this.frame.className = 'absolute z-20 pointer-events-none outline outline-2 outline-accent rounded-[2px]';

        for (const corner of ['nw', 'ne', 'sw', 'se']) {
            const handle = document.createElement('span');
            // Inside the corners, not straddling them: a picture at the foot
            // of the body ends where the window's scroller does, and half a
            // handle hanging below it was under the attachment strip.
            const vertical = 'n' === corner[0] ? 'top-0' : 'bottom-0';
            const horizontal = 'w' === corner[1] ? 'left-0' : 'right-0';

            handle.dataset.imageHandle = corner;
            handle.className = `absolute ${vertical} ${horizontal} pointer-events-auto touch-none ${handleClass(corner)}`;
            handle.addEventListener('pointerdown', (event) => this._startResize(event, corner));
            this.frame.append(handle);
        }

        this.bar = document.createElement('div');
        this.bar.hidden = true;
        this.bar.dataset.imageBar = '';
        this.bar.setAttribute('role', 'toolbar');
        this.bar.setAttribute('aria-label', this.t('imageToolbar', 'Picture'));
        this.bar.className = 'absolute z-20 popover flex flex-wrap items-center gap-0.5 p-1 text-xs';

        const sizes = [
            ['small', 'imageSmall', 'Small', SIZE_SMALL],
            ['medium', 'imageMedium', 'Medium', SIZE_MEDIUM],
            ['large', 'imageLarge', 'Large', SIZE_LARGE],
            ['full', 'imageFull', 'Full size', 0],
        ];

        for (const [name, key, fallback, width] of sizes) {
            const button = this._button(this.t(key, fallback), () => this._setWidth(width));

            button.dataset.imageSize = name;
            button.setAttribute('aria-pressed', 'false');
            this.bar.append(button);
        }

        this.bar.append(this._separator());
        this.bar.append(this._button(ICON_ROTATE_LEFT, () => this._rotate(-1), this.t('imageRotateLeft', 'Rotate left'), 'rotate-left'));
        this.bar.append(this._button(ICON_ROTATE_RIGHT, () => this._rotate(1), this.t('imageRotateRight', 'Rotate right'), 'rotate-right'));
        this.bar.append(this._separator());
        this.bar.append(this._button(`${ICON_CROP}<span>${escapeHtml(this.t('imageCrop', 'Crop'))}</span>`, () => this._openCrop(), null, 'crop'));

        this.host.append(this.frame, this.bar);
    }

    _button(content, action, label = null, name = null) {
        const button = document.createElement('button');

        button.type = 'button';
        button.className = 'inline-flex items-center gap-1 h-7 px-2 rounded text-ink-soft hover:bg-hover hover:text-ink '
            + 'aria-pressed:bg-accent aria-pressed:text-accent-ink '
            + 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent';

        if (null === label) {
            button.innerHTML = content.startsWith('<') ? content : escapeHtml(content);
        } else {
            button.innerHTML = content;
            button.setAttribute('aria-label', label);
            button.title = label;
        }

        if (null !== name) {
            button.dataset.imageAction = name;
        }

        // Keep the editor's selection (the picture) where it is: a button
        // that takes focus would leave the selection behind and make the
        // edit's execCommand land wherever the caret went.
        button.addEventListener('mousedown', (event) => event.preventDefault());
        button.addEventListener('click', (event) => {
            event.preventDefault();
            action();
        });

        return button;
    }

    _separator() {
        const line = document.createElement('span');

        line.className = 'mx-0.5 h-4 w-px bg-line';
        line.setAttribute('aria-hidden', 'true');

        return line;
    }

    // ── Selection ─────────────────────────────────────────────────────

    _click(event) {
        const image = event.target instanceof HTMLImageElement ? event.target : null;

        if (null === image || false === this.editable(image)) {
            if (null !== this.image) {
                this.deselect();
            }

            return;
        }

        event.preventDefault();
        this.select(image);
    }

    /** A press anywhere but the picture, its frame or its bar lets go of it. */
    _outside(event) {
        if (null === this.image) {
            return;
        }

        const target = event.target;

        if (target === this.image || this.frame.contains(target) || this.bar.contains(target) || this.dialog?.contains(target)) {
            return;
        }

        this.deselect();
    }

    _key(event) {
        if (null === this.image) {
            return;
        }

        if ('Escape' === event.key) {
            event.preventDefault();
            event.stopPropagation();

            const image = this.image;

            this.deselect();

            // The caret just after the picture, so typing goes on from there.
            const range = document.createRange();

            range.setStartAfter(image);
            range.collapse(true);
            window.getSelection()?.removeAllRanges();
            window.getSelection()?.addRange(range);
        }
    }

    /** The body changed: still the same picture on screen? Then follow it. */
    _follow() {
        if (null === this.image) {
            return;
        }

        if (false === this.editor.contains(this.image)) {
            this.deselect();

            return;
        }

        this.place();
    }

    /**
     * Frame over the picture; the bar where it can be seen without scrolling.
     * Under the picture when there is room on screen below it, over it when
     * there is room above — and when the picture fills the view, inside its
     * visible part at the foot, so it never leaves the screen.
     */
    place() {
        if (null === this.image) {
            return;
        }

        if (false === this.editor.contains(this.image)) {
            this.deselect();

            return;
        }

        const host = this.host.getBoundingClientRect();
        const box = this.image.getBoundingClientRect();
        const left = box.left - host.left + this.host.scrollLeft;
        const top = box.top - host.top + this.host.scrollTop;

        this.frame.style.left = `${Math.round(left)}px`;
        this.frame.style.top = `${Math.round(top)}px`;
        this.frame.style.width = `${Math.round(box.width)}px`;
        this.frame.style.height = `${Math.round(box.height)}px`;

        const room = this.host.clientWidth - this.bar.offsetWidth - 4;
        const height = this.bar.offsetHeight;
        const view = this.scroller?.getBoundingClientRect() ?? { top: 0, bottom: window.innerHeight };
        const visibleTop = Math.max(0, view.top);
        const visibleBottom = Math.min(window.innerHeight, view.bottom);
        let barTop;

        if (box.bottom + 6 + height <= visibleBottom) {
            barTop = box.bottom + 6;
        } else if (box.top - 6 - height >= visibleTop) {
            barTop = box.top - 6 - height;
        } else {
            barTop = Math.max(Math.max(box.top, visibleTop) + 6, Math.min(box.bottom, visibleBottom) - height - 6);
        }

        this.bar.style.left = `${Math.round(Math.max(0, Math.min(left, room)))}px`;
        this.bar.style.top = `${Math.round(barTop - host.top + this.host.scrollTop)}px`;
    }

    /** Which size button stands for the width now shown, if any. */
    _refreshBar() {
        if (null === this.image) {
            return;
        }

        const width = readEdits(this.image).displayWidth;

        this.bar.querySelectorAll('[data-image-size]').forEach((button) => {
            const size = { small: SIZE_SMALL, medium: SIZE_MEDIUM, large: SIZE_LARGE, full: 0 }[button.dataset.imageSize];

            button.setAttribute('aria-pressed', size === width ? 'true' : 'false');
        });
    }

    // ── Edits ─────────────────────────────────────────────────────────

    /**
     * Put an edited copy in the picture's place, as one undoable step (see
     * the class comment), and keep it selected.
     *
     * A browser that refuses the command — or puts the copy somewhere this
     * cannot find it — gets the edit written onto the picture directly:
     * not undoable, but never lost.
     */
    _replace(edits) {
        const image = this.image;

        if (null === image) {
            return;
        }

        const copy = image.cloneNode(false);

        writeEdits(copy, edits);
        copy.setAttribute('data-pl-editing', '');

        const range = document.createRange();
        const selection = window.getSelection();

        this.editor.focus({ preventScroll: true });
        range.selectNode(image);
        selection?.removeAllRanges();
        selection?.addRange(range);

        let fresh = null;

        try {
            if (true === document.execCommand('insertHTML', false, copy.outerHTML)) {
                fresh = this.editor.querySelector('img[data-pl-editing]');
            }
        } catch (_) {
            fresh = null;
        }

        if (null === fresh) {
            writeEdits(image, edits);
            fresh = image;
        } else {
            fresh.removeAttribute('data-pl-editing');

            // Chrome's insertHTML drops declarations the page's own CSS
            // already gives the element — and the app's reset gives every
            // <img> max-width:100% and height:auto. The recipient's client
            // has no such reset, so the style goes back as it was written.
            // On the node just inserted, so undo still removes all of it.
            writeEdits(fresh, edits);
        }

        this.select(fresh);
        this.changed(fresh);
    }

    /** A size button: 0 is the picture's own size. */
    _setWidth(width) {
        if (null === this.image) {
            return;
        }

        const edits = readEdits(this.image);

        if (edits.displayWidth === width) {
            return;
        }

        this._replace({ ...edits, displayWidth: width });
    }

    _rotate(direction) {
        if (null === this.image) {
            return;
        }

        const box = this.image.getBoundingClientRect();
        const aspect = box.height > 0 ? box.width / box.height : 1;

        this._replace(rotateEdits(readEdits(this.image), direction, aspect));
    }

    /**
     * A corner handle, dragged. The picture follows the pointer as a style
     * only — nothing in the undo stack, nothing saved — and the width lands
     * as one edit when the pointer is let go. Kept within the editor: a
     * picture wider than the message is shown at the message's width anyway.
     */
    _startResize(event, corner) {
        if (null === this.image || 0 !== event.button) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        const image = this.image;
        const startX = event.clientX;
        const startWidth = image.getBoundingClientRect().width;
        const grows = 'e' === corner[1] ? 1 : -1;
        const max = Math.max(MIN_WIDTH, this.editor.clientWidth);
        const before = image.getAttribute('style');
        let width = startWidth;

        const move = (moveEvent) => {
            width = Math.round(Math.min(max, Math.max(MIN_WIDTH, startWidth + grows * (moveEvent.clientX - startX))));
            image.style.width = `${width}px`;
            image.style.height = 'auto';
            this.place();
        };

        const end = () => {
            if (null === before) {
                image.removeAttribute('style');
            } else {
                image.setAttribute('style', before);
            }

            if (Math.abs(width - startWidth) >= 1) {
                this._replace({ ...readEdits(image), displayWidth: width });
            } else {
                this.place();
            }
        };

        followPointer(event, move, end);
    }

    // ── Crop ──────────────────────────────────────────────────────────

    /**
     * Crop in a dialog, on the whole picture turned the way it is now — not
     * on the part already kept, so a crop that took too much can be widened
     * again. A <dialog> in the top layer rather than a frame in the body: the
     * picture may be a thumbnail in the message, and nobody crops a
     * thumbnail.
     */
    async _openCrop() {
        const image = this.image;

        if (null === image) {
            return;
        }

        const edits = readEdits(image);
        let preview;

        try {
            const original = await this.original(image);

            if (null === original) {
                throw new Error('original');
            }

            preview = await renderImage(original, { rotate: edits.rotate, crop: null, displayWidth: 0 }, PREVIEW_CHOICE, { force: true }) ?? original;
        } catch (_) {
            this.failed(this.t('imageEditFailed', 'The picture could not be edited'));

            return;
        }

        const url = URL.createObjectURL(preview);
        const dialog = this._cropDialog();
        const picture = dialog.querySelector('[data-crop-image]');
        const frame = dialog.querySelector('[data-crop-frame]');

        this._cropRect = [...(edits.crop ?? [0, 0, 1, 1])];

        const draw = () => {
            const [x, y, w, h] = this._cropRect;

            frame.style.left = `${x * 100}%`;
            frame.style.top = `${y * 100}%`;
            frame.style.width = `${w * 100}%`;
            frame.style.height = `${h * 100}%`;
        };

        const close = (apply) => {
            dialog.close();
            URL.revokeObjectURL(url);
            picture.removeAttribute('src');

            if (true === apply && this.editor.contains(image)) {
                this.select(image);
                this._replace(cropEdits(readEdits(image), this._cropRect));
            } else if (this.editor.contains(image)) {
                this.select(image);
            }
        };

        this._cropDraw = draw;
        this._cropClose = close;

        picture.src = url;
        draw();
        dialog.showModal();
    }

    _cropDialog() {
        if (undefined !== this.dialog) {
            return this.dialog;
        }

        const dialog = document.createElement('dialog');

        dialog.dataset.imageCropDialog = '';
        dialog.setAttribute('aria-label', this.t('imageCrop', 'Crop'));
        dialog.className = 'pane-flat shadow-float text-ink';
        dialog.innerHTML = `
            <div class="flex flex-col gap-3 p-4">
                <div class="relative self-center select-none touch-none overflow-hidden" data-crop-stage>
                    <img data-crop-image alt="" draggable="false" class="block max-w-[min(86vw,900px)] max-h-[65vh] w-auto h-auto">
                    <div data-crop-frame class="absolute cursor-move outline outline-2 outline-white shadow-[0_0_0_9999px_rgba(0,0,0,0.5)]">
                        ${['nw', 'ne', 'sw', 'se'].map((corner) => `<span data-crop-handle="${corner}" class="absolute ${'n' === corner[0] ? 'top-0' : 'bottom-0'} ${'w' === corner[1] ? 'left-0' : 'right-0'} ${handleClass(corner)}"></span>`).join('')}
                    </div>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <button type="button" data-crop-reset class="h-8 px-3 rounded text-sm text-ink-soft hover:bg-hover hover:text-ink">${escapeHtml(this.t('imageCropReset', 'Whole picture'))}</button>
                    <div class="flex items-center gap-2">
                        <button type="button" data-crop-cancel class="h-8 px-3 rounded text-sm text-ink-soft hover:bg-hover hover:text-ink">${escapeHtml(this.t('imageCropCancel', 'Cancel'))}</button>
                        <button type="button" data-crop-apply class="h-8 px-3 rounded text-sm bg-accent text-accent-ink">${escapeHtml(this.t('imageCropApply', 'Crop'))}</button>
                    </div>
                </div>
            </div>`;

        const stage = dialog.querySelector('[data-crop-stage]');

        dialog.querySelector('[data-crop-frame]').addEventListener('pointerdown', (event) => {
            const corner = event.target instanceof HTMLElement ? event.target.dataset.cropHandle : undefined;

            this._dragCrop(event, stage, corner ?? null);
        });
        dialog.querySelector('[data-crop-reset]').addEventListener('click', () => {
            this._cropRect = [0, 0, 1, 1];
            this._cropDraw?.();
        });
        dialog.querySelector('[data-crop-cancel]').addEventListener('click', () => this._cropClose?.(false));
        dialog.querySelector('[data-crop-apply]').addEventListener('click', () => this._cropClose?.(true));
        dialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            this._cropClose?.(false);
        });

        document.body.append(dialog);
        this.dialog = dialog;

        return dialog;
    }

    /** Move the frame (corner null) or pull one corner, in fractions of the picture. */
    _dragCrop(event, stage, corner) {
        if (0 !== event.button) {
            return;
        }

        event.preventDefault();

        const box = stage.getBoundingClientRect();
        const start = [...this._cropRect];
        const startX = event.clientX;
        const startY = event.clientY;
        const min = Math.max(MIN_WIDTH / Math.max(1, box.width), MIN_WIDTH / Math.max(1, box.height));
        const move = (moveEvent) => {
            const dx = (moveEvent.clientX - startX) / Math.max(1, box.width);
            const dy = (moveEvent.clientY - startY) / Math.max(1, box.height);
            let [x, y, w, h] = start;

            if (null === corner) {
                x = Math.min(1 - w, Math.max(0, x + dx));
                y = Math.min(1 - h, Math.max(0, y + dy));
            } else {
                let left = x;
                let top = y;
                let right = x + w;
                let bottom = y + h;

                if ('w' === corner[1]) {
                    left = Math.min(right - min, Math.max(0, left + dx));
                } else {
                    right = Math.max(left + min, Math.min(1, right + dx));
                }

                if ('n' === corner[0]) {
                    top = Math.min(bottom - min, Math.max(0, top + dy));
                } else {
                    bottom = Math.max(top + min, Math.min(1, bottom + dy));
                }

                [x, y, w, h] = [left, top, right - left, bottom - top];
            }

            this._cropRect = [x, y, w, h];
            this._cropDraw?.();
        };

        followPointer(event, move, () => {});
    }
}

/**
 * One drag, from the press that starts it to the release that ends it.
 *
 * On the document rather than on the element pressed, and without pointer
 * capture: a release the element never hears — over another element in a
 * browser that does not honour the capture, outside the window, behind an
 * alt-tab — left the listeners in place, and from then on the crop frame
 * followed the mouse with no button down. Three ends, so none is missed: the
 * release, a cancel or the window losing focus, and any move that arrives
 * with no button pressed, which is a drag whose release went somewhere else.
 *
 * @param {PointerEvent} start
 * @param {(event: PointerEvent) => void} onMove
 * @param {() => void} onEnd
 */
function followPointer(start, onMove, onEnd) {
    const id = start.pointerId;
    let done = false;

    const finish = () => {
        if (true === done) {
            return;
        }

        done = true;
        document.removeEventListener('pointermove', move, true);
        document.removeEventListener('pointerup', release, true);
        document.removeEventListener('pointercancel', release, true);
        window.removeEventListener('blur', finish);
        onEnd();
    };

    const release = (event) => {
        if (event.pointerId === id) {
            finish();
        }
    };

    const move = (event) => {
        if (event.pointerId !== id) {
            return;
        }

        if (0 === event.buttons) {
            finish();

            return;
        }

        event.preventDefault();
        onMove(event);
    };

    document.addEventListener('pointermove', move, true);
    document.addEventListener('pointerup', release, true);
    document.addEventListener('pointercancel', release, true);
    window.addEventListener('blur', finish);
}

/**
 * The bar's widths, in CSS pixels. Fixed sizes rather than shares of the
 * window: the recipient's window is not this one, and "Medium" should look
 * the same in every mail. Large is about the width most mail clients give a
 * message; Full size takes the width away.
 */
export const SIZE_SMALL = 160;
export const SIZE_MEDIUM = 320;
export const SIZE_LARGE = 600;

/** What the crop dialog shows: big enough to aim, small enough to decode fast. */
const PREVIEW_CHOICE = Object.freeze({ size: 'custom', edge: 1600, quality: 85, restore: false, custom: true });

const ICON_ROTATE_LEFT = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>';
const ICON_ROTATE_RIGHT = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/></svg>';
const ICON_CROP = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 2v14a2 2 0 0 0 2 2h14"/><path d="M18 22V8a2 2 0 0 0-2-2H2"/></svg>';

/**
 * A corner handle, the same in the body and in the crop dialog: one thing to
 * grab, wherever it is. The surface-coloured ring keeps it visible on a dark
 * picture and on the crop dialog's dim alike.
 */
function handleClass(corner) {
    return 'size-3.5 rounded-full bg-accent border-2 border-surface shadow '
        + ('nw' === corner || 'se' === corner ? 'cursor-nwse-resize' : 'cursor-nesw-resize');
}

/** The nearest ancestor that scrolls vertically, or null for the page itself. */
function scrollParent(element) {
    for (let node = element.parentElement; null !== node; node = node.parentElement) {
        const overflow = getComputedStyle(node).overflowY;

        if ('auto' === overflow || 'scroll' === overflow) {
            return node;
        }
    }

    return null;
}

function escapeHtml(text) {
    const span = document.createElement('span');

    span.textContent = String(text);

    return span.innerHTML;
}
