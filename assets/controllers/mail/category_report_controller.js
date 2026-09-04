import { Controller } from "@hotwired/stimulus";

/**
 * Reports one message as filed in the wrong tab.
 *
 * A POST and no navigation. This lives in a popover on a thread somebody is in
 * the middle of reading, and the whole value of the control is that it costs
 * them nothing to press — a redirect, a reload or a toast that steals focus
 * would each cost more than the report is worth.
 *
 * So the button confirms ITSELF: the label becomes "reported" and the control
 * disables. That is also the honest end state, because a report cannot be
 * pressed twice about the same thing usefully.
 */
export default class extends Controller {
    static targets = ["category", "button", "icon", "label"];

    static values = {
        url: String,
        token: String,
        sentLabel: { type: String, default: "Reported" },
        failedLabel: { type: String, default: "Could not report" },
    };

    async send() {
        if (false === this.hasButtonTarget || true === this.buttonTarget.disabled) {
            return;
        }

        // Disabled first, so a double click cannot file two reports about one
        // message while the first request is still in the air.
        this.buttonTarget.disabled = true;

        try {
            const response = await fetch(this.urlValue, {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({
                    _token: this.tokenValue,
                    shouldBe: this.hasCategoryTarget ? this.categoryTarget.value : "",
                }),
            });

            if (false === response.ok) {
                this.#settle(this.failedLabelValue, "fa-triangle-exclamation", true);

                return;
            }

            this.#settle(this.sentLabelValue, "fa-check", false);
        } catch {
            this.#settle(this.failedLabelValue, "fa-triangle-exclamation", true);
        }
    }

    /**
     * The button's own answer.
     *
     * A failure re-enables it, because the report has not been made and trying
     * again is the reasonable next move. Success does not: the same report a
     * second time is noise in the list somebody has to read.
     */
    #settle(text, icon, retry) {
        if (this.hasLabelTarget) {
            this.labelTarget.textContent = text;
        }

        if (this.hasIconTarget) {
            this.iconTarget.classList.remove("fa-flag");
            this.iconTarget.classList.add(icon);
        }

        this.buttonTarget.disabled = false === retry ? true : false;
    }
}
