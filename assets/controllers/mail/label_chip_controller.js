import { Controller } from "@hotwired/stimulus";
import { jsonCsrfHeaders } from "../../csrf.js";
import { requestFailed } from "../../request_errors.js";

/**
 * The × on a tag in a conversation (#221 step 3): takes that label off the
 * one message it is shown on. A folder has no × — taking it off is a move,
 * and "Move to" makes those.
 *
 * The same request as unticking it in the "Label as" picker, for a message
 * rather than the thread. The answer redraws these chips and toasts with an
 * Undo, as that does.
 */
export default class extends Controller {
    static values = { messageId: Number };

    async remove(event) {
        event.preventDefault();
        event.stopPropagation();

        const button = event.currentTarget;
        button.disabled = true;

        let response;

        try {
            response = await fetch(`/status/message/${this.messageIdValue}/label`, {
                method: "POST",
                headers: jsonCsrfHeaders(),
                body: JSON.stringify({ labelId: Number(button.dataset.labelId), attach: false }),
            });
        } catch {
            requestFailed(null);
            button.disabled = false;
            return;
        }

        if (requestFailed(response)) {
            button.disabled = false;
            return;
        }

        const html = await response.text();

        if (html.trim() !== "") {
            Turbo.renderStreamMessage(html);
        }
    }
}
