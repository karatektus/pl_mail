/* stimulusFetch: 'lazy' */
import { Controller } from "@hotwired/stimulus"

/**
 * "Add an exception for…" on the Compose defaults card (#692).
 *
 * Every address's editor is already in the markup, hidden unless the address
 * differs from its account; picking one here only shows it, open, at the end
 * of the list. Nothing is stored until one of its controls is changed — an
 * exception that changes nothing is not one, and the next render hides it
 * again.
 */
export default class extends Controller {
    static targets = ["candidate", "picker"]

    add(event) {
        const select = event.currentTarget
        const id = select.value

        if ("" === id) {
            return
        }

        const row = this.candidateTargets.find((candidate) => candidate.dataset.composeDefaultsException === id)

        select.querySelector(`option[value="${CSS.escape(id)}"]`)?.remove()
        select.value = ""

        if (undefined !== row) {
            row.hidden = false
            row.parentElement.append(row)

            const details = row.querySelector("details")
            details.open = true
            details.querySelector("select, button, input")?.focus()
        }

        if (this.hasPickerTarget && select.options.length <= 1) {
            this.pickerTarget.hidden = true
        }
    }
}
