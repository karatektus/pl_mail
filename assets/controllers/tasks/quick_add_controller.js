import { Controller } from "@hotwired/stimulus";

/**
 * Whether a task is being added again straight after one was: the frame is replaced
 * by the redirect, and with it this controller, so what the next one has to do
 * cannot live on an instance.
 */
let refocus = false;

/**
 * The tasks pane's quick add: one field, and the options under it once it is in use.
 *
 * Out of the way until then, because the pane is narrow and most tasks are a title
 * and nothing else. They stay while anything has been typed or chosen, so clicking
 * away to check a date in the mail does not cost what was entered.
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
        if (refocus) {
            refocus = false;
            this.titleTarget.focus();
        }
    }

    show() {
        this.optionsTarget.hidden = false;
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

            if (false === panel.hidden) {
                panel.querySelector("input, select")?.focus();
            }
        });

        this._drawToggles();
    }

    changed() {
        this._drawToggles();
    }

    /** So several can be typed in a row, as the field invites. */
    submitted(event) {
        refocus = true === event.detail.success;
    }

    // ── Private ───────────────────────────────────────────────────────────

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
