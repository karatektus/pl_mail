import { Controller } from "@hotwired/stimulus";
import { requestFailed } from "../../request_errors.js";

/**
 * The Off / Right choice for where the open message is shown.
 *
 * Its own controller, posting one value to its own endpoint. It is on a
 * settings page of its own (Features → Reading pane) and never was a field of
 * settings--appearance, even while it sat on that page: the appearance panel
 * saves one JSON payload that becomes part of an exported theme, and this is
 * something the mailbox does rather than part of a palette. See
 * settings/_reading_pane.html.twig.
 *
 * Nothing is repainted here. The setting takes effect on the next mailbox page
 * the user opens, and the settings page has no list for it to rearrange — the
 * radio itself is the only thing that changes on screen, and it already has.
 *
 * Values:
 *   url   — where the mode is remembered (POST, `mode`)
 *   token — the CSRF token for that endpoint
 *
 * Routes used:
 *   POST app_appearance_reading_pane_state
 */
export default class extends Controller {
    static values = {
        url: String,
        token: String,
    };

    connect() {
        this._saved = this._checked()?.value ?? null;
    }

    async pick(event) {
        const mode = event.target.value;

        const body = new FormData();
        body.append("_token", this.tokenValue);
        body.append("mode", mode);

        let response;

        try {
            response = await fetch(this.urlValue, {
                method: "POST",
                body,
                headers: { "X-Requested-With": "fetch" },
            });
        } catch {
            this._revert();
            requestFailed(null);

            return;
        }

        if (requestFailed(response)) {
            this._revert();

            return;
        }

        this._saved = mode;
    }

    /**
     * Put the radio back to what the server has, because a choice that did not
     * land would otherwise read as saved: there is no Save button on this page,
     * so the control is the only thing saying what is stored.
     */
    _revert() {
        const previous = this.element.querySelector(`input[name="readingPane"][value="${this._saved}"]`);

        if (null !== previous) {
            previous.checked = true;
        }
    }

    _checked() {
        return this.element.querySelector('input[name="readingPane"]:checked');
    }
}
