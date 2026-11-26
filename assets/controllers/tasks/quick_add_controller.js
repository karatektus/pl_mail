import { Controller } from "@hotwired/stimulus";

/**
 * What was in the field when the frame was redrawn after an add, for the field
 * that replaces it: null when nothing was added. The redirect replaces the frame,
 * and with it this controller, so it cannot live on an instance.
 */
let carried = null;

/**
 * Titles entered while the one before was still on its way, sent in order once it
 * is in. Turbo stops a frame's running submission when the next one starts, and the
 * first task would be lost with it.
 */
const waiting = [];

/**
 * The tasks pane's quick add: one field, and the options under it once it is in use.
 *
 * Out of the way until then, because the pane is narrow and most tasks are a title
 * and nothing else. They stay while anything has been typed or chosen, so clicking
 * away to check a date in the mail does not cost what was entered.
 *
 * The title leaves the field the moment Enter sends it. The answer takes a moment
 * (about 1.5 s on a slow stack), and the field invites typing the next one straight
 * away: left standing, the sent title had the next one typed onto its end. What is
 * typed meanwhile moves into the field the redirect draws, and a title entered
 * meanwhile waits its turn (`waiting`).
 *
 * The form posts with or without any of this; a browser with no script gets the
 * title alone, which is the whole of what the field promises.
 *
 * Targets:
 *   title    the text field
 *   options  the row of buttons and their panels, `hidden` until it is in use
 *   toggle   one button per option, `data-panel` naming its panel
 *   panel    one per option, `data-panel` naming itself
 *   field    what each panel posts, whose value lights up its button
 */
export default class extends Controller {
    static targets = ["title", "options", "toggle", "panel", "field"];

    connect() {
        /** The title on its way from this form, null while none is. */
        this.sent = null;
        /** A waiting title this form is about to send in place of its field's. */
        this.next = null;

        if (null !== carried) {
            const end = carried.length;

            this.titleTarget.value = carried;
            carried = null;
            this.titleTarget.focus();
            this.titleTarget.setSelectionRange(end, end);

            requestAnimationFrame(() => this._sendWaiting());
        }
    }

    disconnect() {
        if (null !== this.sent) {
            carried = this.titleTarget.value;
        }
    }

    /** The options belong to the task being sent until it is in. */
    show() {
        if (null === this.sent) {
            this.optionsTarget.hidden = false;
        }
    }

    /**
     * After the browser has moved the focus: leaving for a control inside the form is
     * not leaving, and the relatedTarget of a native date picker is nothing to go by.
     */
    maybeHide() {
        requestAnimationFrame(() => {
            if (this.element.contains(document.activeElement) || this._isInUse()) {
                return;
            }

            this.optionsTarget.hidden = true;
            this.panelTargets.forEach((panel) => { panel.hidden = true; });
            this._drawToggles();
        });
    }

    /** A press on the row's empty space must not take the focus and close it. */
    keepFocus(event) {
        if (null === event.target.closest("input, select, button, label")) {
            event.preventDefault();
        }
    }

    toggle({ params: { panel: name } }) {
        this.panelTargets.forEach((panel) => {
            panel.hidden = panel.dataset.panel !== name || false === panel.hidden;

            // The repeat is a ui--select, whose own select is hidden: the
            // widget it draws is what takes the focus.
            if (false === panel.hidden) {
                panel.querySelector("[role='combobox'], input, select")?.focus();
            }
        });

        this._drawToggles();
    }

    changed() {
        this._drawToggles();
    }

    /** Enter while the one before is on its way: the title waits, out of the field. */
    hold(event) {
        if (null === this.sent) {
            return;
        }

        event.preventDefault();

        const title = this.titleTarget.value.trim();

        if ("" !== title) {
            waiting.push(title);
        }

        this.titleTarget.value = "";
    }

    /** A waiting title goes in place of what the field holds now. */
    fill(event) {
        if (null !== this.next) {
            event.formData.set("title", this.next);
        }
    }

    /** Turbo has read the form, so the title can leave it. */
    started() {
        if (null !== this.next) {
            this.sent = this.next;
            this.next = null;
        } else {
            this.sent = this.titleTarget.value;
            this.titleTarget.value = "";
        }

        this.optionsTarget.hidden = true;
        this.panelTargets.forEach((panel) => { panel.hidden = true; });
        this._drawToggles();
    }

    /**
     * Once it is in, the redirect redraws the frame and disconnect() carries the
     * field over. If it did not get through, the title comes back: into the field
     * when that is empty, else first in line, so nothing typed is lost.
     */
    submitted(event) {
        if (true === event.detail.success) {
            return;
        }

        waiting.unshift(this.sent.trim());
        this.sent = null;

        if ("" === this.titleTarget.value.trim()) {
            this.titleTarget.value = waiting.shift();
        }

        if (this.element.contains(document.activeElement)) {
            this.show();
        }
    }

    // ── Private ───────────────────────────────────────────────────────────

    /**
     * The next waiting title, from the form the last one's redirect drew. The
     * field may hold the start of another, so it is posted in place of the
     * field's title rather than through it, and without the field's `required`.
     */
    _sendWaiting() {
        if (false === this.element.isConnected || null !== this.sent || 0 === waiting.length) {
            return;
        }

        this.next = waiting.shift();
        this.element.noValidate = true;
        this.element.requestSubmit();
        this.element.noValidate = false;
    }

    _isInUse() {
        return "" !== this.titleTarget.value.trim() || this.fieldTargets.some((field) => "" !== field.value);
    }

    _drawToggles() {
        this.toggleTargets.forEach((toggle) => {
            const panel = this.panelTargets.find((candidate) => candidate.dataset.panel === toggle.dataset.panel);
            const chosen = "" !== (panel?.querySelector("input, select")?.value ?? "");

            toggle.setAttribute("aria-expanded", false === panel?.hidden ? "true" : "false");
            toggle.classList.toggle("text-accent", chosen);
            toggle.classList.toggle("text-ink-muted", !chosen);
        });
    }
}
