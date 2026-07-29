import { Controller } from "@hotwired/stimulus";

/**
 * Posts the picker's selection and hands the result to the compose window.
 *
 * The two live in different Turbo frames — compose in the dock or thread
 * frame, the picker in the modal frame — so they cannot address each other
 * through the DOM. A window event is the seam: this controller knows nothing
 * about compose beyond the event name, and compose knows nothing about
 * services beyond the shape of the payload.
 *
 * The radios are deliberately not grouped into a form. Each file has its own
 * `mode[<id>]` group so picking "copy" then "link" on the same file replaces
 * the choice rather than adding a second one, and files left untouched are
 * simply absent from the POST.
 */
export default class extends Controller {
    static targets = ["mode", "status", "submit"];

    static values = {
        url: String,
        draft: Number,
        token: String,
    };

    async attach() {
        const chosen = this.modeTargets.filter((input) => input.checked);

        if (0 === chosen.length) {
            this._status("Nothing selected");

            return;
        }

        if (0 === this.draftValue) {
            // The draft is force-saved before the picker opens, so this means
            // that save failed rather than that the user was too quick.
            this._status("Save the draft first");

            return;
        }

        const body = new FormData();
        body.append("_token", this.tokenValue);
        body.append("draft", String(this.draftValue));
        chosen.forEach((input) => body.append(input.name, input.value));

        this._busy(true);
        this._status("Attaching…");

        let payload;

        try {
            const response = await fetch(this.urlValue, {
                method: "POST",
                body,
                headers: { "X-Requested-With": "XMLHttpRequest" },
            });

            if (false === response.ok) {
                throw new Error(String(response.status));
            }

            payload = await response.json();
        } catch (_) {
            this._busy(false);
            this._status("Could not attach");

            return;
        }

        window.dispatchEvent(
            new CustomEvent("plmail:integration-attached", {
                detail: {
                    // Named so that compose windows other than the one that
                    // opened this picker ignore the result.
                    draftId: this.draftValue,
                    attachmentsHtml: payload.attachmentsHtml,
                    links: payload.links ?? [],
                },
            }),
        );

        // Files the server could not take — over the cap once its real size was
        // known, or a share link the service refused. Reported rather than
        // swallowed, and the modal stays open so the selection is still there.
        if (0 < (payload.errors ?? []).length) {
            this._busy(false);
            this._status(`Skipped: ${payload.errors.join(", ")}`);

            return;
        }

        this.dispatch("close", { prefix: "modal" });
    }

    _busy(busy) {
        if (true === this.hasSubmitTarget) {
            this.submitTarget.disabled = busy;
        }
    }

    _status(text) {
        if (true === this.hasStatusTarget) {
            this.statusTarget.textContent = text;
        }
    }
}
