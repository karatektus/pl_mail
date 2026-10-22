import { Controller } from "@hotwired/stimulus"
import { joinName, NAME_PARTS, postForm, splitName } from "../../contacts/fields.js"

/**
 * contacts--quick-add: the new-contact page (templates/contacts/new.html.twig).
 *
 * The box is read on every pause in typing (ContactAssistController::parse()),
 * and what came back is drawn as rows the person can untick, re-type or
 * correct — the type is a select, because "the rules filed my mobile as a
 * work number" should be one click, not a delete and a retype. Lines the
 * rules could not place are asked about. "Improve with AI" swaps in the
 * model's reading, with what it changed marked; "Undo AI changes" swaps the
 * rules' reading back.
 *
 * What posts is a set of hidden inputs written from the kept rows with the
 * full editor's names (writeInputs()), so create() and the editor read this
 * page exactly as they read the editor. Several rows can feed one field —
 * two lines placed as notes become one note — which is why the inputs are
 * written in one place rather than one per row.
 */
export default class extends Controller {
    static values = {
        parseUrl: String,
        parseToken: String,
        aiUrl: String,
        aiToken: String,
        strings: Object,
    }

    static targets = [
        "text", "result", "sourceBadge", "sourceDetail", "initials", "name", "partsSummary", "partsToggle",
        "partsPanel", "part", "rows", "rowTemplate", "unsureBox", "unsureTitle", "unsureList", "skipped",
        "aiBar", "aiAsk", "aiButton", "aiButtonText", "existing", "summary", "create", "inputs",
    ]

    connect() {
        this.rules = null
        this.ai = null
        this.usingAi = false
        this.models = []
        this.unsure = []
        this.nameEdited = false
        this.partsEdited = false
        this.#refresh()
    }

    disconnect() {
        this.abort?.abort()
        clearTimeout(this.timer)
    }

    typed() {
        clearTimeout(this.timer)
        this.timer = setTimeout(() => this.#read(), 300)
    }

    nameTyped() {
        this.nameEdited = true
        if (!this.partsEdited) this.#setParts(splitName(this.nameTarget.value))
        this.#refresh()
    }

    partTyped() {
        this.partsEdited = true
        this.nameTarget.value = joinName(this.#parts())
        this.nameEdited = true
        this.#refresh()
    }

    toggleParts() {
        const open = this.partsPanelTarget.hidden
        this.partsPanelTarget.hidden = !open
        this.partsToggleTarget.setAttribute("aria-expanded", String(open))
        this.partsToggleTarget.textContent = open ? this.stringsValue.done : this.stringsValue.fix
    }

    async improve() {
        if (this.usingAi) {
            this.usingAi = false
            this.#show(this.rules)
            return
        }

        const text = this.textTarget.value
        if (text.trim() === "") return

        this.aiButtonTarget.disabled = true
        this.aiButtonTextTarget.textContent = this.stringsValue.aiRunning
        this.abort?.abort()
        this.abort = new AbortController()
        try {
            this.ai = await postForm(this.aiUrlValue, this.aiTokenValue, { text }, this.abort.signal)
            this.usingAi = true
            this.#show(this.ai)
        } catch (error) {
            if (error.name === "AbortError") return
            if (error.status === 409) this.aiBarTarget.remove()
            else this.aiAskTarget.textContent = this.stringsValue.aiFailed
        } finally {
            if (this.hasAiButtonTarget) this.aiButtonTarget.disabled = false
            this.#refreshAiBar()
        }
    }

    // ── Reading ─────────────────────────────────────────────────────────

    async #read() {
        const text = this.textTarget.value
        this.usingAi = false
        this.ai = null

        if (text.trim() === "") {
            this.rules = null
            this.nameEdited = false
            this.partsEdited = false
            this.#show(null)
            return
        }

        this.sourceDetailTarget.textContent = this.stringsValue.reading
        this.abort?.abort()
        this.abort = new AbortController()
        try {
            this.rules = await postForm(this.parseUrlValue, this.parseTokenValue, { text }, this.abort.signal)
            this.#show(this.rules)
        } catch (error) {
            if (error.name !== "AbortError") this.sourceDetailTarget.textContent = ""
        }
    }

    #show(draft) {
        this.models = (draft?.fields || []).map((f) => ({
            type: f.type,
            label: f.label || "",
            value: f.value,
            address: f.address || null,
            family: this.#family(f.type),
            original: `${f.type}:${f.label || ""}`,
            keep: true,
            reason: f.reason,
            ai: this.usingAi && f.reason !== undefined,
        }))
        this.unsure = [...(draft?.unsure || [])]
        this.draft = draft

        if (draft && !this.nameEdited) this.nameTarget.value = draft.name || ""
        if (draft && !this.partsEdited) this.#setParts(draft.parts && Object.keys(draft.parts).length ? draft.parts : splitName(this.nameTarget.value))

        this.resultTarget.hidden = !draft
        this.#renderRows()
        this.#renderUnsure()
        this.#renderSource()
        this.#renderExisting(draft?.existing || [])

        const skipped = draft?.skipped || []
        this.skippedTarget.hidden = skipped.length === 0
        this.skippedTarget.textContent = skipped.length ? this.stringsValue.skipped.replace("%lines%", skipped.map((l) => `“${l}”`).join(", ")) : ""

        this.#refresh()
    }

    // ── Rows ────────────────────────────────────────────────────────────

    #renderRows() {
        const nodes = this.models.map((model, i) => {
            const node = this.rowTemplateTarget.content.firstElementChild.cloneNode(true)
            const keep = node.querySelector("[data-keep]")
            const kind = node.querySelector("[data-kind]")
            const value = node.querySelector("[data-value]")

            keep.checked = model.keep
            keep.setAttribute("aria-label", `${this.stringsValue.keep}: ${model.value}`)
            keep.addEventListener("change", () => { model.keep = keep.checked; this.#dim(node, model); this.#refresh() })

            for (const [key, text] of this.#kindOptions(model.family)) {
                kind.add(new Option(text, key, false, key === `${model.type}:${model.label}`))
            }
            if (![...kind.options].some((o) => o.selected)) {
                kind.add(new Option(this.#kindText(model.type, model.label), `${model.type}:${model.label}`, true, true), 0)
            }
            const markKind = () => {
                const changed = kind.value !== model.original
                kind.classList.toggle("text-accent", changed)
                kind.classList.toggle("text-ink", !changed)
            }
            markKind()
            kind.addEventListener("change", () => {
                const [type, label] = kind.value.split(":")
                model.type = type
                model.label = label || ""
                if (type !== "address") model.address = null
                markKind()
                this.#refresh()
            })

            value.value = model.value
            value.setAttribute("aria-label", this.#kindText(model.type, model.label))
            value.addEventListener("input", () => { model.value = value.value; model.address = null; this.#refresh() })

            const note = node.querySelector("[data-note]")
            const noteText = model.you ? this.stringsValue.youPlaced : (model.reason || "")
            if (model.ai || noteText) {
                note.hidden = false
                node.querySelector("[data-ai]").hidden = !model.ai
                node.querySelector("[data-note-text]").textContent = noteText
            }

            this.#dim(node, model)
            return node
        })
        this.rowsTarget.replaceChildren(...nodes)
    }

    #dim(node, model) {
        node.classList.toggle("opacity-50", !model.keep)
        const value = node.querySelector("[data-value]")
        value.classList.toggle("line-through", !model.keep)
        value.disabled = !model.keep
        node.querySelector("[data-kind]").disabled = !model.keep
    }

    #renderUnsure() {
        const lines = this.unsure
        this.unsureBoxTarget.hidden = lines.length === 0
        this.unsureTitleTarget.textContent = lines.length > 1 ? this.stringsValue.unsureMany : this.stringsValue.unsureOne
        this.unsureListTarget.replaceChildren(...lines.map((line) => {
            const row = document.createElement("div")
            row.className = "flex items-center gap-2 flex-wrap"
            const text = document.createElement("span")
            text.className = "flex-1 basis-56 min-w-0 text-sm font-mono text-ink break-words"
            text.textContent = line
            const select = document.createElement("select")
            select.className = "h-8 rounded-lg border border-field bg-field px-2 text-sm text-ink shrink-0"
            select.setAttribute("aria-label", `${this.stringsValue.useAs}: ${line}`)
            select.add(new Option(this.stringsValue.useAs, ""))
            for (const type of ["company", "jobTitle", "department", "notes"]) select.add(new Option(this.stringsValue.kinds[type], type))
            select.add(new Option(this.stringsValue.ignore, "ignore"))
            select.addEventListener("change", () => {
                if (!select.value) return
                if (select.value !== "ignore") {
                    this.models.push({ type: select.value, label: "", value: line, address: null, family: "text", original: `${select.value}:`, keep: true, you: true })
                }
                this.unsure = this.unsure.filter((l) => l !== line)
                this.#renderRows()
                this.#renderUnsure()
                this.#renderSource()
                this.#refresh()
            })
            row.append(text, select)
            return row
        }))
    }

    #renderSource() {
        const s = this.stringsValue
        const draft = this.draft
        if (!draft) return
        let badge = s.sourceRules
        let detail = this.unsure.length ? s.detailUnsure.replace("%count%", this.unsure.length) : s.detailRules
        if (draft.source === "vcard") { badge = s.sourceVcard; detail = s.detailVcard.replace("%count%", this.models.length) }
        if (this.usingAi) {
            badge = s.sourceAi
            detail = this.models.some((m) => m.ai) ? s.detailAi : s.detailAiSame
        }
        this.sourceBadgeTarget.textContent = badge
        this.sourceDetailTarget.textContent = detail
        this.#refreshAiBar()
    }

    #renderExisting(existing) {
        const box = this.existingTarget
        box.hidden = existing.length === 0
        box.replaceChildren()
        if (!existing.length) return
        const first = existing[0]
        const text = document.createElement("span")
        text.className = "flex-1 basis-60"
        text.textContent = this.stringsValue.existing.replace("%name%", first.name).replace("%email%", first.email)
        const link = document.createElement("a")
        link.href = first.url
        link.className = "text-accent font-medium hover:underline"
        link.textContent = this.stringsValue.openExisting
        box.append(text, link)
    }

    #refreshAiBar() {
        if (!this.hasAiBarTarget) return
        const s = this.stringsValue
        this.aiBarTarget.hidden = !this.draft || this.draft.source === "vcard"
        this.aiAskTarget.textContent = this.usingAi ? s.aiUndoAsk : (this.unsure.length ? s.aiAsk : s.aiAskComplete)
        this.aiButtonTextTarget.textContent = this.usingAi ? s.aiUndo : s.aiButton
    }

    // ── Name parts ──────────────────────────────────────────────────────

    #parts() {
        const parts = {}
        for (const input of this.partTargets) parts[input.dataset.part] = input.value.trim()
        return parts
    }

    #setParts(parts) {
        for (const input of this.partTargets) input.value = parts[input.dataset.part] || ""
    }

    #renderParts() {
        const parts = this.#parts()
        this.partsSummaryTarget.replaceChildren(...NAME_PARTS.filter((k) => parts[k]).map((k) => {
            const chip = document.createElement("span")
            chip.className = "inline-flex items-baseline gap-1 px-2 py-0.5 rounded-full bg-surface border border-line"
            const label = document.createElement("span")
            label.className = "text-ink-faint"
            label.textContent = this.stringsValue.parts[k] ?? k
            const value = document.createElement("span")
            value.className = "text-ink font-medium"
            value.textContent = parts[k]
            chip.append(label, value)
            return chip
        }))
        this.partsToggleTarget.hidden = Object.values(parts).every((v) => !v) && this.partsPanelTarget.hidden
    }

    // ── What posts ──────────────────────────────────────────────────────

    #refresh() {
        this.#renderParts()
        const name = this.nameTarget.value.trim()
        this.initialsTarget.textContent = (name.charAt(0) || "?").toUpperCase()
        this.createTarget.disabled = name === ""

        const kept = this.models.filter((m) => m.keep && m.value.trim() !== "")
        const s = this.stringsValue
        this.summaryTarget.textContent = name === ""
            ? (this.draft ? s.noName : s.summaryNone)
            : (kept.length === 0 ? s.summaryName.replace("%name%", name)
                : (kept.length === 1 ? s.summaryOne : s.summaryMany).replace("%name%", name).replace("%count%", kept.length))

        this.#writeInputs(kept)
    }

    #writeInputs(kept) {
        const inputs = []
        const add = (name, value) => {
            const input = document.createElement("input")
            input.type = "hidden"
            input.name = name
            input.value = value
            inputs.push(input)
        }
        const count = {}
        const next = (list) => (count[list] = (count[list] ?? -1) + 1)
        const scalars = {}

        for (const m of kept) {
            const value = m.value.trim()
            switch (m.type) {
                case "email":
                case "phone": {
                    const list = m.type === "email" ? "emails" : "phones"
                    const i = next(list)
                    add(`${list}[${i}][value]`, value)
                    add(`${list}[${i}][label]`, m.label || "other")
                    if (i === 0) add(`${list}[${i}][primary]`, "1")
                    break
                }
                case "address": {
                    const i = next("addresses")
                    const parts = m.address || this.#splitAddress(value)
                    for (const key of ["street", "postcode", "city", "region", "country"]) add(`addresses[${i}][${key}]`, parts[key] || "")
                    add(`addresses[${i}][label]`, m.label || "work")
                    break
                }
                case "url": {
                    const i = next("urls")
                    add(`urls[${i}][url]`, /^https?:\/\//i.test(value) ? value : `https://${value}`)
                    add(`urls[${i}][label]`, m.label || "work")
                    break
                }
                case "birthday": {
                    const d = this.#date(value)
                    if (d && !scalars.birthday) {
                        scalars.birthday = true
                        add("birthday[day]", d.day)
                        add("birthday[month]", d.month)
                        add("birthday[year]", d.year)
                    }
                    break
                }
                case "notes":
                    scalars.notes = scalars.notes ? `${scalars.notes}\n${value}` : value
                    break
                default:
                    if (!(m.type in scalars)) scalars[m.type] = value
            }
        }

        for (const [key, value] of Object.entries(scalars)) {
            if (key !== "birthday") add(key, value)
        }

        this.inputsTarget.replaceChildren(...inputs)
    }

    /** "Street 12, 12345 City, Country" back into parts, after somebody edited the line. */
    #splitAddress(line) {
        const m = line.match(/^(.*?),\s*(?:([A-Z]{1,2})[-\s])?(\d{4,5})\s+([^,]+)(?:,\s*(.+))?$/)
        if (m) return { street: m[1], postcode: m[3], city: m[4], country: m[5] || "" }
        return { street: line }
    }

    /** A date in the spellings people paste: 1990-05-21, --05-21, 21.05.1990, 21.5. */
    #date(value) {
        let m = value.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/)
        if (m) return { year: m[1], month: String(+m[2]), day: String(+m[3]) }
        m = value.match(/^--(\d{2})-?(\d{2})$/)
        if (m) return { year: "", month: String(+m[1]), day: String(+m[2]) }
        m = value.match(/^(\d{1,2})\.\s?(\d{1,2})\.(?:\s?(\d{4}))?$/)
        if (m) return { year: m[3] || "", month: String(+m[2]), day: String(+m[1]) }
        return null
    }

    // ── Types ───────────────────────────────────────────────────────────

    #family(type) {
        return type === "email" || type === "phone" ? type : "text"
    }

    #kindText(type, label) {
        const kind = this.stringsValue.kinds[type] ?? type
        return label && this.stringsValue.labels[label] ? `${kind} · ${this.stringsValue.labels[label]}` : kind
    }

    /**
     * What a row can be turned into. An email stays an email and a number a
     * number — only their type changes; a line of text can become any of the
     * text fields, an address or a website.
     */
    #kindOptions(family) {
        const opt = (type, label = "") => [`${type}:${label}`, this.#kindText(type, label)]
        switch (family) {
            case "email": return ["work", "home", "other"].map((l) => opt("email", l))
            case "phone": return ["mobile", "work", "home", "other"].map((l) => opt("phone", l))
            default: return [
                opt("jobTitle"), opt("company"), opt("department"), opt("nickname"), opt("notes"),
                opt("address", "work"), opt("address", "home"), opt("url", "work"), opt("url", "home"), opt("birthday"),
            ]
        }
    }
}
