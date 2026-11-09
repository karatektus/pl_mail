import { test, expect } from "@playwright/test";
import { imageDimensions } from "../../assets/compose/image_dimensions.js";

/**
 * Reading a picture's shown size from the first bytes of its file, on its own.
 *
 * No browser, like pdf-geometry.spec.ts: a pure function imported directly. The
 * files are built here byte by byte, small enough to read, with only the
 * structure the reader walks; the placeholder that uses the answer is checked
 * by hand in the compose window, against real camera files.
 */

const bytes = (...parts: (number[] | string)[]) =>
    Uint8Array.from(parts.flatMap((p) => ("string" === typeof p ? Array.from(p, (c) => c.charCodeAt(0)) : p)));

const be16 = (n: number) => [(n >> 8) & 0xff, n & 0xff];
const be32 = (n: number) => [(n >>> 24) & 0xff, (n >> 16) & 0xff, (n >> 8) & 0xff, n & 0xff];
const le16 = (n: number) => [n & 0xff, (n >> 8) & 0xff];
const le24 = (n: number) => [n & 0xff, (n >> 8) & 0xff, (n >> 16) & 0xff];
const le32 = (n: number) => [...le16(n & 0xffff), ...le16(n >>> 16)];

function png(width: number, height: number) {
    return bytes([0x89], "PNG\r\n\x1a\n", be32(13), "IHDR", be32(width), be32(height), [8, 2, 0, 0, 0]);
}

/** An APP1 segment holding IFD0 with one entry: the orientation. */
function exif(orientation: number, little: boolean) {
    const u16 = little ? le16 : be16;
    const u32 = little ? le32 : be32;
    const tiff = bytes(little ? "II" : "MM", u16(42), u32(8), u16(1), u16(0x0112), u16(3), u32(1), u16(orientation), [0, 0], u32(0));
    const payload = bytes("Exif\0\0", Array.from(tiff));

    return bytes([0xff, 0xe1], be16(payload.length + 2), Array.from(payload));
}

/** SOI, optional segments, a JFIF APP0, a quantisation table, then the frame. */
function jpeg(width: number, height: number, before: Uint8Array[] = [], sof = 0xc0) {
    return bytes(
        [0xff, 0xd8],
        ...before.map((segment) => Array.from(segment)),
        [0xff, 0xe0], be16(16), "JFIF\0", [1, 1, 0], be16(1), be16(1), [0, 0],
        [0xff, 0xdb], be16(3), [0],
        [0xff, sof], be16(17), [8], be16(height), be16(width), [3, 1, 0x22, 0, 2, 0x11, 1, 3, 0x11, 1],
        [0xff, 0xda], be16(2),
    );
}

test.describe("image dimensions", () => {
    test("PNG reads its header chunk", () => {
        expect(imageDimensions(png(1000, 750))).toEqual({ width: 1000, height: 750 });
    });

    test("GIF reads its screen size", () => {
        expect(imageDimensions(bytes("GIF89a", le16(320), le16(200), [0, 0, 0]))).toEqual({ width: 320, height: 200 });
    });

    test("WebP in all three of its forms", () => {
        const riff = (chunk: number[]) => bytes("RIFF", le32(chunk.length + 4), "WEBP", chunk);

        // Lossy: the frame tag, then the start code, then 14-bit sizes.
        expect(imageDimensions(riff([...bytes("VP8 ", le32(20), [0, 0, 0], [0x9d, 0x01, 0x2a], le16(640), le16(480), [0, 0])])))
            .toEqual({ width: 640, height: 480 });

        // Lossless: 14-bit sizes, less one, packed after the 0x2f signature.
        const w = 799;
        const h = 599;
        const packed = [w & 0xff, ((w >> 8) & 0x3f) | ((h & 0x03) << 6), (h >> 2) & 0xff, (h >> 10) & 0x0f];
        expect(imageDimensions(riff([...bytes("VP8L", le32(10), [0x2f], packed, [0, 0, 0, 0, 0])])))
            .toEqual({ width: 800, height: 600 });

        // Extended: 24-bit canvas size, less one.
        expect(imageDimensions(riff([...bytes("VP8X", le32(10), [0, 0, 0, 0], le24(1919), le24(1079))])))
            .toEqual({ width: 1920, height: 1080 });
    });

    test("JPEG reads its frame header, past the segments before it", () => {
        expect(imageDimensions(jpeg(4032, 3024))).toEqual({ width: 4032, height: 3024 });
    });

    /** A progressive JPEG has SOF2 rather than SOF0, and DHT (0xC4) is not a frame. */
    test("JPEG: a progressive frame counts, a Huffman table does not", () => {
        const dht = bytes([0xff, 0xc4], be16(3), [0]);

        expect(imageDimensions(jpeg(1200, 800, [dht], 0xc2))).toEqual({ width: 1200, height: 800 });
    });

    /**
     * The case the reader exists for: a phone photo stored landscape and turned
     * by its EXIF orientation. 6 and 8 are a quarter turn, 5 and 7 a quarter
     * turn mirrored; all four swap the sides. 3 is upside down and does not.
     */
    test("JPEG: an EXIF quarter turn swaps width and height, in either byte order", () => {
        for (const little of [true, false]) {
            for (const turn of [5, 6, 7, 8]) {
                expect(imageDimensions(jpeg(4032, 3024, [exif(turn, little)]))).toEqual({ width: 3024, height: 4032 });
            }

            expect(imageDimensions(jpeg(4032, 3024, [exif(3, little)]))).toEqual({ width: 4032, height: 3024 });
            expect(imageDimensions(jpeg(4032, 3024, [exif(1, little)]))).toEqual({ width: 4032, height: 3024 });
        }
    });

    /** A download is read as it comes in, so "not yet" must be an answer, not an exception. */
    test("too few bytes are no answer yet, at every length", () => {
        for (const file of [png(10, 10), jpeg(30, 20, [exif(6, true)])]) {
            const whole = imageDimensions(file);

            for (let n = 0; n < file.length; n++) {
                const answer = imageDimensions(file.subarray(0, n));

                // Either nothing yet, or already the right answer — never a
                // wrong size from a cut-off header.
                expect([null, JSON.stringify(whole)]).toContain(null === answer ? null : JSON.stringify(answer));
            }
        }
    });

    test("anything else is no answer", () => {
        expect(imageDimensions(bytes("%PDF-1.7\n"))).toBeNull();
        expect(imageDimensions(new Uint8Array(0))).toBeNull();
        expect(imageDimensions(bytes([0xff, 0xd8, 0x00, 0x00, 0x00, 0x00]))).toBeNull();
    });
});
