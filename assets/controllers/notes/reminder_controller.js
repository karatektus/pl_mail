import { Controller } from "@hotwired/stimulus"

/**
 * notes--reminder: the note's reminder in the editor — a bell in the toolbar
 * opens a row of presets (later today, tomorrow morning, next week) and a
 * date-and-time field; the chosen time shows as a chip with an ✕.
 *
 * Saved on its own (POST /notes/{id}/reminder, JSON) rather than through the
 * autosave, so undo/redo of the text never moves a reminder. Times are picked
 * and shown in the browser's zone and sent as an instant with its offset.
 */
export default class extends Controller {
    static targets = ["row", "input", "chip", "chipText"]
    static values = { url: String, token: String, at: String, failed: String }

    connect() {
        this.#show(this.atValue || null)
    }

    toggle() {
        this.rowTarget.hidden = !this.rowTarget.hidden
        if (!this.rowTarget.hidden && !this.inputTarget.value) {
            this.inputTarget.value = this.#local(this.#preset("tomorrow"))
        }
    }

    preset(event) {
        this.#save(this.#preset(event.params.when))
    }

    pick() {
        if (!this.inputTarget.value) return
        this.#save(new Date(this.inputTarget.value))
    }

    clear() {
        this.#save(null)
    }

    #preset(when) {
        const d = new Date()
        d.setSeconds(0, 0)
        if (when === "later") {
            // 18:00 today, or three hours from now once that has passed.
            d.setHours(18, 0)
            if (d <= new Date()) {
                const later = new Date(Date.now() + 3 * 3600_000)
                later.setMinutes(0, 0, 0)
                return later
            }
            return d
        }
        if (when === "week") {
            // Next Monday, 08:00.
            d.setDate(d.getDate() + ((8 - d.getDay()) % 7 || 7))
        } else {
            d.setDate(d.getDate() + 1)
        }
        d.setHours(8, 0)
        return d
    }

    async #save(date) {
        try {
            const response = await fetch(this.urlValue, {
                method: "POST",
                credentials: "same-origin",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": this.tokenValue },
                body: JSON.stringify({ at: date ? date.toISOString() : null }),
            })
            if (!response.ok) throw new Error(String(response.status))
            const json = await response.json()
            this.#show(json.at)
            this.rowTarget.hidden = true
        } catch (e) {
            window.alert(this.failedValue)
        }
    }

    #show(iso) {
        this.atValue = iso || ""
        this.chipTarget.hidden = !iso
        if (!iso) return
        const d = new Date(iso)
        this.chipTextTarget.textContent = d.toLocaleString(undefined, {
            weekday: "short", month: "short", day: "numeric", hour: "numeric", minute: "2-digit",
        })
        this.chipTarget.classList.toggle("line-through", d < new Date())
        this.inputTarget.value = this.#local(d)
    }

    /** A Date as a datetime-local value, in the browser's zone. */
    #local(d) {
        const pad = (n) => String(n).padStart(2, "0")
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
    }
}
