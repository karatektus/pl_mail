import { Controller } from "@hotwired/stimulus"

/**
 * contacts--save-to: the line under "Save to" says where the chosen place
 * sends the contact. Each choice — a radio in the segmented row, or an
 * option in the dropdown past four books — carries its sentence in
 * `data-hint`; this swaps it in. See contacts/_save_to.html.twig.
 */
export default class extends Controller {
    static targets = ["hint"]

    picked(event) {
        const el = event.target
        const hint = el.tagName === "SELECT" ? el.selectedOptions[0]?.dataset.hint : el.dataset.hint
        this.hintTarget.textContent = hint ?? ""
    }
}
