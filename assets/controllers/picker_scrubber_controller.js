import { Controller } from "@hotwired/stimulus";

/**
 * The floating month readout on the picker's date bar, plus drag-to-scrub.
 *
 * The segments are real links and do the actual jumping; this only makes the bar
 * pleasant to aim at. A month with three photos is a two-pixel target, so
 * without a readout you cannot tell what you are about to click, and without
 * dragging you have to release and re-aim for every attempt.
 *
 * Dragging deliberately does not navigate as you move — one request per segment
 * crossed would hammer the server and land you somewhere you were only passing
 * through. It follows the pointer, and commits on release.
 */
export default class extends Controller {
    static targets = ["readout", "segment"];

    connect() {
        this._boundMove = this._handleMove.bind(this);
        this._boundUp = this._handleUp.bind(this);

        this.element.addEventListener("pointerdown", this._handleDown.bind(this));
    }

    disconnect() {
        this._stopDrag();
    }

    /** Hover on a single segment, when not dragging. */
    show(event) {
        if (true === this._dragging) {
            return;
        }

        this._place(event.currentTarget);
    }

    hide() {
        if (true === this._dragging) {
            return;
        }

        this.readoutTarget.classList.add("hidden");
    }

    // ── Private ───────────────────────────────────────────────────────────────

    _handleDown(event) {
        // Left button or touch only; a right-click should open the link menu.
        if (0 !== event.button) {
            return;
        }

        this._dragging = true;
        this._target = event.target.closest("[data-picker-scrubber-target='segment']");
        this._place(this._target);

        // Capturing the pointer keeps events coming even once the finger leaves
        // the bar, which it will — the bar is 48px wide.
        this.element.setPointerCapture?.(event.pointerId);
        this.element.addEventListener("pointermove", this._boundMove);
        this.element.addEventListener("pointerup", this._boundUp);
        this.element.addEventListener("pointercancel", this._boundUp);

        event.preventDefault();
    }

    _handleMove(event) {
        if (false === this._dragging) {
            return;
        }

        // elementFromPoint rather than event.target: with the pointer captured
        // every event reports the bar itself, not the segment under the finger.
        const under = document
            .elementFromPoint(event.clientX, event.clientY)
            ?.closest("[data-picker-scrubber-target='segment']");

        if (null != under) {
            this._target = under;
            this._place(under);
        }
    }

    _handleUp() {
        const target = this._target;

        this._stopDrag();
        this.readoutTarget.classList.add("hidden");

        // Committing on release is what makes dragging cheap: passing over a
        // month costs nothing, only stopping on one loads it.
        target?.click();
    }

    _stopDrag() {
        this._dragging = false;
        this.element.removeEventListener("pointermove", this._boundMove);
        this.element.removeEventListener("pointerup", this._boundUp);
        this.element.removeEventListener("pointercancel", this._boundUp);
    }

    /** Show the readout beside a segment, vertically centred on it. */
    _place(segment) {
        if (null == segment || false === this.hasReadoutTarget) {
            return;
        }

        const title = segment.dataset.title;

        if (undefined === title) {
            return;
        }

        const bar = this.readoutTarget.offsetParent ?? this.element;
        const barBox = bar.getBoundingClientRect();
        const box = segment.getBoundingClientRect();

        this.readoutTarget.textContent = title;
        this.readoutTarget.classList.remove("hidden");
        this.readoutTarget.style.top = `${box.top - barBox.top + box.height / 2 - this.readoutTarget.offsetHeight / 2}px`;
    }
}
