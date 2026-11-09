/**
 * The size a picture will be shown at, read from the first bytes of its file.
 *
 * The compose window puts a placeholder where a picture is going and wants it
 * the picture's shape before the picture is there (compose--compose
 * #_sizePlaceholder): from the disk the whole file is at hand, but one from a
 * file service arrives as a download, and every format here keeps its size in
 * the first few bytes — or, for JPEG, before the first scan, which in a camera
 * file is typically within the first 64 KB, behind the EXIF block and its
 * thumbnail.
 *
 * "Shown at" because a JPEG's EXIF orientation is applied by every browser
 * (`image-orientation: from-image` is the default), and most phone photos are
 * stored landscape with a note to turn them. Orientations 5–8 swap width and
 * height; reading only the frame would give a portrait photo a landscape box.
 * The same note in a PNG `eXIf` chunk or a WebP `EXIF` chunk is not read: both
 * are rare, and the caller measures the decoded picture once it has all of it.
 *
 * Returns null while the bytes do not (yet) say: too few of them, or not one
 * of PNG, GIF, WebP or JPEG. Never throws on a short or malformed buffer.
 *
 * @param {Uint8Array} bytes the start of the file, as much of it as there is
 * @returns {{width: number, height: number}|null}
 */
export function imageDimensions(bytes) {
    try {
        return png(bytes) ?? gif(bytes) ?? webp(bytes) ?? jpeg(bytes);
    } catch (_) {
        return null;
    }
}

/**
 * How many leading bytes are worth reading for imageDimensions(). A JPEG whose
 * frame header is further in than this — a huge embedded preview — is measured
 * from the decoded picture instead.
 */
export const IMAGE_HEAD_BYTES = 512 * 1024;

function dims(width, height) {
    return width > 0 && height > 0 ? { width, height } : null;
}

function startsWith(bytes, offset, ascii) {
    if (bytes.length < offset + ascii.length) {
        return false;
    }

    for (let i = 0; i < ascii.length; i++) {
        if (bytes[offset + i] !== ascii.charCodeAt(i)) {
            return false;
        }
    }

    return true;
}

const be16 = (b, i) => (b[i] << 8) | b[i + 1];
const le16 = (b, i) => b[i] | (b[i + 1] << 8);
const be32 = (b, i) => ((b[i] << 24) >>> 0) + ((b[i + 1] << 16) | (b[i + 2] << 8) | b[i + 3]);
const le24 = (b, i) => b[i] | (b[i + 1] << 8) | (b[i + 2] << 16);

/** IHDR is the first chunk, always: width and height at 16 and 20. */
function png(b) {
    if (b.length < 24 || 0x89 !== b[0] || false === startsWith(b, 1, 'PNG')) {
        return null;
    }

    return dims(be32(b, 16), be32(b, 20));
}

/** The logical screen size, which is what a browser draws. */
function gif(b) {
    if (b.length < 10 || false === startsWith(b, 0, 'GIF8')) {
        return null;
    }

    return dims(le16(b, 6), le16(b, 8));
}

/** Lossy (VP8), lossless (VP8L) and extended (VP8X) each keep it differently. */
function webp(b) {
    if (b.length < 30 || false === startsWith(b, 0, 'RIFF') || false === startsWith(b, 8, 'WEBP')) {
        return null;
    }

    if (startsWith(b, 12, 'VP8X')) {
        return dims(le24(b, 24) + 1, le24(b, 27) + 1);
    }

    if (startsWith(b, 12, 'VP8L')) {
        return dims(
            1 + (((b[22] & 0x3f) << 8) | b[21]),
            1 + (((b[24] & 0x0f) << 10) | (b[23] << 2) | ((b[22] & 0xc0) >> 6)),
        );
    }

    if (startsWith(b, 12, 'VP8 ')) {
        return dims(le16(b, 26) & 0x3fff, le16(b, 28) & 0x3fff);
    }

    return null;
}

/**
 * Walk the markers to the first frame header (SOF0–SOF15 bar DHT, JPG and
 * DAC, which share the range), noting the EXIF orientation on the way.
 */
function jpeg(b) {
    if (b.length < 4 || 0xff !== b[0] || 0xd8 !== b[1]) {
        return null;
    }

    let orientation = 1;
    let i = 2;

    while (i + 4 <= b.length) {
        if (0xff !== b[i]) {
            return null;
        }

        const marker = b[i + 1];

        // Fill bytes, and the markers that carry no length.
        if (0xff === marker) {
            i += 1;
            continue;
        }

        if (0x01 === marker || (marker >= 0xd0 && marker <= 0xd7)) {
            i += 2;
            continue;
        }

        // Start of scan or end of image before any frame header: nothing to
        // find in this file.
        if (0xda === marker || 0xd9 === marker) {
            return null;
        }

        const length = be16(b, i + 2);

        if (length < 2) {
            return null;
        }

        if (0xe1 === marker && i + 2 + length <= b.length && startsWith(b, i + 4, 'Exif\0\0')) {
            orientation = exifOrientation(b, i + 10, i + 2 + length) ?? orientation;
        }

        const isFrame = marker >= 0xc0 && marker <= 0xcf && 0xc4 !== marker && 0xc8 !== marker && 0xcc !== marker;

        if (true === isFrame) {
            if (i + 9 > b.length) {
                return null;
            }

            const height = be16(b, i + 5);
            const width = be16(b, i + 7);

            return orientation >= 5 && orientation <= 8 ? dims(height, width) : dims(width, height);
        }

        i += 2 + length;
    }

    return null;
}

/** Tag 0x0112 of IFD0 in the TIFF structure that starts at `tiff`. */
function exifOrientation(b, tiff, end) {
    if (tiff + 8 > end) {
        return null;
    }

    const little = startsWith(b, tiff, 'II');

    if (false === little && false === startsWith(b, tiff, 'MM')) {
        return null;
    }

    const u16 = (at) => (little ? le16(b, at) : be16(b, at));
    const u32 = (at) => (little
        ? (b[at] | (b[at + 1] << 8) | (b[at + 2] << 16)) + ((b[at + 3] << 24) >>> 0)
        : be32(b, at));

    const ifd = tiff + u32(tiff + 4);

    if (ifd + 2 > end) {
        return null;
    }

    const count = u16(ifd);

    for (let n = 0; n < count; n++) {
        const entry = ifd + 2 + n * 12;

        if (entry + 12 > end) {
            return null;
        }

        if (0x0112 === u16(entry)) {
            const value = u16(entry + 8);

            return value >= 1 && value <= 8 ? value : null;
        }
    }

    return null;
}
