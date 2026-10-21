import { Controller } from "@hotwired/stimulus"
import * as Turbo from "@hotwired/turbo"

/**
 * notes--create: "Add note" beside a contact or an event. A button rather
 * than a form, because those views are read-only and carry no form at all:
 * it posts the link to /notes/new itself and opens the editor it lands on.
 */
export default class extends Controller {
    static values = { url: String, token: String, type: String, id: String, title: String, return: String }

    async create(event) {
        event.preventDefault()
        this.element.disabled = true

        const body = new FormData()
        body.append("_token", this.tokenValue)
        body.append("link_type", this.typeValue)
        body.append("link_id", this.idValue)
        body.append("link_title", this.titleValue)
        body.append("return", this.returnValue)

        try {
            const response = await fetch(this.urlValue, { method: "POST", body, credentials: "same-origin" })
            if (response.ok && response.redirected) {
                Turbo.visit(response.url)
                return
            }
        } catch (e) {
            // fall through: let the button be tried again
        }
        this.element.disabled = false
    }
}
