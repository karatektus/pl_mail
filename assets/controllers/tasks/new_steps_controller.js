import { Controller } from "@hotwired/stimulus";

/**
 * The fields steps are typed into, in the editor.
 *
 * There is always one empty field at the end: starting to type in it brings the next
 * one. So nothing has to be confirmed, and every field is simply part of the editor's
 * form. What is empty when it is saved is not a step, which the server decides, and
 * this only keeps the column tidy.
 *
 * A row's tick says the step goes in done. It writes the hidden field beside it, which
 * every row posts whatever it says, so the two lists line up by position. Its × takes
 * the row away, saved nothing yet. An empty row has nothing to tick or take away.
 *
 * Enter goes to the next field. It would otherwise submit the form, and saving half a
 * list by reflex would close the dialog on the rest.
 *
 * Targets:
 *   list      the fields' column
 *   field     one per row
 *   template  one empty row
 */
export default class extends Controller {
    static targets = ["list", "field", "template"];

    connect() {
        this._keepOneEmptyAtTheEnd();
    }

    typed(event) {
        const row = event.target.closest("li");
        const empty = "" === event.target.value.trim();

        row.querySelector("[role='checkbox']").disabled = empty;
        row.querySelector("[data-step-remove]").disabled = empty;

        if (empty) {
            this._setDone(row, false);
        }

        this._keepOneEmptyAtTheEnd();
    }

    tick(event) {
        this._setDone(event.currentTarget.closest("li"), "true" !== event.currentTarget.getAttribute("aria-checked"));
    }

    remove(event) {
        const row = event.currentTarget.closest("li");
        const next = row.nextElementSibling?.querySelector("input[type='text']");

        row.remove();
        this._keepOneEmptyAtTheEnd();
        next?.focus();
    }

    next(event) {
        event.preventDefault();

        const fields = this.fieldTargets;

        fields[fields.indexOf(event.target) + 1]?.focus();
    }

    /** A field emptied in the middle has nothing left to hold its place for. */
    left(event) {
        if ("" === event.target.value.trim() && event.target !== this.fieldTargets.at(-1)) {
            event.target.closest("li").remove();
        }
    }

    // ── Private ───────────────────────────────────────────────────────────

    _setDone(row, done) {
        row.querySelector("[name='stepsDone[]']").value = done ? "1" : "0";
        row.querySelector("[role='checkbox']").setAttribute("aria-checked", done ? "true" : "false");
    }

    _keepOneEmptyAtTheEnd() {
        const fields = this.fieldTargets;

        if ("" !== (fields.at(-1)?.value.trim() ?? "x")) {
            this.listTarget.append(this.templateTarget.content.firstElementChild.cloneNode(true));

            return;
        }

        // Emptied again: the one after it is no longer waiting for anything.
        if (2 <= fields.length && "" === fields.at(-2).value.trim()) {
            fields.at(-1).closest("li").remove();
        }
    }
}
