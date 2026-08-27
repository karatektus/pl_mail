import { Controller } from "@hotwired/stimulus";

/**
 * Puts one system prompt back to the text plMail ships.
 *
 * WHY "PUT IT BACK" IS AN EMPTY BOX AND NOT THE DEFAULT TEXT
 * An empty override means the shipped prompt is in force — that is the whole
 * fallback, and it is what keeps a later release able to improve the wording on
 * an installation that once pressed this button. Writing the current default
 * into the field would look identical on screen and would store a COPY of it,
 * pinning this installation to today's words for ever. So the field is cleared,
 * and the placeholder underneath it goes back to showing what will be sent.
 *
 * Nothing is saved here. The gesture is "undo my edit", not "undo my edit
 * everywhere immediately" — the administrator can still change their mind by
 * not pressing Save, which is what they would expect of a form.
 *
 * The input event is dispatched by hand because assigning to `.value` does not
 * fire one, and Turbo's form-restoration and any future character counter both
 * listen for it.
 */
export default class extends Controller {
    static targets = ["field"];

    reset() {
        if (false === this.hasFieldTarget) {
            return;
        }

        this.fieldTarget.value = "";
        this.fieldTarget.dispatchEvent(new Event("input", { bubbles: true }));

        // Focus follows the change, so a screen reader lands in the box that
        // just emptied rather than being left on a button whose label has
        // stopped being true.
        this.fieldTarget.focus();
    }
}
