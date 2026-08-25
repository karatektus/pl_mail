import { Controller } from "@hotwired/stimulus";
import { trimAlpha } from "../../pdf_geometry.js";

/**
 * Drawing a signature once, in Settings, so it need not be drawn again.
 *
 * The pad itself, and nothing else: the file is posted by an ordinary form, so
 * saving works the way every other setting on this page does and there is no
 * fetch() here to keep in step with a CSRF token.
 *
 * WHAT IT PRODUCES
 *
 * A trimmed PNG with a transparent background, drawn at four times the size it
 * is shown at. All three properties matter and each is a bug if dropped.
 * Transparency, because the image is stamped onto somebody else's document and
 * an opaque one is a white card over their text. Trimmed, because otherwise the
 * mark sits wherever inside the placement box it happened to be drawn rather
 * than where the box was put. Supersampled, because a stamp captured at 1×
 * looks right on screen and prints as a smear — and the document people most
 * want a signature on is the one they are going to print.
 *
 * trimAlpha comes from assets/pdf_geometry.js, which is where the signing
 * controller gets it too. The two pads have to agree about what a stamp IS, and
 * one copy of the trim is how that stays true.
 *
 * | Targets |                                                              |
 * |---------|--------------------------------------------------------------|
 * | ink     | the canvas drawn on                                           |
 * | file    | the file input the form posts                                 |
 * | save    | disabled until something has actually been drawn              |
 */
export default class extends Controller {
    static targets = ["ink", "file", "save"];

    /** Matches mail--pdf-sign, deliberately: same stamp, same resolution. */
    static SUPERSAMPLE = 4;

    connect() {
        this._drawing = false;
        this.#prepare();

        // The pad has no size until it is laid out, and a canvas sized from a
        // zero-width box stays zero-width for good. This is the settings pane,
        // which can be rendered into a hidden section and shown afterwards.
        this._sizes = new ResizeObserver(() => this.#prepareIfResized());
        this._sizes.observe(this.inkTarget);
    }

    disconnect() {
        this._sizes?.disconnect();
    }

    startStroke(event) {
        event.preventDefault();

        this._drawing = true;
        this.inkTarget.setPointerCapture?.(event.pointerId);

        const context = this.inkTarget.getContext("2d");

        context.beginPath();
        context.moveTo(...this.#point(event));
    }

    /**
     * Coalesced events, because a quick signature moves further between frames
     * than between input samples and a line drawn frame to frame is visibly
     * polygonal.
     */
    stroke(event) {
        if (false === this._drawing) {
            return;
        }

        event.preventDefault();

        const context = this.inkTarget.getContext("2d");

        for (const point of event.getCoalescedEvents?.() ?? [event]) {
            context.lineTo(...this.#point(point));
        }

        context.stroke();
    }

    async endStroke(event) {
        if (false === this._drawing) {
            return;
        }

        this._drawing = false;
        this.inkTarget.releasePointerCapture?.(event.pointerId);

        await this.#capture();
    }

    clear(event) {
        event?.preventDefault();

        this.#prepare();
        this.fileTarget.value = "";
        this.saveTarget.disabled = true;
    }

    // ── Private ───────────────────────────────────────────────────────────

    #prepareIfResized() {
        const box = this.inkTarget.getBoundingClientRect();
        const wanted = Math.round(box.width * this.constructor.SUPERSAMPLE);

        // Only when the width actually changed: preparing resets the canvas,
        // and a ResizeObserver that fires for any reason would wipe a signature
        // somebody was half-way through drawing.
        if (0 !== wanted && wanted !== this.inkTarget.width) {
            this.#prepare();
        }
    }

    #prepare() {
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
        // Ink, not the theme's accent: this goes onto other people's documents,
        // where the reader's chosen colour means nothing.
        context.strokeStyle = "#111827";
    }

    #point(event) {
        const box = this.inkTarget.getBoundingClientRect();
        const scale = this.inkTarget.width / box.width;

        return [(event.clientX - box.left) * scale, (event.clientY - box.top) * scale];
    }

    /** Trim to the stroke, and hand the result to the form as a real file. */
    async #capture() {
        const canvas = this.inkTarget;
        const context = canvas.getContext("2d");
        const bounds = trimAlpha(context.getImageData(0, 0, canvas.width, canvas.height));

        if (null === bounds) {
            return;
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
        const transfer = new DataTransfer();

        transfer.items.add(new File([blob], "signature.png", { type: "image/png" }));
        this.fileTarget.files = transfer.files;
        this.saveTarget.disabled = false;
    }
}
