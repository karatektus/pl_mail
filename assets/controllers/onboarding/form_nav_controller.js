import { Controller } from "@hotwired/stimulus"

/**
 * Navigating away from a wizard step without losing what was typed.
 *
 * Both the provider chips on the credential steps and the pills in the progress
 * rail used to be links. Clicking one navigated the frame, and anything entered
 * on the step being left went with it — you could tick "offer this service",
 * click the next pill, and arrive with the box quietly unticked again.
 *
 * So they submit the step instead, naming where to go. The server saves what is
 * there and answers with the requested destination; if the form is not valid it
 * stays put and says so, rather than discarding the input on the way past.
 *
 * The hidden field is what carries the destination. Which field it is depends
 * on the caller — a provider to switch to, a step to jump to — so the buttons
 * name their own value and the controller only wires the two together.
 */
export default class extends Controller {
    static targets = ["field"]

    go(event) {
        const value = event.currentTarget.dataset.value

        if (!value) {
            return
        }

        this.fieldTarget.value = value

        // requestSubmit, not submit(): it runs the form's own validation and
        // fires the submit event Turbo listens for, so the answer lands back in
        // this frame instead of navigating the page.
        this.element.closest("form")?.requestSubmit()
    }
}
