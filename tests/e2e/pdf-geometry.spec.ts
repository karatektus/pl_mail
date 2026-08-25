import { test, expect } from "@playwright/test";
import { pdfRect, stampAnchor, trimAlpha, viewportToPdfPoint } from "../../assets/pdf_geometry.js";

/**
 * The coordinate arithmetic behind signing, on its own.
 *
 * No browser: these are pure functions and this file imports them directly, so
 * a failure names the case rather than a screenshot difference. The counterpart
 * is pdf-sign.spec.ts, which signs the awkward fixture for real and compares
 * the result — this says the maths is right, that says it is WIRED right, and
 * neither is worth much without the other.
 *
 * `viewport` here is the smallest thing that behaves like pdf.js's PageViewport:
 * a flip about the page height, which is the transform for an unrotated page
 * whose viewBox starts at the origin. The interesting cases are about what the
 * caller does AROUND convertToPdfPoint, so the real matrix would only obscure
 * them; the awkward fixture is where the real one is exercised.
 */
function viewport(rotation = 0, height = 200, width = 100) {
    return {
        width,
        height,
        rotation,
        convertToPdfPoint: (x: number, y: number) => [x, height - y],
    };
}

const FULL = { left: 0, top: 0, width: 100, height: 200 };

test.describe("pdf geometry", () => {
    test("a point on screen becomes a point in the page", () => {
        expect(viewportToPdfPoint(viewport(), FULL, 10, 20)).toEqual([10, 180]);
    });

    /**
     * Pitfall 2, and the reason both corners are converted rather than one
     * corner plus a scaled size. A canvas displayed at half its viewport width
     * must not halve where things land — the symptom is a drift that grows
     * toward one edge, which reads as "roughly right" until it is not.
     */
    test("a canvas shown smaller than its viewport still places a point correctly", () => {
        const shown = { left: 0, top: 0, width: 50, height: 100 };

        expect(viewportToPdfPoint(viewport(), shown, 5, 10)).toEqual([10, 180]);
    });

    /** And the offset of the canvas within the window is not part of the page. */
    test("the canvas position on the page is subtracted", () => {
        const offset = { left: 300, top: 40, width: 100, height: 200 };

        expect(viewportToPdfPoint(viewport(), offset, 310, 60)).toEqual([10, 180]);
    });

    test("a box drawn on screen becomes a rectangle in page units", () => {
        expect(pdfRect(viewport(), FULL, { left: 10, top: 20, width: 30, height: 40 })).toEqual({
            x: 10,
            y: 140,
            width: 30,
            height: 40,
        });
    });

    /**
     * On a quarter-turned page the stamp's own width runs UP the page. Getting
     * this backwards makes a signature that is the right size and the wrong
     * shape, which is easy to miss on a square-ish stamp.
     */
    test("a quarter turn swaps which page axis the stamp's width runs along", () => {
        const rect = pdfRect(viewport(90), FULL, { left: 10, top: 20, width: 30, height: 40 });

        expect(rect.width).toBe(40);
        expect(rect.height).toBe(30);
    });

    /**
     * Pitfall 5. pdf-lib turns the image about (x, y) — its lower-left corner,
     * not its centre — so the corner it is handed is not the corner of the box
     * the reader saw, and the correction differs per quarter turn. All four,
     * because three of them being right is the failure mode.
     */
    test("the anchor is moved to the corner pdf-lib will rotate about", () => {
        const rect = { x: 10, y: 20, width: 30, height: 40 };

        expect(stampAnchor(rect, 0)).toMatchObject({ x: 10, y: 20, rotate: 0 });
        expect(stampAnchor(rect, 90)).toMatchObject({ x: 10, y: 50, rotate: -90 });
        expect(stampAnchor(rect, 180)).toMatchObject({ x: 40, y: 60, rotate: -180 });
        expect(stampAnchor(rect, 270)).toMatchObject({ x: 50, y: 20, rotate: -270 });
    });

    test("the stamp keeps its own size whichever way the page is turned", () => {
        for (const rotation of [0, 90, 180, 270]) {
            const anchored = stampAnchor({ x: 10, y: 20, width: 30, height: 40 }, rotation);

            expect(anchored, `rotation ${rotation}`).toMatchObject({ width: 30, height: 40 });
        }
    });

    /** /Rotate is a multiple of 90, but nothing says it is in [0, 360). */
    test("a rotation outside the usual range is normalised", () => {
        const rect = { x: 10, y: 20, width: 30, height: 40 };

        expect(stampAnchor(rect, -90)).toEqual(stampAnchor(rect, 270));
        expect(stampAnchor(rect, 450)).toEqual(stampAnchor(rect, 90));
    });

    test("ink is trimmed to what was actually drawn", () => {
        const image = { width: 4, height: 4, data: new Uint8ClampedArray(4 * 4 * 4) };
        const paint = (x: number, y: number) => {
            image.data[(y * 4 + x) * 4 + 3] = 255;
        };

        paint(1, 2);
        paint(2, 2);

        expect(trimAlpha(image)).toEqual({ left: 1, top: 2, width: 2, height: 1 });
    });

    test("an untouched canvas trims to nothing rather than to a dot", () => {
        expect(trimAlpha({ width: 4, height: 4, data: new Uint8ClampedArray(4 * 4 * 4) })).toBeNull();
    });
});
