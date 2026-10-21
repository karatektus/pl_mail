import { Controller } from "@hotwired/stimulus"

/**
 * notes--media: a note's pictures, drawings and voice recordings in the editor.
 *
 * Every change goes to the server at once (POST /notes/{id}/media, and
 * …/media/{mid}/delete) and is drawn here without a reload, so the text being
 * typed and its autosave are never interrupted.
 *
 *   - Pictures: the image button's file input, several at a time.
 *   - Drawing: a canvas in a <dialog> — pen colours (white rubs out), width,
 *     undo, clear — saved as a PNG of kind "drawing".
 *   - Voice: the microphone button records with MediaRecorder until pressed
 *     again (or five minutes), then uploads the recording.
 */
export default class extends Controller {
    static targets = ["grid", "audioList", "drawDialog", "canvas", "size", "recordButton", "recordTime"]
    static values = { uploadUrl: String, token: String, failed: String, deleteLabel: String, micDenied: String }

    // ── Pictures ────────────────────────────────────────────────────────────

    async pickImages(event) {
        const files = [...(event.target.files ?? [])]
        event.target.value = ""
        for (const file of files) {
            await this.#upload(file, "image", file.name)
        }
    }

    // ── Drawing ─────────────────────────────────────────────────────────────

    openDrawing() {
        this.color = "#202124"
        this.strokes = []
        this.drawDialogTarget.showModal()
        this.#sizeCanvas()
        this.#redraw()

        if (!this.drawBound) {
            this.drawBound = true
            const c = this.canvasTarget
            c.addEventListener("pointerdown", (e) => this.#startStroke(e))
            c.addEventListener("pointermove", (e) => this.#extendStroke(e))
            for (const type of ["pointerup", "pointercancel", "pointerleave"]) {
                c.addEventListener(type, () => (this.current = null))
            }
        }
    }

    closeDrawing() {
        this.drawDialogTarget.close()
    }

    pen({ params: { color } }) {
        this.color = color
    }

    undoStroke() {
        this.strokes.pop()
        this.#redraw()
    }

    clearDrawing() {
        this.strokes = []
        this.#redraw()
    }

    saveDrawing() {
        if (!this.strokes.length) {
            this.closeDrawing()
            return
        }
        this.canvasTarget.toBlob(async (blob) => {
            if (blob) await this.#upload(blob, "drawing", "drawing.png")
            this.closeDrawing()
        }, "image/png")
    }

    #sizeCanvas() {
        const c = this.canvasTarget
        const rect = c.getBoundingClientRect()
        c.width = Math.round(rect.width * 2)
        c.height = Math.round(rect.height * 2)
    }

    #point(e) {
        const rect = this.canvasTarget.getBoundingClientRect()
        return { x: (e.clientX - rect.left) * (this.canvasTarget.width / rect.width), y: (e.clientY - rect.top) * (this.canvasTarget.height / rect.height) }
    }

    #startStroke(e) {
        e.preventDefault()
        this.canvasTarget.setPointerCapture?.(e.pointerId)
        this.current = { color: this.color, width: Number(this.sizeTarget.value) * 2, points: [this.#point(e)] }
        this.strokes.push(this.current)
        this.#redraw()
    }

    #extendStroke(e) {
        if (!this.current) return
        this.current.points.push(this.#point(e))
        this.#redraw()
    }

    #redraw() {
        const c = this.canvasTarget
        const ctx = c.getContext("2d")
        ctx.fillStyle = "#ffffff"
        ctx.fillRect(0, 0, c.width, c.height)
        ctx.lineCap = "round"
        ctx.lineJoin = "round"
        for (const s of this.strokes ?? []) {
            ctx.strokeStyle = s.color
            ctx.lineWidth = s.width
            ctx.beginPath()
            s.points.forEach((p, i) => (i ? ctx.lineTo(p.x, p.y) : ctx.moveTo(p.x, p.y)))
            if (s.points.length === 1) ctx.lineTo(s.points[0].x + 0.1, s.points[0].y)
            ctx.stroke()
        }
    }

    // ── Voice ───────────────────────────────────────────────────────────────

    async toggleRecording() {
        if (this.recorder?.state === "recording") {
            this.recorder.stop()
            return
        }
        let stream
        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio: true })
        } catch (e) {
            window.alert(this.micDeniedValue)
            return
        }
        const type = ["audio/webm;codecs=opus", "audio/webm", "audio/mp4", "audio/ogg"].find((t) => window.MediaRecorder?.isTypeSupported?.(t)) ?? ""
        const chunks = []
        this.recorder = new MediaRecorder(stream, type ? { mimeType: type } : undefined)
        this.recorder.ondataavailable = (e) => e.data.size && chunks.push(e.data)
        this.recorder.onstop = async () => {
            stream.getTracks().forEach((t) => t.stop())
            clearInterval(this.tick)
            clearTimeout(this.limit)
            this.recordButtonTarget.classList.remove("text-red-600")
            this.recordTimeTarget.classList.add("hidden")
            const blob = new Blob(chunks, { type: this.recorder.mimeType || "audio/webm" })
            const ext = blob.type.includes("mp4") ? "m4a" : blob.type.includes("ogg") ? "ogg" : "webm"
            if (blob.size) await this.#upload(blob, "audio", `voice-note.${ext}`)
        }
        this.recorder.start()
        const started = Date.now()
        this.recordButtonTarget.classList.add("text-red-600")
        this.recordTimeTarget.classList.remove("hidden")
        this.recordTimeTarget.textContent = "0:00"
        this.tick = setInterval(() => {
            const s = Math.floor((Date.now() - started) / 1000)
            this.recordTimeTarget.textContent = `${Math.floor(s / 60)}:${String(s % 60).padStart(2, "0")}`
        }, 500)
        this.limit = setTimeout(() => this.recorder?.state === "recording" && this.recorder.stop(), 5 * 60 * 1000)
    }

    // ── Removing ────────────────────────────────────────────────────────────

    async remove({ params: { id } }) {
        try {
            const response = await fetch(`${this.uploadUrlValue}/${id}/delete`, {
                method: "POST",
                credentials: "same-origin",
                headers: { "X-CSRF-Token": this.tokenValue },
            })
            if (!response.ok) throw new Error(String(response.status))
            this.element.querySelectorAll(`[data-media-id="${id}"]`).forEach((el) => el.remove())
            this.#hideEmpty()
        } catch (e) {
            window.alert(this.failedValue)
        }
    }

    // ── Shared ──────────────────────────────────────────────────────────────

    async #upload(blob, kind, name) {
        const body = new FormData()
        body.append("file", blob, name)
        body.append("kind", kind)
        try {
            const response = await fetch(this.uploadUrlValue, {
                method: "POST",
                credentials: "same-origin",
                headers: { "X-CSRF-Token": this.tokenValue },
                body,
            })
            const json = await response.json().catch(() => ({}))
            if (!response.ok) throw new Error(json.error || this.failedValue)
            this.#draw(json.media, json.url)
        } catch (e) {
            window.alert(e.message || this.failedValue)
        }
    }

    #draw(media, url) {
        const button = (classes) => {
            const b = document.createElement("button")
            b.type = "button"
            b.className = classes
            b.title = this.deleteLabelValue
            b.setAttribute("aria-label", this.deleteLabelValue)
            b.dataset.action = "notes--media#remove"
            b.dataset.notesMediaIdParam = media.id
            b.innerHTML = '<i class="fa-solid fa-xmark text-xs" aria-hidden="true"></i>'
            return b
        }
        if (media.kind === "audio") {
            const row = document.createElement("div")
            row.className = "flex items-center gap-2"
            row.dataset.mediaId = media.id
            row.innerHTML = '<i class="fa-solid fa-microphone text-xs opacity-60" aria-hidden="true"></i>'
            const audio = document.createElement("audio")
            audio.controls = true
            audio.preload = "metadata"
            audio.src = url
            audio.className = "flex-1 min-w-0 h-9"
            row.append(audio, button("w-7 h-7 rounded-full hover:bg-black/10 cursor-pointer"))
            this.audioListTarget.append(row)
            this.audioListTarget.classList.remove("hidden")
        } else {
            const tile = document.createElement("div")
            tile.className = "relative group"
            tile.dataset.mediaId = media.id
            const link = document.createElement("a")
            link.href = url
            link.target = "_blank"
            link.rel = "noopener"
            link.dataset.turbo = "false"
            const img = document.createElement("img")
            img.src = url
            img.alt = media.name
            img.className = "w-full h-32 sm:h-40 object-cover rounded-lg bg-white"
            link.append(img)
            tile.append(link, button("absolute top-1.5 right-1.5 w-7 h-7 rounded-full bg-black/55 text-white opacity-0 group-hover:opacity-100 focus:opacity-100 cursor-pointer"))
            this.gridTarget.append(tile)
            this.gridTarget.classList.remove("hidden")
        }
    }

    #hideEmpty() {
        this.gridTarget.classList.toggle("hidden", !this.gridTarget.children.length)
        this.audioListTarget.classList.toggle("hidden", !this.audioListTarget.children.length)
    }
}
