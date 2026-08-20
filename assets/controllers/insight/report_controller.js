import { Controller } from "@hotwired/stimulus";

/**
 * The report dialog's confirm button: POSTs the note, closes the dialog, and
 * says what happened.
 *
 * Values:
 *   url    the endpoint for THIS message (app_insight_report_submit)
 *   token  the per-action `insight-report` CSRF token
 * Targets:
 *   note           the optional free-text field
 *   successToast   \ the two confirmations, rendered by the template so they
 *   alreadyToast   / are the app's real toast rather than a copy of it
 *
 * The token rides in as a Stimulus value rather than through csrf.js, for the
 * reason radar_controller states and that module documents: this is the
 * per-action token the endpoint checks, not the shared `ajax` one. The dialog
 * mints it, so the value is on the element the dialog rendered.
 *
 * ── Why the toast is cloned and not built ───────────────────────────────────
 * Both confirmations are <template>s in the dialog holding an include of
 * _partials/_toast.html.twig, so the markup, the tone colours and the
 * translated string all come from the places that own them. The alternative is
 * what mail/sync_now_controller does — a toast assembled from a string in
 * JavaScript — and that one is now a theme behind the partial it copied, which
 * is the whole argument.
 *
 * The clone is taken before close(), because closing the dialog empties the
 * modal frame and takes the templates with it.
 *
 * Nothing is optimistic. The dialog stays put until the server has the report,
 * for the same reason the radar's card does: a confirmation shown for a write
 * that did not happen is worse than a slow one, and this write is the one the
 * user was asked to consent to.
 */
export default class extends Controller {
    static targets = ["note", "successToast", "alreadyToast"];

    static values = { url: String, token: String };

    async submit(event) {
        const button = event.currentTarget;

        // The endpoint is idempotent, so a second click is harmless; this is
        // about not looking broken on a slow network.
        button.disabled = true;

        let response;

        try {
            response = await fetch(this.urlValue, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-Token": this.tokenValue,
                },
                body: JSON.stringify({ note: this.hasNoteTarget ? this.noteTarget.value : "" }),
            });
        } catch {
            button.disabled = false;

            return;
        }

        if (!response.ok) {
            button.disabled = false;

            return;
        }

        const { alreadyReported } = await response.json();

        this.#toast(alreadyReported === true ? this.alreadyToastTarget : this.successToastTarget);
        this.#closeDialog();
    }

    // ── Private ───────────────────────────────────────────────────────────

    /** @param {HTMLTemplateElement} template */
    #toast(template) {
        const region = document.getElementById("toast-region");

        if (null === region || !template) {
            return;
        }

        region.append(template.content.cloneNode(true));
    }

    /**
     * The dialog is closed through the shell's own controller rather than by
     * hiding anything here: close() is what restores focus to the menu entry
     * that opened it, empties the frame and releases the scroll lock, and half
     * of that done by hand is a dialog that is gone and a page that is still
     * locked.
     */
    #closeDialog() {
        const backdrop = document.querySelector("[data-ui--modal-dialog]");

        if (null === backdrop) {
            return;
        }

        this.application
            .getControllerForElementAndIdentifier(backdrop, "ui--modal")
            ?.close();
    }
}
