import { Controller } from "@hotwired/stimulus"

/**
 * notes--export: the note as a PNG, drawn on a canvas in the browser — a
 * Keep-style card in the note's colour with its title and its text or
 * checklist. Reads the editor as it is now when there is one, so what is
 * downloaded is what is on screen, not what the page was loaded with.
 */
export default class extends Controller {
    static values = { title: String, body: String, items: Array, color: String, name: String }

    png() {
        const { title, body, items } = this.#current()
        const width = 800
        const pad = 48
        const ctx0 = document.createElement("canvas").getContext("2d")
        const font = '"Inter", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif'

        const lines = []
        if (title) lines.push({ text: title, font: `600 34px ${font}`, height: 46 })
        if (title) lines.push({ text: "", font: `400 24px ${font}`, height: 12 })
        const textLines = items.length
            ? items.map((i) => `${i.done ? "☑" : "☐"}  ${i.text}`)
            : body.split("\n").map((l) => l.replace(/\*\*|__/g, "").replace(/^# /, "").replace(/^- /, "• "))
        for (const raw of textLines) {
            ctx0.font = `400 24px ${font}`
            for (const piece of this.#wrap(ctx0, raw, width - pad * 2)) {
                lines.push({ text: piece, font: `400 24px ${font}`, height: 34 })
            }
        }

        const height = Math.max(240, pad * 2 + lines.reduce((h, l) => h + l.height, 0))
        const scale = 2
        const canvas = document.createElement("canvas")
        canvas.width = width * scale
        canvas.height = height * scale
        const ctx = canvas.getContext("2d")
        ctx.scale(scale, scale)

        ctx.fillStyle = this.colorValue || "#ffffff"
        this.#roundRect(ctx, 0, 0, width, height, 24)
        ctx.fill()
        ctx.fillStyle = "#202124"
        ctx.textBaseline = "top"

        let y = pad
        for (const line of lines) {
            ctx.font = line.font
            ctx.fillText(line.text, pad, y)
            y += line.height
        }

        canvas.toBlob((blob) => {
            if (!blob) return
            const a = document.createElement("a")
            a.href = URL.createObjectURL(blob)
            a.download = `${(this.nameValue || "note").replace(/[\\/:*?"<>|]/g, "_")}.png`
            document.body.append(a)
            a.click()
            a.remove()
            setTimeout(() => URL.revokeObjectURL(a.href), 1000)
        }, "image/png")
    }

    #current() {
        const editor = this.element.closest('[data-controller~="notes--editor"]')
        if (!editor) return { title: this.titleValue, body: this.bodyValue, items: this.itemsValue }

        const title = editor.querySelector('[data-notes--editor-target="title"]')?.value ?? this.titleValue
        const listPane = editor.querySelector('[data-notes--editor-target="listPane"]')
        const checklist = listPane && !listPane.classList.contains("hidden")
        const items = checklist
            ? [...listPane.querySelectorAll("li[data-item]")]
                  .map((li) => ({ done: li.querySelector("[data-done]")?.checked ?? false, text: li.querySelector("[data-text]")?.value ?? "" }))
                  .filter((i) => i.text.trim() !== "")
            : []
        const body = editor.querySelector('[data-notes--editor-target="body"]')?.value ?? this.bodyValue
        return { title, body: checklist ? "" : body, items }
    }

    #wrap(ctx, text, max) {
        if (text === "") return [""]
        const out = []
        let line = ""
        for (const word of text.split(/(\s+)/)) {
            const next = line + word
            if (ctx.measureText(next).width > max && line.trim() !== "") {
                out.push(line.trimEnd())
                line = word.trimStart()
            } else {
                line = next
            }
        }
        if (line !== "") out.push(line)
        return out
    }

    #roundRect(ctx, x, y, w, h, r) {
        ctx.beginPath()
        ctx.moveTo(x + r, y)
        ctx.arcTo(x + w, y, x + w, y + h, r)
        ctx.arcTo(x + w, y + h, x, y + h, r)
        ctx.arcTo(x, y + h, x, y, r)
        ctx.arcTo(x, y, x + w, y, r)
        ctx.closePath()
    }
}
