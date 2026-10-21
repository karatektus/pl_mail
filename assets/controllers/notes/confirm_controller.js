import { Controller } from "@hotwired/stimulus"

/**
 * notes--confirm: asks before a form goes (delete forever). An inline
 * onsubmit would be simpler and the app's CSP refuses it.
 */
export default class extends Controller {
    static values = { message: String }

    ask(event) {
        if (!window.confirm(this.messageValue)) {
            event.preventDefault()
        }
    }
}
