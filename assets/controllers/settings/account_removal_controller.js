/* stimulusFetch: 'lazy' */
import { Controller } from "@hotwired/stimulus"

/**
 * Re-reads the account lists while an account on them is being removed (#672).
 *
 * Sits on the greyed-out row itself, so it exists exactly as long as there is
 * a removal to watch: the row goes when the job finishes, and this goes with
 * it. The row is server-rendered from the job, so a page loaded at any point
 * is already right — this only shortens the wait for one that is open, as
 * rules--rule-run does for a rule run.
 *
 * Listens for the mercure controller's `jobs-changed` event on the document
 * rather than opening its own EventSource. That event fires on every batch,
 * so re-reads are spaced out; and a slow poll covers a hub that cannot be
 * reached, so the row still settles without one.
 */
export default class extends Controller {
    static values = { url: String }

    static THROTTLE = 1500
    static POLL = 3000

    connect() {
        this._lastAt = 0
        this._pending = null
        this._onJobsChanged = () => this.refresh()
        document.addEventListener("core--mercure:jobs-changed", this._onJobsChanged)
        this._poll = setInterval(() => this.refresh(), this.constructor.POLL)
    }

    disconnect() {
        document.removeEventListener("core--mercure:jobs-changed", this._onJobsChanged)
        clearInterval(this._poll)
        clearTimeout(this._pending)
    }

    refresh() {
        const since = Date.now() - this._lastAt

        if (since >= this.constructor.THROTTLE) {
            this.#reload()

            return
        }

        clearTimeout(this._pending)
        this._pending = setTimeout(() => this.#reload(), this.constructor.THROTTLE - since)
    }

    async #reload() {
        this._lastAt = Date.now()

        try {
            const response = await fetch(this.urlValue, {
                headers: { Accept: "text/vnd.turbo-stream.html" },
                credentials: "same-origin",
            })

            // A failed re-read leaves the row as it is; the next nudge or poll
            // asks again. Nothing here is worth a toast.
            if (false === response.ok) {
                return
            }

            window.Turbo.renderStreamMessage(await response.text())
        } catch {
            // Offline for a moment: the same answer as a failed response.
        }
    }
}
