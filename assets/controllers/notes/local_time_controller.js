import { Controller } from "@hotwired/stimulus"

/** notes--local-time: a <time datetime="…Z"> shown in the reader's own zone and language. */
export default class extends Controller {
    connect() {
        const at = new Date(this.element.getAttribute("datetime"))
        if (Number.isNaN(at.getTime())) return
        this.element.textContent = at.toLocaleString(undefined, {
            weekday: "short", month: "short", day: "numeric", hour: "numeric", minute: "2-digit",
        })
    }
}
