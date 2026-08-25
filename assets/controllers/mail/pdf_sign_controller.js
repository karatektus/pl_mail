import { Controller } from "@hotwired/stimulus";
import { pdfRect, stampAnchor, trimAlpha } from "../../pdf_geometry.js";

/**
 * Signing a PDF by drawing on it, and replying with the signed copy.
 *
 * WHAT THIS IS NOT
 *
 * A visual signature: a picture of a name, stamped onto a page. It is not
 * PAdES, it carries no certificate, and it proves nothing about whether the
 * document was altered afterwards. Nothing in the interface may claim
 * otherwise — see the translations, which are careful about this.
 *
 * WHY IT IS A SEPARATE CONTROLLER
 *
 * It rides on the same element as mail--pdf-viewer and talks to it through the
 * handful of methods that controller marks as the signing layer's surface.
 * Reading a PDF is the feature most people will ever use, and it stays whole
 * and testable without any of this.
 *
 * THE ORDER OF OPERATIONS, WHICH IS THE WHOLE DESIGN
 *
 *   draw → trim → place → flatten → reply
 *
 * The ink is drawn on its own canvas at four times the size it is shown at,
 * trimmed to what was actually drawn, and becomes a PNG. From then on it is an
 * IMAGE, never a set of strokes — which is what lets a saved signature (stage
 * three) be placed by exactly the same code as one drawn a moment ago.
 *
 * Placement is stored in PDF user-space points from the moment it is committed.
 * assets/pdf_geometry.js holds that arithmetic and says at length why.
 *
 * WHY pdf-lib IS IMPORTED BY NAME AND pdf.js IS NOT
 *
 * `import("@cantoo/pdf-lib")` is a literal, which AssetMapper resolves through
 * the importmap — the house mechanism, used here because it fits. pdf.js could
 * not use it: that library needs stable DIRECTORY prefixes for its cmaps and
 * fonts, and AssetMapper digests every filename it compiles, so it is vendored
 * under public/ and imported from a variable instead. The split is deliberate
 * and this is the whole of the reason for it.
 *
 * Still lazy either way: a dynamic import is not preloaded, and nobody who
 * merely reads a PDF should pay to parse the library for writing on one.
 *
 * | Values     |                                                             |
 * |------------|-------------------------------------------------------------|
 * | action     | where the signed copy is posted                              |
 * | token      | the CSRF token for that post                                 |
 * | frame      | the turbo-frame the compose window should land in            |
 * | filename   | what the signed copy is called, for the download only        |
 * | encryptedText | why signing is unavailable on a protected document       |
 * | unreadableText | and why it is unavailable on one that will not parse    |
 * | savedUrl   | the saved signature, when there is one — empty when not      |
 */
export default class extends Controller {
    static targets = ["pad", "ink", "stamp", "sheet", "reason", "apply", "form", "file", "frameField"];
    static values = {
        action: String,
        token: String,
        frame: String,
        filename: String,
        encryptedText: String,
        unreadableText: String,
        savedUrl: String,
    };

    /**
     * The ink canvas is backed at four times the size it is displayed at.
     *
     * Not a nicety. A stamp captured at 1× looks right on screen and prints as
     * a blurred smear, because the page it lands on is rendered at whatever
     * resolution the reader chooses — and the one document people most want to
     * sign is the one they are going to print.
     */
    static SUPERSAMPLE = 4;

    connect() {
        this._stamp = null;
        this._placement = null;
        this._drawing = false;
        this._busy = false;
    }

    disconnect() {
        this._sizes?.disconnect();
        this._releaseDownload();
    }

    // ── The pad ───────────────────────────────────────────────────────────

    /** Open the drawing pad, unless the document refuses to be written on. */
    async open(event) {
        event?.preventDefault();

        const refusal = await this.#refusal();

        if (null !== refusal) {
            this.reasonTarget.textContent = refusal;
            this.reasonTarget.hidden = false;

            return;
        }

        this.reasonTarget.hidden = true;
        this.padTarget.hidden = false;

        this.#prepareInk();
    }

    close(event) {
        event?.preventDefault();
        this.padTarget.hidden = true;
    }

    startStroke(event) {
        event.preventDefault();

        this._drawing = true;
        this.inkTarget.setPointerCapture?.(event.pointerId);

        const context = this.inkTarget.getContext("2d");

        context.beginPath();
        context.moveTo(...this.#inkPoint(event));
    }

    /**
     * `pointermove` carries coalesced events on a fast stroke.
     *
     * A quick signature moves further between frames than between input
     * samples, and a line drawn from frame to frame is visibly polygonal. The
     * coalesced list is what the pointer actually did.
     */
    stroke(event) {
        if (false === this._drawing) {
            return;
        }

        event.preventDefault();

        const context = this.inkTarget.getContext("2d");

        for (const point of event.getCoalescedEvents?.() ?? [event]) {
            context.lineTo(...this.#inkPoint(point));
        }

        context.stroke();
    }

    endStroke(event) {
        if (false === this._drawing) {
            return;
        }

        this._drawing = false;
        this.inkTarget.releasePointerCapture?.(event.pointerId);
        this.applyTarget.disabled = false;
    }

    clear(event) {
        event?.preventDefault();

        this.#prepareInk();
        this.applyTarget.disabled = true;
    }

    /**
     * Place the signature saved in Settings, without drawing anything.
     *
     * Goes through exactly the same path as a fresh scribble, because a stamp
     * is an IMAGE from the moment it is captured rather than a set of strokes.
     * That was the point of expressing it that way: this method is six lines
     * instead of a second placement implementation to keep in step.
     */
    async useSaved(event) {
        event?.preventDefault();

        const refusal = await this.#refusal();

        if (null !== refusal) {
            this.reasonTarget.textContent = refusal;
            this.reasonTarget.hidden = false;

            return;
        }

        const response = await fetch(this.savedUrlValue, { credentials: "same-origin" });

        if (false === response.ok) {
            return;
        }

        const blob = await response.blob();
        const bitmap = await createImageBitmap(blob);

        this._stamp = {
            bytes: await blob.arrayBuffer(),
            url: URL.createObjectURL(blob),
            ratio: bitmap.width / bitmap.height,
        };

        bitmap.close();
        this.reasonTarget.hidden = true;
        this.padTarget.hidden = true;

        this.#placeOnCurrentPage();
    }

    /** Turn the ink into a stamp and put it on the page to be positioned. */
    async apply(event) {
        event?.preventDefault();

        const stamp = await this.#captureInk();

        if (null === stamp) {
            return;
        }

        this._stamp = stamp;
        this.padTarget.hidden = true;

        this.#placeOnCurrentPage();
    }

    // ── Placing it ────────────────────────────────────────────────────────

    /**
     * Dropped onto the middle of the page being read, at a sixth of its width.
     *
     * Placed rather than waiting to be dragged in from somewhere: a stamp that
     * exists but is nowhere is a state with no affordance, and the first thing
     * anyone does is drag it in anyway.
     *
     * WHY THE PLACEMENT IS FRACTIONS OF THE PAGE
     *
     * Not pixels, and not client coordinates. The stamp belongs to the page it
     * was put on, so it has to survive the two things that move a page under
     * it: scrolling the reader, and zooming it. Fractions of the canvas box
     * survive both, and the box is re-measured every time the stamp is drawn.
     *
     * A `position: fixed` stamp in client coordinates would have been simpler
     * and wrong twice — it would sit still while the document scrolled, and
     * fixed does not escape a clipping ancestor with a backdrop-filter, which
     * is exactly what the modal panel is.
     */
    #placeOnCurrentPage() {
        const slot = this.viewer?.currentPage();

        if (null === slot || undefined === slot) {
            return;
        }

        const width = 1 / 6;
        const height = (width * slot.canvas.offsetWidth) / this._stamp.ratio / slot.canvas.offsetHeight;

        this._placement = {
            page: slot.number,
            x: (1 - width) / 2,
            y: (1 - height) / 2,
            width,
        };

        this.#watchSize(slot.canvas);
        this.#drawStamp();
    }

    /**
     * Redraw the stamp when the page under it changes size.
     *
     * Zooming rebuilds every canvas at a new scale. The placement is fractions
     * of the page and so survives that, but the box on screen is derived from
     * the canvas's current size and has to be derived again — otherwise the
     * stamp keeps the old page's dimensions and drifts off the document.
     */
    #watchSize(canvas) {
        this._sizes?.disconnect();
        this._sizes = new ResizeObserver(() => this.#drawStamp());
        this._sizes.observe(canvas);
    }

    /** The stamp's box in the reader, from the placement and the page's size. */
    #box(canvas) {
        const width = this._placement.width * canvas.offsetWidth;

        return {
            left: canvas.offsetLeft + this._placement.x * canvas.offsetWidth,
            top: canvas.offsetTop + this._placement.y * canvas.offsetHeight,
            width,
            height: width / this._stamp.ratio,
        };
    }

    #drawStamp() {
        const slot = this.#slot();

        if (null === slot) {
            return;
        }

        const box = this.#box(slot.canvas);
        const stamp = this.stampTarget;

        stamp.hidden = false;
        stamp.style.left = `${box.left}px`;
        stamp.style.top = `${box.top}px`;
        stamp.style.width = `${box.width}px`;
        stamp.style.height = `${box.height}px`;
        stamp.style.backgroundImage = `url(${this._stamp.url})`;

        this.sheetTarget.hidden = false;
    }

    /** The page the stamp was put on, which is not always the one on screen. */
    #slot() {
        return this.viewer?.pageAt(this._placement?.page) ?? null;
    }

    grab(event) {
        event.preventDefault();

        const slot = this.#slot();

        if (null === slot) {
            return;
        }

        this._grab = {
            resize: null !== event.target.closest("[data-resize]"),
            x: event.clientX,
            y: event.clientY,
            from: { ...this._placement },
            canvas: { width: slot.canvas.offsetWidth, height: slot.canvas.offsetHeight },
        };

        this.stampTarget.setPointerCapture?.(event.pointerId);
    }

    drag(event) {
        if (undefined === this._grab || null === this._grab) {
            return;
        }

        event.preventDefault();

        const grab = this._grab;

        if (true === grab.resize) {
            // A floor, because a stamp dragged down to nothing cannot be
            // dragged back — there is no handle left to take hold of.
            const width = grab.from.width + (event.clientX - grab.x) / grab.canvas.width;

            this._placement.width = Math.max(0.02, width);
        } else {
            this._placement.x = grab.from.x + (event.clientX - grab.x) / grab.canvas.width;
            this._placement.y = grab.from.y + (event.clientY - grab.y) / grab.canvas.height;
        }

        this.#drawStamp();
    }

    drop(event) {
        if (undefined === this._grab || null === this._grab) {
            return;
        }

        this.stampTarget.releasePointerCapture?.(event.pointerId);
        this._grab = null;
    }

    // ── Committing it ─────────────────────────────────────────────────────

    /** Flatten, then post the result as a reply draft's attachment. */
    async reply(event) {
        event?.preventDefault();

        const signed = await this.#flatten();

        if (null === signed) {
            return;
        }

        const file = new File([signed], this.filenameValue, { type: "application/pdf" });
        const transfer = new DataTransfer();

        transfer.items.add(file);
        this.fileTarget.files = transfer.files;
        this.frameFieldTarget.value = this.frameValue;

        // A real form submitted by Turbo, so the compose window comes back into
        // the frame that asked for it and modal_controller closes the dialog on
        // turbo:submit-end with no code of ours. _message_actions does the same.
        this.formTarget.requestSubmit();
    }

    /** Flatten, and hand the result straight to the reader instead. */
    async download(event) {
        event?.preventDefault();

        const signed = await this.#flatten();

        if (null === signed) {
            return;
        }

        this._releaseDownload();
        this._downloadUrl = URL.createObjectURL(new Blob([signed], { type: "application/pdf" }));

        const link = document.createElement("a");

        link.href = this._downloadUrl;
        link.download = this.filenameValue;
        link.click();
    }

    // ── Private ───────────────────────────────────────────────────────────

    /** The reading controller riding the same element. */
    get viewer() {
        return this.application.getControllerForElementAndIdentifier(this.element, "mail--pdf-viewer");
    }

    /**
     * Why this document cannot be signed, or null when it can.
     *
     * An encrypted PDF loads only with `ignoreEncryption: true`, and saving it
     * back then silently strips the protection it was given — so the honest
     * answer is to refuse and say so. Reading is unaffected: pdf.js opens these
     * quite happily, and the reader stays exactly as useful as it was.
     *
     * Anything else that will not parse gets its own wording. Telling somebody
     * their document is protected when it is in fact malformed sends them off
     * looking for a password that does not exist.
     */
    async #refusal() {
        try {
            const { PDFDocument } = await import("@cantoo/pdf-lib");

            await PDFDocument.load(this.viewer.documentBytes().slice(0));

            return null;
        } catch (error) {
            console.error("[pdf-sign]", error);

            return "EncryptedPDFError" === error?.name
                ? this.encryptedTextValue
                : this.unreadableTextValue;
        }
    }

    /** Sized to what it is shown at, backed at four times that. */
    #prepareInk() {
        const canvas = this.inkTarget;
        const box = canvas.getBoundingClientRect();
        const scale = this.constructor.SUPERSAMPLE;

        canvas.width = Math.round(box.width * scale);
        canvas.height = Math.round(box.height * scale);

        const context = canvas.getContext("2d");

        context.clearRect(0, 0, canvas.width, canvas.height);
        context.lineWidth = 2.5 * scale;
        context.lineCap = "round";
        context.lineJoin = "round";
        // Ink, not theme colour: this is going onto somebody else's document,
        // where the reader's chosen accent has no meaning.
        context.strokeStyle = "#111827";
    }

    #inkPoint(event) {
        const box = this.inkTarget.getBoundingClientRect();
        const scale = this.inkTarget.width / box.width;

        return [(event.clientX - box.left) * scale, (event.clientY - box.top) * scale];
    }

    /**
     * The ink, trimmed to itself, as a PNG.
     *
     * Untrimmed it carries the whole empty pad, so the mark lands wherever
     * inside the placement box it happened to be drawn rather than where the
     * box was put — and it is mostly transparent padding either way.
     */
    async #captureInk() {
        const canvas = this.inkTarget;
        const context = canvas.getContext("2d");
        const bounds = trimAlpha(context.getImageData(0, 0, canvas.width, canvas.height));

        if (null === bounds) {
            return null;
        }

        const cut = document.createElement("canvas");

        cut.width = bounds.width;
        cut.height = bounds.height;
        cut.getContext("2d").drawImage(
            canvas,
            bounds.left, bounds.top, bounds.width, bounds.height,
            0, 0, bounds.width, bounds.height,
        );

        const blob = await new Promise((resolve) => cut.toBlob(resolve, "image/png"));

        return {
            bytes: await blob.arrayBuffer(),
            url: URL.createObjectURL(blob),
            ratio: bounds.width / bounds.height,
        };
    }

    /**
     * The document with the stamp drawn into it, as bytes.
     *
     * The placement is converted here and not before: the reader may have been
     * zoomed or the window resized since the box was put down, and the box on
     * screen is only ever a view of where it goes.
     */
    async #flatten() {
        if (true === this._busy || null === this._placement) {
            return null;
        }

        this._busy = true;

        try {
            const { PDFDocument, degrees } = await import("@cantoo/pdf-lib");

            const slot = this.#slot();
            const doc = await PDFDocument.load(this.viewer.documentBytes().slice(0));
            const page = doc.getPages()[this._placement.page - 1];

            // Back into client coordinates at the last moment, because that is
            // what the viewport transform is expressed in. The canvas is
            // re-measured here rather than remembered: the reader may have been
            // zoomed or the window resized since the box was put down.
            const canvas = slot.canvas.getBoundingClientRect();
            const box = this.#box(slot.canvas);

            const rect = pdfRect(this.viewer.displayViewport(slot), canvas, {
                left: canvas.left + (box.left - slot.canvas.offsetLeft),
                top: canvas.top + (box.top - slot.canvas.offsetTop),
                width: box.width,
                height: box.height,
            });

            const anchor = stampAnchor(rect, page.getRotation().angle);
            const png = await doc.embedPng(this._stamp.bytes);

            page.drawImage(png, {
                x: anchor.x,
                y: anchor.y,
                width: anchor.width,
                height: anchor.height,
                rotate: degrees(anchor.rotate),
            });

            return await doc.save();
        } catch (error) {
            console.error("[pdf-sign]", error);

            return null;
        } finally {
            this._busy = false;
        }
    }

    /** An object URL is a document-lifetime reference until it is revoked. */
    _releaseDownload() {
        if (undefined !== this._downloadUrl && null !== this._downloadUrl) {
            URL.revokeObjectURL(this._downloadUrl);
            this._downloadUrl = null;
        }
    }
}
