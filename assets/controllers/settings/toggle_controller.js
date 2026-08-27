import { Controller } from "@hotwired/stimulus";

/**
 * An On/Off segment on a settings page that posts on its own.
 *
 * The generic version of the one the insights page grew — same contract, same
 * revert-on-failure, no subject baked in. Each segment change POSTs by itself
 * because the toggles are independent: there is no form and nothing to submit,
 * and grouping them would mean a save button for a control whose whole point is
 * that it acts when you click it.
 *
 * The body is FormData with a `_token` field rather than JSON with the shared
 * `ajax` header, matching how ui--split posts the pane width: the token is
 * minted for one action and travels as a Stimulus value from the template, so
 * one endpoint's token is not good for every other endpoint.
 *
 * On any failure the OTHER segment of the pair is re-checked. The control is
 * the only indicator on the row, and leaving it showing a state the server
 * refused would be the settings page lying about the setting — which for these
 * rows can happen for a real reason rather than a broken network: an
 * administrator switching a feature off while somebody has the page open makes
 * the server answer 409 to a request to switch it on.
 *
 * Values:
 *   url    — where to POST
 *   token  — csrf_token() for that one action
 *
 * Each radio carries `data-key` for the subject and `data-action`
 * `change->settings--toggle#toggle`. No inline handlers anywhere: the enforced
 * Content-Security-Policy refuses them, silently.
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

    /** The radios are a pair sharing a name, so re-checking the sibling is the whole revert. */
    revert(segment) {
        const sibling = this.element.querySelector(
            `input[name="${segment.name}"]:not([value="${segment.value}"])`,
        );

        if (sibling) {
            sibling.checked = true;
        }
    }
}
