/**
 * What the person did to one picture in the body, and the pixels that makes
 * of its original (#515).
 *
 * WHERE AN EDIT LIVES
 * ───────────────────
 * On the <img> itself, as three attributes:
 *
 *   width           the size it is shown at, in CSS pixels; absent is "as big
 *                   as it is", capped by the editor's max-width:100%
 *   data-pl-rotate  90, 180 or 270: quarter turns clockwise; absent is none
 *   data-pl-crop    "x,y,w,h": the part kept, as fractions of the picture
 *                   AFTER rotating; absent is all of it
 *   data-pl-size    small, medium, large or original: how many pixels this
 *                   picture goes out with, the bar's Small … Full size;
 *                   absent is the draft's Image size row
 *
 * Not on the server's MessagePart, for two reasons. A sealed draft has no
 * part for a picture in its body — the picture is a data: URI the server never
 * reads — and both kinds of picture must edit alike. And on the element the
 * edit is part of the body, so the editor's own undo takes it back: replacing
 * the <img> through execCommand puts the old one, attributes and all, on the
 * undo stack (see InlineImageEditor#replace), and an edit kept anywhere else
 * would be one undo could not reach.
 *
 * The attributes are a recipe, not a record of what was done to the bytes.
 * The bytes the mail carries are always made from the ORIGINAL — which the
 * server keeps beside the copy for an uploaded picture (#502), and a sealed
 * body keeps in data-plmail-original — by applying all of it at once. So a
 * crop can be widened again, a picture made small can be made big again
 * without going soft, and a second rotation does not re-encode a JPEG twice.
 *
 * WHAT CHANGES THE BYTES
 * ──────────────────────
 * Crop and rotate change the picture itself, so they are in the bytes by
 * nature: a crop that only hid the rest with CSS would send the rest along,
 * to everyone who opens the mail in a client that ignores the CSS, and to
 * everyone who saves the picture.
 *
 * The width it is shown at does not. Dragging a picture smaller is a layout
 * choice; the pixels it goes out with are a choice of their own — the
 * picture's Small … Full size (data-pl-size), else the draft's Image size row
 * (#502). Two settings, kept apart. It used to cap the bytes too, at twice
 * the width shown, so every drag re-encoded and re-uploaded the picture and
 * a picture dragged small and then sent kept none of its detail for the
 * recipient who opens it whole.
 */

/** The formats a picture can be re-encoded as itself in every browser. */
export const EDITABLE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

/**
 * The quality JPEG and WebP are written at when an edit needs new bytes and
 * the draft's Image size is Original, which has no quality of its own. High
 * enough that a crop does not look worse than the photo it came from.
 */
export const EDIT_QUALITY = 92;

/** The draft-wide Image size choice that means "leave the pictures alone". */
export const ORIGINAL_CHOICE = Object.freeze({ size: 'original', edge: 0, quality: 100, restore: true, custom: false });

/** Smallest a picture can be dragged down to: still something to grab. */
export const MIN_WIDTH = 24;

/**
 * @typedef {object} ImageEdits
 * @property {number} rotate        0, 90, 180 or 270
 * @property {?number[]} crop       [x, y, w, h] fractions, or null for all of it
 * @property {number} displayWidth  CSS pixels, or 0 for natural size
 * @property {string} size          '' (the draft's), or one of PICTURE_SIZES
 */

/**
 * A picture's own file size, the same presets as the draft's Image size row
 * (App\Domain\Enum\Mail\ImageSize::edge() and PRESET_QUALITY — kept in step by
 * hand): the longest side in pixels, 0 for the picture as uploaded.
 */
export const PICTURE_SIZES = Object.freeze({ small: 800, medium: 1280, large: 2048, original: 0 });

const PRESET_QUALITY = 85;

/**
 * The Image size choice one picture goes out with: its own where it has one,
 * else the draft's.
 */
export function pictureChoice(edits, choice) {
    if (undefined === edits.size || '' === edits.size || false === Object.hasOwn(PICTURE_SIZES, edits.size)) {
        return choice;
    }

    if ('original' === edits.size) {
        return ORIGINAL_CHOICE;
    }

    return Object.freeze({ size: edits.size, edge: PICTURE_SIZES[edits.size], quality: PRESET_QUALITY, restore: false, custom: false });
}

/** @returns {ImageEdits} */
export function noEdits() {
    return { rotate: 0, crop: null, displayWidth: 0, size: '' };
}

/**
 * The recipe an <img> carries. Anything malformed reads as "not edited" —
 * the attributes come back from a stored draft, and a draft must open
 * whatever is in it.
 *
 * @param {HTMLImageElement} image
 * @returns {ImageEdits}
 */
export function readEdits(image) {
    const rotate = Number(image.getAttribute('data-pl-rotate')) || 0;
    const width = Number.parseInt(image.getAttribute('width') ?? '', 10);
    const size = image.getAttribute('data-pl-size') ?? '';

    return {
        rotate: [90, 180, 270].includes(rotate) ? rotate : 0,
        crop: parseCrop(image.getAttribute('data-pl-crop')),
        displayWidth: Number.isFinite(width) && width > 0 ? width : 0,
        size: Object.hasOwn(PICTURE_SIZES, size) ? size : '',
    };
}

/**
 * Write a recipe onto an <img>: the attributes, and the style that makes a
 * recipient's client show it at that width. `height:auto` because a crop or
 * a turn changes the shape, and a height carried over would stretch it.
 *
 * @param {HTMLImageElement} image
 * @param {ImageEdits} edits
 */
export function writeEdits(image, edits) {
    if (edits.rotate > 0) {
        image.setAttribute('data-pl-rotate', String(edits.rotate));
    } else {
        image.removeAttribute('data-pl-rotate');
    }

    if (null !== edits.crop) {
        image.setAttribute('data-pl-crop', edits.crop.map((n) => String(Math.round(n * 10000) / 10000)).join(','));
    } else {
        image.removeAttribute('data-pl-crop');
    }

    if (Object.hasOwn(PICTURE_SIZES, edits.size ?? '')) {
        image.setAttribute('data-pl-size', edits.size);
    } else {
        image.removeAttribute('data-pl-size');
    }

    image.removeAttribute('height');

    if (edits.displayWidth > 0) {
        const width = Math.round(edits.displayWidth);

        image.setAttribute('width', String(width));
        image.setAttribute('style', `width:${width}px;max-width:100%;height:auto`);
    } else {
        image.removeAttribute('width');
        image.setAttribute('style', 'max-width:100%');
    }
}

/**
 * Whether the recipe asks for other pixels than the picture came with: a
 * turn, a crop, or a file size of its own short of the original. The width
 * it is shown at is not one (see above).
 */
export function hasEdits(edits) {
    return edits.rotate > 0 || null !== edits.crop || (PICTURE_SIZES[edits.size] ?? 0) > 0;
}

/** A string that changes whenever the bytes the recipe makes would: not with the width. */
export function editSignature(edits) {
    return `${edits.rotate}|${null === edits.crop ? '' : edits.crop.join(',')}|${edits.size ?? ''}`;
}

/**
 * A quarter turn of the whole recipe. The crop turns with the picture — it
 * is a frame on the picture, not on the screen — and a picture shown at a
 * width keeps its height as its new width, so a turn changes its shape on
 * the page, not its size.
 *
 * @param {ImageEdits} edits
 * @param {1|-1} direction 1 clockwise, -1 counter-clockwise
 * @param {number} aspect the shown picture's width / height before the turn
 * @returns {ImageEdits}
 */
export function rotateEdits(edits, direction, aspect) {
    const rotate = (edits.rotate + (1 === direction ? 90 : 270)) % 360;
    let crop = edits.crop;

    if (null !== crop) {
        const [x, y, w, h] = crop;

        crop = 1 === direction ? [1 - (y + h), x, h, w] : [y, 1 - (x + w), h, w];
    }

    return {
        rotate,
        crop,
        displayWidth: edits.displayWidth > 0 && aspect > 0 ? Math.max(MIN_WIDTH, Math.round(edits.displayWidth / aspect)) : edits.displayWidth,
        size: edits.size,
    };
}

/**
 * A new crop, chosen on the whole turned picture. A frame that keeps (all
 * but a sliver of) everything is no crop: the attribute goes rather than
 * holding "0,0,1,1" forever. A picture shown at a width keeps its scale —
 * cropping takes something away, it does not zoom in on the rest.
 *
 * @param {ImageEdits} edits
 * @param {number[]} frame [x, y, w, h] fractions of the turned picture
 * @returns {ImageEdits}
 */
export function cropEdits(edits, frame) {
    const [x, y, w, h] = frame.map((n) => Math.min(1, Math.max(0, n)));
    const whole = x <= 0.002 && y <= 0.002 && w >= 0.996 && h >= 0.996;
    const crop = true === whole ? null : [x, y, Math.min(w, 1 - x), Math.min(h, 1 - y)];
    const before = null === edits.crop ? 1 : edits.crop[2];
    const after = null === crop ? 1 : crop[2];

    return {
        rotate: edits.rotate,
        crop,
        displayWidth: edits.displayWidth > 0 ? Math.max(MIN_WIDTH, Math.round(edits.displayWidth * after / before)) : 0,
        size: edits.size,
    };
}

/**
 * The bytes a picture goes out as: its original turned, cropped, and brought
 * down to its own file size, else the draft's Image size (pictureChoice) — or
 * null when the original itself is what should go. The width it is shown at
 * plays no part.
 *
 * An Image size preset leaves a picture already within its size alone, as
 * #502 always did: Medium on a small screenshot should not touch it. Custom
 * recompresses it anyway, because a quality the person set on purpose is the
 * point. A PNG that keeps its size is left alone either way: it is lossless.
 * createImageBitmap turns a phone photo the right way up as it decodes it,
 * and drawing onto a canvas keeps nothing but pixels, so the photo's
 * metadata, its GPS position included, stays behind.
 *
 * Null for a format a browser cannot write back as itself (GIF, SVG, HEIC
 * outside Safari): those cannot be edited, and InlineImageEditor offers them
 * no tools. Throws when an edit was asked for and the browser cannot carry
 * it out, so the caller can say so rather than send the uncropped picture.
 *
 * @param {Blob} blob the original
 * @param {ImageEdits} edits
 * @param {{edge: number, quality: number, restore: boolean, custom: boolean}} choice the draft's Image size (#502)
 * @param {{force?: boolean}} [options] force: answer bytes even where the original would do (previews)
 * @returns {Promise<?Blob>}
 */
export async function renderImage(blob, edits, draftChoice, options = {}) {
    const type = (blob.type || '').toLowerCase();
    const choice = pictureChoice(edits, draftChoice);

    if (false === EDITABLE_TYPES.includes(type)) {
        return null;
    }

    const geometric = edits.rotate > 0 || null !== edits.crop;
    const force = true === options.force;
    let bitmap;

    try {
        // from-image: a phone photo stored on its side comes out the way it
        // was taken, and the turn the person adds is on top of that.
        bitmap = await createImageBitmap(blob, { imageOrientation: 'from-image' });
    } catch (error) {
        if (true === geometric) {
            throw error;
        }

        return null;
    }

    try {
        const turned = 90 === edits.rotate || 270 === edits.rotate;
        const rw = true === turned ? bitmap.height : bitmap.width;
        const rh = true === turned ? bitmap.width : bitmap.height;
        const [cx, cy, cw, ch] = edits.crop ?? [0, 0, 1, 1];
        const sx = Math.round(cx * rw);
        const sy = Math.round(cy * rh);
        const sw = Math.max(1, Math.min(rw - sx, Math.round(cw * rw)));
        const sh = Math.max(1, Math.min(rh - sy, Math.round(ch * rh)));
        const longest = Math.max(sw, sh);
        let target = longest;

        if (choice.edge > 0) {
            target = Math.min(target, choice.edge);
        }

        const resize = target < longest;

        // Nothing to turn, cut or shrink: the original goes, unless the
        // draft's Custom size asks for every JPEG at another quality (#502).
        if (false === geometric && false === resize && false === force
            && (true === choice.restore || false === choice.custom || 'image/png' === type)) {
            return null;
        }

        const scale = target / longest;
        const width = Math.max(1, Math.round(sw * scale));
        const height = Math.max(1, Math.round(sh * scale));
        const quality = 'image/png' === type
            ? undefined
            : Math.min(1, Math.max(0.01, (true === choice.restore ? EDIT_QUALITY : choice.quality) / 100));

        const draw = (context) => {
            context.imageSmoothingQuality = 'high';
            context.scale(scale, scale);
            context.translate(-sx, -sy);

            // Into the turned picture's own space: its top-left is where the
            // original's corner lands after the turn.
            if (90 === edits.rotate) {
                context.translate(rw, 0);
            } else if (180 === edits.rotate) {
                context.translate(rw, rh);
            } else if (270 === edits.rotate) {
                context.translate(0, rh);
            }

            context.rotate(edits.rotate * Math.PI / 180);
            context.drawImage(bitmap, 0, 0);
        };

        let out;

        if ('function' === typeof OffscreenCanvas) {
            const canvas = new OffscreenCanvas(width, height);

            draw(canvas.getContext('2d'));
            out = await canvas.convertToBlob({ type, quality });
        } else {
            const canvas = document.createElement('canvas');

            canvas.width = width;
            canvas.height = height;
            draw(canvas.getContext('2d'));
            out = await new Promise((resolve) => canvas.toBlob(resolve, type, quality));
        }

        // A browser that cannot write the format writes PNG instead, and the
        // server will not take a PNG in place of a JPEG part: the picture
        // stays as it was rather than change type behind the person's back.
        if (null === out || type !== out.type) {
            if (true === geometric) {
                throw new Error('encode');
            }

            return null;
        }

        // Only shrinking can end up larger than it started (an already small
        // JPEG written again at a higher quality); a crop or a turn is kept
        // whatever it weighs, because it is what the person asked for.
        if (false === geometric && false === force && out.size >= blob.size) {
            return null;
        }

        return out;
    } finally {
        bitmap.close();
    }
}

function parseCrop(value) {
    if (null === value || '' === value) {
        return null;
    }

    const parts = value.split(',').map(Number);

    if (4 !== parts.length || parts.some((n) => false === Number.isFinite(n) || n < 0 || n > 1)) {
        return null;
    }

    const [x, y, w, h] = parts;

    if (w <= 0 || h <= 0 || x + w > 1.0001 || y + h > 1.0001) {
        return null;
    }

    return parts;
}
