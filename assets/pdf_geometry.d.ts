/**
 * Types for assets/pdf_geometry.js, so the specs that import it are checked.
 *
 * A declaration file rather than `allowJs`, which would pull every controller
 * in assets/ into the TypeScript run — those are plain JavaScript by choice and
 * are exercised through the browser, not the compiler.
 *
 * This is the contract, and it is worth reading as one: everything here is
 * expressed in PDF user-space points except `Box`, which is in client pixels.
 * The module's own docblock says why that direction is the only one allowed.
 */

/** A rectangle on screen, in client coordinates. */
export interface Box {
    left: number;
    top: number;
    width: number;
    height: number;
}

/** As much of pdf.js's PageViewport as any of this needs. */
export interface Viewport {
    width: number;
    height: number;
    rotation?: number;
    convertToPdfPoint(x: number, y: number): number[];
}

/** A rectangle in PDF user space; width and height are the stamp's own. */
export interface PdfRect {
    x: number;
    y: number;
    width: number;
    height: number;
}

/** A PdfRect moved to the corner pdf-lib rotates about, and by how much. */
export interface Anchor extends PdfRect {
    rotate: number;
}

export function viewportToPdfPoint(
    viewport: Viewport,
    rect: Box,
    clientX: number,
    clientY: number,
): [number, number];

export function pdfRect(viewport: Viewport, rect: Box, box: Box): PdfRect;

export function stampAnchor(rect: PdfRect, rotation: number): Anchor;

export function trimAlpha(
    image: { data: Uint8ClampedArray; width: number; height: number },
    threshold?: number,
): Box | null;
