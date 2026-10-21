import { Controller } from "@hotwired/stimulus"

/**
 * notes--shared: a collaborator link's note (/n/<token>). Saves a moment after
 * a change — the title, and the text or the checklist (items can be ticked,
 * edited, added and removed) — with the grant the page was given when the
 * password (if any) was entered. Nothing is sent until something changes.
 */
export default class extends Controller {
    static targets = ["title", "body", "item", "list", "newItem", "status"]
    static values = { url: String, grant: String, saved: String, failed: String }

    connect() {
        this.initial = JSON.stringify(this.#payload())
    }

    changed(event) {
        const box = event?.target
        if (box?.type === "checkbox") {
            box.closest("li")?.querySelector('input[type="text"]')?.classList.toggle("line-through", box.checked)
            box.closest("li")?.querySelector('input[type="text"]')?.classList.toggle("opacity-60", box.checked)
        }
        clearTimeout(this.timer)
        this.timer = setTimeout(() => this.save(), 800)
    }

    addItem(event) {
        if (event.key !== "Enter") return
        event.preventDefault()
        const text = this.newItemTarget.value.trim()
        if (!text || !this.itemTargets.length) return
        const row = this.itemTargets[this.itemTargets.length - 1].cloneNode(true)
        row.querySelector('input[type="checkbox"]').checked = false
        const field = row.querySelector('input[type="text"]')
        field.value = text
        field.classList.remove("line-through", "opacity-60")
        this.listTarget.append(row)
        this.newItemTarget.value = ""
        this.changed()
    }

    removeItem(event) {
        if (this.itemTargets.length <= 1) {
            const field = this.itemTargets[0]?.querySelector('input[type="text"]')
            if (field) field.value = ""
        } else {
            event.currentTarget.closest("li")?.remove()
        }
        this.changed()
    }

    disconnect() {
        if (this.timer) {
            clearTimeout(this.timer)
            this.save()
        }
    }

    #payload() {
        const payload = { title: this.hasTitleTarget ? this.titleTarget.value : "" }
        if (this.hasBodyTarget) payload.body = this.bodyTarget.value
        if (this.itemTargets.length) {
            payload.items = this.itemTargets.map((row) => ({
                done: row.querySelector('input[type="checkbox"]').checked,
                text: row.querySelector('input[type="text"]').value,
            }))
        }
        return payload
    }

    async save() {
        this.timer = null
        const payload = this.#payload()
        const json = JSON.stringify(payload)
        if (json === this.initial) return
        try {
            const response = await fetch(this.urlValue, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ ...payload, grant: this.grantValue }),
                keepalive: true,
            })
            if (!response.ok) throw new Error(String(response.status))
            this.initial = json
            if (this.hasStatusTarget) this.statusTarget.textContent = this.savedValue
        } catch (e) {
            if (this.hasStatusTarget) this.statusTarget.textContent = this.failedValue
        }
    }
}
