import { Controller } from "@hotwired/stimulus"

/**
 * notes--editor: the Keep-style note editor.
 *
 * Saves the whole note as you type (debounced) to the autosave endpoint —
 * title, text or checklist, colour, labels — so there is no Save button.
 * Undo/redo walk snapshots of the same state. Formatting buttons put the
 * small markdown subset both clients render (**bold**, *italic*,
 * __underline__, "# " heading, "- " bullet) around the selection.
 */
export default class extends Controller {
    static targets = [
        "title", "body", "textPane", "listPane", "items", "newItem", "itemTemplate",
        "labels", "colorRow", "linkRow", "status", "undoButton", "redoButton",
        "checklistButton", "formatButton",
    ]

    static values = {
        url: String, token: String, palette: Object,
        saving: String, saved: String, failed: String, itemPlaceholder: String,
    }

    connect() {
        this.timer = null
        this.history = [this.#snapshot()]
        this.position = 0
        this.lastSaved = JSON.stringify(this.history[0])
        this.pending = null
        this.#flushOnLeave = () => this.#save(true)
        window.addEventListener("pagehide", this.#flushOnLeave)
    }

    disconnect() {
        window.removeEventListener("pagehide", this.#flushOnLeave)
        this.#save(true)
    }

    #flushOnLeave = null

    // ── Changes ──────────────────────────────────────────────────────────

    changed(event) {
        if (event?.target?.matches?.("[data-done]")) {
            const text = event.target.closest("[data-item]")?.querySelector("[data-text]")
            text?.classList.toggle("line-through", event.target.checked)
            text?.classList.toggle("opacity-60", event.target.checked)
        }
        this.#schedule()
    }

    #schedule() {
        clearTimeout(this.timer)
        this.timer = setTimeout(() => {
            this.#record()
            this.#save()
        }, 600)
    }

    #record() {
        const snap = this.#snapshot()
        if (JSON.stringify(snap) === JSON.stringify(this.history[this.position])) return
        this.history = this.history.slice(0, this.position + 1)
        this.history.push(snap)
        if (this.history.length > 100) this.history.shift()
        this.position = this.history.length - 1
        this.#updateUndo()
    }

    async #save(leaving = false) {
        clearTimeout(this.timer)
        const state = this.#snapshot()
        const json = JSON.stringify(state)
        if (json === this.lastSaved) return
        this.lastSaved = json
        if (this.hasStatusTarget) this.statusTarget.textContent = this.savingValue
        const request = fetch(this.urlValue, {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": this.tokenValue },
            body: json,
            keepalive: leaving,
            credentials: "same-origin",
        }).then((response) => {
            if (!response.ok) throw new Error(String(response.status))
            if (this.hasStatusTarget) this.statusTarget.textContent = this.savedValue
        }).catch(() => {
            this.lastSaved = null
            if (this.hasStatusTarget) this.statusTarget.textContent = this.failedValue
        })
        this.pending = request
        return request
    }

    /** Close: make sure the last keystrokes are saved before leaving. */
    async close(event) {
        event.preventDefault()
        const form = event.currentTarget
        await this.#save()
        await this.pending
        form.submit()
    }

    // ── State ────────────────────────────────────────────────────────────

    get #checklist() {
        return !this.listPaneTarget.classList.contains("hidden")
    }

    #snapshot() {
        const color = this.element.querySelector('input[name="color"]:checked')?.value ?? ""
        return {
            title: this.titleTarget.value,
            checklist: this.#checklist,
            body: this.bodyTarget.value,
            items: this.#items(),
            color,
            labels: this.hasLabelsTarget
                ? this.labelsTarget.value.split(",").map((l) => l.trim()).filter(Boolean)
                : [],
        }
    }

    #items() {
        return [...this.itemsTarget.querySelectorAll("[data-item]")].map((row) => ({
            text: row.querySelector("[data-text]").value,
            done: row.querySelector("[data-done]").checked,
        }))
    }

    #apply(state) {
        this.titleTarget.value = state.title
        this.bodyTarget.value = state.body
        this.itemsTarget.innerHTML = ""
        for (const item of state.items) this.#appendItem(item.text, item.done)
        this.#setChecklist(state.checklist)
        const radio = this.element.querySelector(`input[name="color"][value="${CSS.escape(state.color)}"]`)
        if (radio) radio.checked = true
        this.#paint(state.color)
        if (this.hasLabelsTarget) this.labelsTarget.value = state.labels.join(", ")
    }

    // ── Undo / redo ──────────────────────────────────────────────────────

    undo() {
        this.#record()
        if (this.position === 0) return
        this.position--
        this.#apply(this.history[this.position])
        this.#updateUndo()
        this.#save()
    }

    redo() {
        if (this.position >= this.history.length - 1) return
        this.position++
        this.#apply(this.history[this.position])
        this.#updateUndo()
        this.#save()
    }

    #updateUndo() {
        if (this.hasUndoButtonTarget) this.undoButtonTarget.disabled = this.position === 0
        if (this.hasRedoButtonTarget) this.redoButtonTarget.disabled = this.position >= this.history.length - 1
    }

    // ── Checklist ────────────────────────────────────────────────────────

    toggleChecklist() {
        this.#record()
        if (this.#checklist) {
            this.bodyTarget.value = this.#items().map((i) => i.text).filter((t) => t.trim() !== "").join("\n")
            this.itemsTarget.innerHTML = ""
            this.#setChecklist(false)
        } else {
            const lines = this.bodyTarget.value.split("\n").map((l) => l.replace(/^- /, "")).filter((l) => l.trim() !== "")
            this.itemsTarget.innerHTML = ""
            for (const line of lines.length ? lines : [""]) this.#appendItem(line, false)
            this.bodyTarget.value = ""
            this.#setChecklist(true)
        }
        this.#record()
        this.#save()
    }

    #setChecklist(on) {
        this.listPaneTarget.classList.toggle("hidden", !on)
        this.textPaneTarget.classList.toggle("hidden", on)
        for (const button of this.formatButtonTargets) button.classList.toggle("hidden", on)
        if (this.hasChecklistButtonTarget) {
            const label = this.checklistButtonTarget.dataset[on ? "hideLabel" : "showLabel"]
            this.checklistButtonTarget.title = label
            this.checklistButtonTarget.setAttribute("aria-label", label)
        }
    }

    /** Typing in "+ List item" turns into a real item and keeps the cursor. */
    addItem(event) {
        const text = event.target.value
        if (text === "") return
        event.target.value = ""
        const row = this.#appendItem(text, false)
        const input = row.querySelector("[data-text]")
        input.focus()
        input.setSelectionRange(text.length, text.length)
        this.#schedule()
    }

    itemKey(event) {
        const row = event.target.closest("[data-item]")
        if (event.key === "Enter") {
            event.preventDefault()
            const next = this.#appendItem("", false, row)
            next.querySelector("[data-text]").focus()
            this.#schedule()
        } else if (event.key === "Backspace" && event.target.value === "") {
            event.preventDefault()
            const previous = row.previousElementSibling?.querySelector("[data-text]")
            row.remove()
            previous?.focus()
            this.#schedule()
        }
    }

    removeItem(event) {
        event.currentTarget.closest("[data-item]")?.remove()
        this.#schedule()
    }

    #appendItem(text, done, after = null) {
        const row = this.itemTemplateTarget.content.firstElementChild.cloneNode(true)
        row.querySelector("[data-text]").value = text
        const box = row.querySelector("[data-done]")
        box.checked = done
        if (done) row.querySelector("[data-text]").classList.add("line-through", "opacity-60")
        if (after) after.after(row)
        else this.itemsTarget.append(row)
        return row
    }

    // ── Formatting ───────────────────────────────────────────────────────

    format(event) {
        const mark = event.params.mark
        const area = this.bodyTarget
        const { selectionStart: start, selectionEnd: end, value } = area

        if (mark === "# " || mark === "- ") {
            const lineStart = value.lastIndexOf("\n", start - 1) + 1
            const lineEndAt = value.indexOf("\n", end)
            const lineEnd = lineEndAt === -1 ? value.length : lineEndAt
            const lines = value.slice(lineStart, lineEnd).split("\n")
            const all = lines.every((l) => l.startsWith(mark))
            const changed = lines.map((l) => (all ? l.slice(mark.length) : (l.startsWith(mark) ? l : mark + l))).join("\n")
            area.setRangeText(changed, lineStart, lineEnd, "select")
        } else {
            const selected = value.slice(start, end)
            const wrapped = selected.startsWith(mark) && selected.endsWith(mark) && selected.length >= mark.length * 2
            const replacement = wrapped ? selected.slice(mark.length, -mark.length) : mark + selected + mark
            area.setRangeText(replacement, start, end, "select")
            if (!wrapped && selected === "") area.setSelectionRange(start + mark.length, start + mark.length)
        }
        area.focus()
        this.#schedule()
    }

    // ── Colour, links ────────────────────────────────────────────────────

    toggleColors() {
        this.colorRowTarget.classList.toggle("hidden")
    }

    toggleLinks() {
        this.linkRowTarget.classList.toggle("hidden")
    }

    colorChanged(event) {
        this.#paint(event.target.value)
        this.#schedule()
    }

    #paint(color) {
        const fill = this.paletteValue[color] || ""
        this.element.style.background = fill
        this.element.classList.toggle("bg-surface", fill === "")
        this.element.classList.toggle("text-ink", fill === "")
        this.element.classList.toggle("border-line", fill === "")
        this.element.classList.toggle("text-[#202124]", fill !== "")
        this.element.classList.toggle("border-black/10", fill !== "")
    }
}
