import { Controller } from "@hotwired/stimulus";

/**
 * Reads a PDF attachment in the browser.
 *
 * | Values  |                                                              |
 * |---------|--------------------------------------------------------------|
 * | src     | the attachment route the bytes are fetched from              |
 * | assets  | {lib, worker, cMaps, fonts, wasm} — see App\Twig\PdfJsExtension |
 * | failedText | what to say when the document will not open, translated  |
 *
 * | Targets        |                                                       |
 * |----------------|-------------------------------------------------------|
 * | stack          | where the page canvases go                            |
 * | pages          | the "3 / 12" position readout                         |
 * | scroller       | the scroll box, watched to know which page is showing |
 * | status         | the spinner, and where a failure is explained         |
 *
 * WHY THE LIBRARY IS IMPORTED FROM A VARIABLE
 *
 * `import(this.assetsValue.lib)` rather than a literal path, for two reasons.
 * AssetMapper's JavaScriptImportPathCompiler matches `import(` followed by a
 * quoted string and would try to resolve it — pdf.js is deliberately outside
 * the asset map (it needs directory prefixes, which digested filenames cannot
 * be), so a literal would warn at build time and resolve to nothing. And it is
 * 4.7 MB: parsing that on every page that merely CONTAINS a PDF chip would be
 * absurd, so it loads when a reader is actually opened. The emoji picker makes
 * the same trade and says so.
 *
 * WHY useWasm IS FALSE
 *
 * pdf.js 6 decodes JBIG2, JPEG 2000 and ICC colour with WebAssembly, and the
 * enforced Content-Security-Policy carries no 'wasm-unsafe-eval', so
 * instantiating it throws. The package ships pure-JS fallbacks and the loader
 * takes them when this is false. Setting it explicitly matters beyond speed:
 * *attempting* WASM logs a violation on every scanned document, which would
 * fail csp.spec.ts and teach everyone to ignore that test.
 *
 * The worker is same-origin, which is the other half of staying inside the
 * policy — pdf.js only reaches for a `blob:` worker wrapper on the cross-origin
 * branch, and a blob: worker is exactly what `default-src 'self'` refuses. This
 * controller constructs that worker itself and hands it over as
 * GlobalWorkerOptions.workerPort, so that closing the reader can terminate it
 * outright rather than asking it to stand down. See disconnect().
 */
export default class extends Controller {
    static targets = ["stack", "pages", "scroller", "status"];
    static values = { src: String, assets: Object, failedText: String };

    async connect() {
        this._doc = null;
        this._scale = 1.2;
        this._pages = [];
        this._rendering = null;
        this._closed = false;
        this._port = null;
        this._task = null;

        try {
            await this.#load();
        } catch (error) {
            this.#fail(error);
        }
    }

    disconnect() {
        this._observer?.disconnect();
        this._closed = true;

        // The modal empties its frame on close, so without this every open
        // leaves a worker process behind.
        //
        // Cancelling first is not tidiness: destroy() tears the pages down and
        // waits on any operator list still in flight. Note that destroy() is on
        // the LOADING TASK — PDFDocumentProxy has no such method, and calling it
        // there throws synchronously, which is a silent no-op inside a
        // disconnect() nobody awaits.
        for (const slot of this._pages) {
            slot.task?.cancel();
            slot.task = null;
        }

        this._task?.destroy().catch(() => {});
        this._task = null;
        this._doc = null;
        this._pages = [];

        // And then the port is terminated regardless. destroy() asks the worker
        // to stand down over the message channel and waits for the reply; that
        // handshake does not come back once the modal has emptied its frame, so
        // relying on it leaves the process alive for the rest of the session.
        // Owning the port is what makes the teardown ours to guarantee.
        if (null !== this._port) {
            if (this._pdfjs?.GlobalWorkerOptions.workerPort === this._port) {
                this._pdfjs.GlobalWorkerOptions.workerPort = null;
            }

            this._port.terminate();
            this._port = null;
        }
    }

    zoomIn() {
        this.#rescale(Math.min(this._scale + 0.25, 4));
    }

    zoomOut() {
        this.#rescale(Math.max(this._scale - 0.25, 0.5));
    }

    // ── Private ───────────────────────────────────────────────────────────

    async #load() {
        const assets = this.assetsValue;
        const pdfjs = await import(assets.lib);
        this._pdfjs = pdfjs;

        // Constructed here rather than left to GlobalWorkerOptions so that
        // disconnect() has something it can terminate outright — see there.
        // A module worker from a same-origin URL is also the branch that keeps
        // pdf.js away from its `blob:` wrapper, which the policy refuses.
        this._port = new Worker(assets.worker, { type: "module" });

        pdfjs.GlobalWorkerOptions.workerSrc = assets.worker;
        pdfjs.GlobalWorkerOptions.workerPort = this._port;

        const response = await fetch(this.srcValue, { credentials: "same-origin" });

        if (false === response.ok) {
            throw new Error(`attachment responded ${response.status}`);
        }

        // Kept whole. pdf.js DETACHES the buffer it is given as `data`, and the
        // signing step needs the original bytes afterwards — so it gets a copy
        // and this keeps the original.
        this._original = await response.arrayBuffer();

        this._task = pdfjs.getDocument({
            data: this._original.slice(0),
            cMapUrl: assets.cMaps,
            cMapPacked: true,
            standardFontDataUrl: assets.fonts,
            wasmUrl: assets.wasm,
            useWasm: false,
            // Nothing in a mail attachment gets to run its own JavaScript.
            enableScripting: false,
        });

        this._doc = await this._task.promise;

        this.statusTarget.hidden = true;
        this.pagesTarget.classList.remove("hidden");

        await this.#buildPages();
    }

    /**
     * One placeholder per page, sized before anything is drawn.
     *
     * Sized up front so the scrollbar is honest from the first paint: a stack
     * that grows as pages render moves the document under the reader, and on a
     * long file it never settles.
     */
    async #buildPages() {
        this.stackTarget.replaceChildren();
        this._pages = [];

        for (let number = 1; number <= this._doc.numPages; number += 1) {
            const page = await this._doc.getPage(number);
            const viewport = page.getViewport({ scale: this._scale });

            const canvas = document.createElement("canvas");
            canvas.className = "block shadow-sm bg-white max-w-full";
            canvas.style.width = `${viewport.width}px`;
            canvas.style.height = `${viewport.height}px`;
            canvas.dataset.page = String(number);

            this.stackTarget.appendChild(canvas);
            this._pages.push({ number, page, canvas, rendered: false, task: null });
        }

        this.#watch();
        this.#updatePosition(1);
    }

    /**
     * Render only what is near the viewport, and release what is far from it.
     *
     * A 200-page document at two megapixels a page is a gigabyte of canvas if
     * every page is kept. Releasing is `canvas.width = 0`, which is the only
     * thing that actually frees the backing store.
     */
    #watch() {
        this._observer?.disconnect();

        this._observer = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    const number = Number(entry.target.dataset.page);
                    const slot = this._pages[number - 1];

                    if (undefined === slot) {
                        continue;
                    }

                    if (true === entry.isIntersecting) {
                        this.#updatePosition(number);
                        // A cancelled page is the normal outcome of closing
                        // the reader mid-draw, not a fault worth a console.
                        void this.#render(slot).catch((error) => {
                            if (false === this._closed) {
                                console.error("[pdf-viewer]", error);
                            }
                        });
                    } else if (true === slot.rendered && this.#isFarAway(number)) {
                        slot.canvas.width = 0;
                        slot.rendered = false;
                    }
                }
            },
            { root: this.scrollerTarget, rootMargin: "200% 0px" },
        );

        for (const slot of this._pages) {
            this._observer.observe(slot.canvas);
        }
    }

    #isFarAway(number) {
        return Math.abs(number - this._current) > 2;
    }

    /**
     * One page onto its canvas.
     *
     * Serialised: pdf.js allows concurrent render tasks, but a burst of them
     * from a fast scroll competes for the same worker and finishes slower than
     * doing them in turn.
     */
    async #render(slot) {
        if (true === slot.rendered) {
            return;
        }

        slot.rendered = true;

        this._rendering = Promise.resolve(this._rendering).then(async () => {
            if (true === this._closed) {
                return;
            }

            // The backing store is drawn at device resolution; the CSS size
            // stays in layout pixels. Capped, because a 3× phone would
            // otherwise ask for nine times the pixels.
            const ratio = Math.min(window.devicePixelRatio || 1, 2);
            const viewport = slot.page.getViewport({ scale: this._scale });
            const rendered = slot.page.getViewport({ scale: this._scale * ratio });

            slot.canvas.width = Math.round(rendered.width);
            slot.canvas.height = Math.round(rendered.height);
            slot.canvas.style.width = `${viewport.width}px`;
            slot.canvas.style.height = `${viewport.height}px`;

            slot.task = slot.page.render({ canvas: slot.canvas, viewport: rendered });

            try {
                await slot.task.promise;
            } catch (error) {
                slot.rendered = false;
                throw error;
            } finally {
                slot.task = null;
            }
        });

        await this._rendering;
    }

    async #rescale(scale) {
        if (scale === this._scale || null === this._doc) {
            return;
        }

        this._scale = scale;

        for (const slot of this._pages) {
            slot.rendered = false;
            slot.canvas.width = 0;
        }

        await this.#buildPages();
    }

    #updatePosition(number) {
        this._current = number;
        this.pagesTarget.textContent = `${number} / ${this._doc.numPages}`;
    }

    #fail(error) {
        console.error("[pdf-viewer]", error);

        this.statusTarget.hidden = false;
        this.statusTarget.replaceChildren();

        const icon = document.createElement("i");
        icon.className = "fa-solid fa-triangle-exclamation text-2xl";

        const text = document.createElement("p");
        text.className = "text-sm";
        text.textContent = this.failedTextValue;

        this.statusTarget.append(icon, text);
    }
}
