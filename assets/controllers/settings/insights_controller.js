import { Controller } from "@hotwired/stimulus";

/**
 * The insight extractor On/Off segments on Settings → Insights.
 *
 * Each segment change POSTs on its own — the toggles are independent, so
 * there is no form and nothing to submit. The body is FormData with a `_token`
 * field rather than JSON with the shared `ajax` header, matching how ui--split
 * posts the pane width to its sibling endpoint: the token is minted for this
 * one action (`insights_toggle`) and travels as a Stimulus value from the
 * template.
 *
 * On any failure the OTHER segment of the pair is re-checked — the control is
 * the only indicator on the row, and leaving it showing a state the server
 * refused would be the settings page lying about the setting. The radios are
 * a pair sharing a name, so re-checking the sibling is the whole revert.
 *
 * Values:
 *   url    — POST /settings/insights/toggle
 *   token  — csrf_token('insights_toggle')
 */
export default class extends Controller {
    static values = {
        url: String,
        token: String,
    };

    async toggle(event) {
        const segment = event.target;
        const enabled = segment.value === "1";

        const body = new FormData();
        body.append("key", segment.dataset.key);
        body.append("enabled", enabled ? "1" : "0");
        body.append("_token", this.tokenValue);

        try {
            const response = await fetch(this.urlValue, {
                method: "POST",
                credentials: "same-origin",
                headers: { "X-Requested-With": "fetch" },
                body,
            });

            if (false === response.ok) {
                this.revert(segment);
            }
        } catch (e) {
            this.revert(segment);
        }
    }

    revert(segment) {
        const sibling = this.element.querySelector(
            `input[name="${segment.name}"]:not([value="${segment.value}"])`,
        );

        if (sibling) {
            sibling.checked = true;
        }
    }
}
