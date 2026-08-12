import { Controller } from "@hotwired/stimulus";

/**
 * The parent side of the sandboxed reading frame.
 *
 * The frame has no allow-same-origin, so this controller cannot touch its
 * document at all — not to measure it, not to style it, not to unblock an
 * image. Everything crosses as a postMessage, and that constraint IS the
 * security property: a body that this code cannot reach into is a body that
 * cannot reach back.
 *
 * Three conversations:
 *   frame → here   "height"  the content measured itself; resize to fit
 *   frame → here   "link"    a link is under the cursor; show/hide the preview
 *   here  → frame  "theme"   the live mail-sheet colours, which CSS custom
 *                            properties cannot carry across a document boundary
 *   here  → frame  "show-images"  the reader clicked the bar
 */
export default class extends Controller {
    static targets = ["frame", "status"];
    static values  = { minHeight: { type: Number, default: 80 } };

    connect() {
        this.onMessage = this.handleMessage.bind(this);
        window.addEventListener("message", this.onMessage);

        // The frame may already have loaded before this controller connected —
        // srcdoc content is parsed synchronously and Stimulus connects on the
        // next microtask, so `load` can be gone by the time we could listen,
        // and with it the frame's first height report. Now that we ARE
        // listening, ask for a fresh measurement (the frame dedupes its own
        // reports, so it will not re-send the lost one unprompted). Both the
        // theme and the request are idempotent, and run on connect and on load
        // in case either happened first.
        this.sync = () => {
            this.postTheme();
            this.post({ plmail: "measure" });
        };
        this.frameTarget.addEventListener("load", this.sync);
        this.sync();
    }

    disconnect() {
        window.removeEventListener("message", this.onMessage);
    }

    handleMessage(event) {
        // The frame is an opaque origin, so event.origin is "null" and proves
        // nothing. Identity comes from the window itself: only OUR frame's
        // contentWindow is our frame.
        if (!this.hasFrameTarget || event.source !== this.frameTarget.contentWindow) return;

        const data = event.data;
        if (!data || typeof data !== "object") return;

        if (data.plmail === "height") {
            const height = Number(data.height);
            if (Number.isFinite(height) && height > 0) {
                this.frameTarget.style.height = `${Math.max(height, this.minHeightValue)}px`;
            }
        }

        if (data.plmail === "link") {
            this.showLink(data.href);
        }
    }

    /**
     * The hovered link's destination, shown the way a browser's own status bar
     * shows it — and rendered HERE rather than in the frame, so a message
     * cannot draw a status bar of its own claiming a different destination.
     *
     * textContent, never innerHTML: the href is attacker-controlled text.
     */
    showLink(href) {
        if (!this.hasStatusTarget) return;

        if (!href) {
            this.statusTarget.classList.add("hidden");
            this.statusTarget.textContent = "";
            return;
        }

        this.statusTarget.textContent = String(href).slice(0, 300);
        this.statusTarget.classList.remove("hidden");
    }

    /** Called by the images bar (see remote_images_controller). */
    showImages() {
        this.post({ plmail: "show-images" });
    }

    /**
     * The mail sheet's colours, read from the app's own computed styles.
     *
     * The frame cannot inherit them: custom properties stop at the document
     * boundary, and there is no way in from outside. So they are read here,
     * where the cascade has already resolved whichever theme is active, and
     * posted across as concrete values.
     */
    postTheme() {
        const styles = getComputedStyle(this.element);
        const rgb = (name) => {
            const value = styles.getPropertyValue(name).trim();
            return value ? `rgb(${value})` : "";
        };

        this.post({
            plmail: "theme",
            vars: {
                sheet: rgb("--rgb-sheet"),
                ink:   rgb("--rgb-sheet-ink"),
                link:  rgb("--rgb-sheet-link"),
            },
        });
    }

    post(message) {
        const frame = this.hasFrameTarget ? this.frameTarget.contentWindow : null;
        // "*" is the only option: an opaque origin has no name to address, so
        // there is no targetOrigin that would ever match. Safe because the
        // recipient is a window we created and hold a handle to.
        if (frame) frame.postMessage(message, "*");
    }
}
