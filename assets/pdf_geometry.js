/**
 * Where a stamp goes, in the only coordinates that survive.
 *
 * Pure functions, no DOM, no pdf.js and no pdf-lib — the two libraries speak
 * different coordinate systems and this is the translation between them. A
 * plain module beside motion.js, which is the house pattern for logic several
 * controllers need and none should own.
 *
 * THE GOVERNING RULE
 *
 * A placement is stored in PDF user-space points from the moment it is
 * committed, and the on-screen box is derived from that — never the reverse.
 * Screen coordinates depend on the zoom, the device pixel ratio, the window
 * width and the page's own rotation, all of which change while the reader is
 * open. User-space points depend on none of them.
 *
 * WHAT NOT TO HAND-ROLL
 *
 * `pageHeight - y` is the y-flip everybody writes and it is right only for the
 * PDFs you happened to test. pdf.js's `convertToPdfPoint()` inverts the flip,
 * the /Rotate and the viewBox offset in one step, because it is the inverse of
 * the very matrix the page was drawn with.
 *
 * That last part is worth stating plainly, because the obvious correction is
 * wrong: the viewport transform is built around the viewBox CENTRE (see
 * PageViewport in pdf.js), so `convertToPdfPoint()` already answers in ABSOLUTE
 * user space, origin included. pdf-lib's drawImage writes into that same
 * absolute default user space — a MediaBox of `[20 30 615 872]` selects which
 * rectangle of it is shown, it does not shift it. So the MediaBox origin is
 * subtracted nowhere here. Doing it "to be safe" is what puts the stamp an inch
 * off on exactly the scanned contract that matters, and the awkward fixture in
 * SamplePdf exists to keep that honest.
 */

/**
 * A point on screen, in PDF user-space points.
 *
 * `rect` is the canvas's own bounding box. Never assume it equals
 * `viewport.width`: browser zoom, a max-width, or a phone in landscape all
 * break that, and the symptom is a PROPORTIONAL drift toward one edge — the
 * most commonly reported bug of this whole class.
 *
 * Device pixel ratio does not appear here at all, and must not. It belongs to
 * the RENDER viewport — the backing store is drawn at 2× on a Retina display
 * while the CSS box stays the same size — so dividing by it here would place
 * every stamp at half scale.
 *
 * @param {{convertToPdfPoint: (x: number, y: number) => number[], width: number, height: number}} viewport
 * @param {{left: number, top: number, width: number, height: number}} rect
 * @returns {[number, number]}
 */
export function viewportToPdfPoint(viewport, rect, clientX, clientY) {
    const x = ((clientX - rect.left) * viewport.width) / rect.width;
    const y = ((clientY - rect.top) * viewport.height) / rect.height;

    const [pdfX, pdfY] = viewport.convertToPdfPoint(x, y);

    return [pdfX, pdfY];
}

/**
 * A box drawn on screen, as a rectangle in PDF user space.
 *
 * Both corners are converted rather than the origin plus a scaled size, which
 * is what keeps the scale factor out of this function entirely — there is no
 * `cssWidth / scale` to get wrong, and no second place for the ratio to differ
 * from the one convertToPdfPoint used.
 *
 * `width` and `height` come back in the STAMP's own frame, not the page's: they
 * are what the picture measures along its own edges, which on a page with
 * /Rotate 90 runs up the page rather than across it. stampAnchor() is what
 * reconciles the two.
 *
 * @param {{left: number, top: number, width: number, height: number}} box in client coordinates
 * @returns {{x: number, y: number, width: number, height: number}}
 */
export function pdfRect(viewport, rect, box) {
    const [ax, ay] = viewportToPdfPoint(viewport, rect, box.left, box.top);
    const [bx, by] = viewportToPdfPoint(viewport, rect, box.left + box.width, box.top + box.height);

    const rotation = normaliseRotation(viewport.rotation ?? 0);
    const spanX = Math.abs(bx - ax);
    const spanY = Math.abs(by - ay);

    // A quarter turn swaps which page axis the stamp's width runs along.
    const swapped = 90 === rotation || 270 === rotation;

    return {
        x: Math.min(ax, bx),
        y: Math.min(ay, by),
        width: swapped ? spanY : spanX,
        height: swapped ? spanX : spanY,
    };
}

/**
 * Where pdf-lib has to be told to put the image, and how far to turn it.
 *
 * A page with /Rotate is DISPLAYED turned, so a stamp that is upright on screen
 * has to be drawn turned the other way to come out upright — that much is
 * expected. What catches people is that pdf-lib rotates about (x, y), the
 * image's lower-left corner, and not about its centre. So the corner it is
 * given is not the corner of the box the reader saw, and the offset differs per
 * quarter turn.
 *
 * Worked through, with W and H the stamp's own width and height, and the
 * on-page bounding box starting at (x, y):
 *
 * |  /Rotate | drawn at            | turned |
 * |----------|---------------------|--------|
 * |        0 | (x, y)              |    0°  |
 * |       90 | (x, y + W)          |  -90°  |
 * |      180 | (x + W, y + H)      | -180°  |
 * |      270 | (x + H, y)          | -270°  |
 *
 * @param {{x: number, y: number, width: number, height: number}} rect from pdfRect()
 * @returns {{x: number, y: number, width: number, height: number, rotate: number}}
 */
export function stampAnchor(rect, rotation) {
    const turn = normaliseRotation(rotation);
    const { x, y, width, height } = rect;

    const anchors = {
        0: [x, y],
        90: [x, y + width],
        180: [x + width, y + height],
        270: [x + height, y],
    };

    const [anchorX, anchorY] = anchors[turn];

    // `|| 0` so that no rotation is 0 rather than -0, which prints as "-0"
    // in a failure message and reads as a bug that is not there.
    return { x: anchorX, y: anchorY, width, height, rotate: -turn || 0 };
}

/**
 * The bounds of what was actually drawn, in an ink canvas mostly empty.
 *
 * A signature drawn across a wide canvas is a small mark in a large transparent
 * field. Stamped untrimmed it carries all that emptiness, and the mark lands
 * wherever inside the placement box it happened to be drawn rather than where
 * the box was put.
 *
 * @param {{data: Uint8ClampedArray, width: number, height: number}} image from getImageData()
 * @returns {{left: number, top: number, width: number, height: number}|null} null when nothing was drawn
 */
export function trimAlpha(image, threshold = 8) {
    let left = image.width;
    let top = image.height;
    let right = -1;
    let bottom = -1;

    for (let y = 0; y < image.height; y += 1) {
        for (let x = 0; x < image.width; x += 1) {
            if (image.data[(y * image.width + x) * 4 + 3] < threshold) {
                continue;
            }

            if (x < left) left = x;
            if (x > right) right = x;
            if (y < top) top = y;
            if (y > bottom) bottom = y;
        }
    }

    if (right < 0) {
        return null;
    }

    return { left, top, width: right - left + 1, height: bottom - top + 1 };
}

/** /Rotate is a multiple of 90 but may be negative or over 360. */
function normaliseRotation(rotation) {
    const turn = ((Math.round(rotation / 90) * 90) % 360 + 360) % 360;

    return turn;
}
