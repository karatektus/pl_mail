/* stimulusFetch: 'lazy' */
import { Controller } from "@hotwired/stimulus";
import { jsonCsrfHeaders } from "../../csrf.js";
import { requestFailed } from "../../request_errors.js";

/**
 * The message list settings (#608): Settings → Inbox → Message list, and the
 * tab count switch on the Categories page.
 *
 * Every choice is a radio that posts its one field. The list's shape is read
 * by app.css from attributes on <html> (see MessageListRenderer), so a choice
 * that carries `data-list-attr` sets that attribute at once and the preview —
 * and any list on the page — changes on the click. The server then answers
 * with every attribute and custom property the layout would write, and those
 * are applied as they come, which is also how the four custom-property
 * choices (sender discs, account corner, unread emphasis, tab counts) reach
 * the page: they have no attribute to set early.
 *
 * A save that fails puts the radio and the attribute back. There is no Save
 * button on this page, so the control is the only thing saying what is
 * stored, and it must not say something that is not.
 *
 * Values:
 *   url — where a field is saved (POST, JSON `{ field: value }`)
 *
 * Targets:
 *   preview — the sample list, whose width the width choice sets
 *
 * Routes used:
 *   POST app_message_list_save
 */
export default class extends Controller {
    static values = {
        url: String,
    };

    static targets = ["preview"];

    /** The width of the preview list, in rem, from the Phone/Narrow/Wide choice. */
    width(event) {
        if (this.hasPreviewTarget) {
            this.previewTarget.style.width = `${event.target.value}rem`;
        }
    }

    async pick(event) {
        const input = event.target;
        const field = input.name;
        const previous = this.#saved(field);

        this.#applyEarly(input);

        let response;

        try {
            response = await fetch(this.urlValue, {
                method: "POST",
                headers: jsonCsrfHeaders(),
                body: JSON.stringify({ [field]: input.value }),
            });
        } catch {
            this.#revert(field, previous);
            requestFailed(null);

            return;
        }

        if (requestFailed(response)) {
            this.#revert(field, previous);

            return;
        }

        const answer = await response.json();

        this.#apply(answer.attributes ?? {}, answer.style ?? "");
        this.#markSaved(field, input.value);
    }

    // ── Private ─────────────────────────────────────────────────────────────

    #applyEarly(input) {
        const attr = input.dataset.listAttr;

        if (undefined !== attr) {
            document.documentElement.setAttribute(attr, input.dataset.listValue);
        }
    }

    /**
     * The server's attributes and `name:value;` properties, onto <html>.
     * The properties are set one by one rather than by replacing the style
     * attribute, which also carries every appearance variable.
     */
    #apply(attributes, style) {
        const root = document.documentElement;

        for (const [name, value] of Object.entries(attributes)) {
            root.setAttribute(name, value);
        }

        for (const pair of style.split(";")) {
            const at = pair.indexOf(":");

            if (at > 0) {
                root.style.setProperty(pair.slice(0, at), pair.slice(at + 1));
            }
        }
    }

    /**
     * What is stored for a field, as the value of its radio: remembered on
     * the radio after each save, and the radio checked when the page was drawn
     * before the first.
     */
    #saved(field) {
        const radios = [...this.element.querySelectorAll(`input[name="${field}"]`)];

        return (radios.find((radio) => "true" === radio.dataset.stored)
            ?? radios.find((radio) => radio.defaultChecked))?.value ?? null;
    }

    #markSaved(field, value) {
        for (const radio of this.element.querySelectorAll(`input[name="${field}"]`)) {
            radio.dataset.stored = radio.value === value ? "true" : "false";
        }
    }

    #revert(field, value) {
        const radio = this.element.querySelector(`input[name="${field}"][value="${value}"]`);

        if (null === radio) {
            return;
        }

        radio.checked = true;
        this.#applyEarly(radio);
    }
}
