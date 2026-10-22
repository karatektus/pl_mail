import { Controller } from "@hotwired/stimulus"
import { EMAIL_RE, emailLabel, joinName, looksMobile, NAME_PARTS, postForm, sameNumber, sameText, splitName } from "../../contacts/fields.js"

/**
 * contacts--editor: the contact form (templates/contacts/form.html.twig).
 *
 * The form is complete without this — every input the save reads is in the
 * markup with its name. What this adds:
 *
 *   - the name's parts follow the name as it is typed, until somebody edits a
 *     part by hand (then they stay, and a note says when the two disagree);
 *   - email and phone end in a dashed row, and typing into it makes a real
 *     row, with a type guessed from the address or number (marked, until the
 *     person picks one);
 *   - one preferred row per kind (the star), sections that show when asked
 *     for, and "Add another" for the lists;
 *   - a save bar that appears once something changed and says how much, and
 *     refuses to save an email address that is not one;
 *   - "Paste details" and "Improve with AI", which fill one review list: each
 *     detail found is compared with the form — new, already there, or
 *     different — and only what is ticked is applied, into the form, where it
 *     stays highlighted until saved. Nothing here saves.
 *
 * Every text it shows comes from the template (the `strings` value), so the
 * page speaks the person's language without a second catalogue in JS.
 */
export default class extends Controller {
    static values = {
        parseUrl: String,
        parseToken: String,
        aiUrl: String,
        aiToken: String,
        new: Boolean,
        strings: Object,
    }

    static targets = [
        "name", "initials", "partsSummary", "partsToggle", "partsStale", "partsPanel", "part",
        "panel", "panelTitle", "pasteText", "panelNote", "suggestions", "suggestionTemplate", "panelFoot",
        "suggestSummary", "applyButton", "appliedNote",
        "saveBar", "dirtyText", "saveButton", "tags", "tagChips",
    ]

    connect() {
        this.snapshot = this.#serialize()
        // Parts follow the name only while they still say the same thing:
        // a saved contact whose parts were set by hand keeps them.
        const parts = this.#parts()
        this.autoParts = Object.values(parts).every((v) => v === "") || joinName(parts) === this.nameTarget.value.trim()
        this.suggestionsList = []
        this.#renderParts()
        this.#renderTags()
        this.#refresh()
    }

    disconnect() {
        this.abort?.abort()
        clearTimeout(this.pasteTimer)
    }

    // ── Anything changed ────────────────────────────────────────────────

    changed(event) {
        const target = event.target
        const row = target.closest?.("[data-row]")

        // A guessed type follows the value until somebody picks one.
        if (row && row.dataset.auto && target.matches("[data-row-value]")) {
            this.#guessLabel(row)
        }
        if (row && target.matches("select") && target.name.endsWith("[label]")) {
            delete row.dataset.auto
            row.querySelector("[data-auto-mark]")?.setAttribute("hidden", "")
        }
        if (this.hasTagsTarget && target === this.tagsTarget) {
            this.#renderTags()
        }

        this.#refresh()
    }

    // ── The name ────────────────────────────────────────────────────────

    nameTyped() {
        if (this.autoParts) {
            const parts = splitName(this.nameTarget.value)
            for (const input of this.partTargets) input.value = parts[input.dataset.part] || ""
        }
        if (this.hasInitialsTarget) {
            this.initialsTarget.textContent = (this.nameTarget.value.trim().charAt(0) || "?").toUpperCase()
        }
        this.#renderParts()
    }

    partTyped() {
        // The name follows the parts when it said what they said a moment
        // ago; a name that was something else ("Mum") is left alone.
        const before = this.lastJoined ?? ""
        if (this.nameTarget.value.trim() === before) {
            this.nameTarget.value = joinName(this.#parts())
        }
        this.autoParts = false
        this.#renderParts()
    }

    toggleParts() {
        const open = this.partsPanelTarget.hidden
        this.partsPanelTarget.hidden = !open
        this.partsToggleTarget.setAttribute("aria-expanded", String(open))
        this.partsToggleTarget.textContent = open ? this.stringsValue.hideParts : this.stringsValue.editParts
    }

    resplit() {
        this.autoParts = true
        this.nameTyped()
        this.#refresh()
    }

    // ── Rows ────────────────────────────────────────────────────────────

    ghostTyped(event) {
        const ghost = event.target
        const value = ghost.value
        if (value.trim() === "") return

        const row = this.#addRow(ghost.dataset.ghost)
        const input = row.querySelector("[data-row-value]")
        input.value = value
        row.dataset.auto = "1"
        this.#guessLabel(row)
        ghost.value = ""
        input.focus()
        input.setSelectionRange?.(value.length, value.length)
        this.#refresh()
    }

    addRow({ params: { list } }) {
        const row = this.#addRow(list)
        row.querySelector("input, select")?.focus()
        this.#refresh()
    }

    removeRow(event) {
        const row = event.currentTarget.closest("[data-row]")
        const list = row?.parentElement
        const wasPrimary = !!row?.querySelector("[data-row-primary]:not([disabled])")
        row?.remove()
        if (wasPrimary && list) {
            const first = list.querySelector("[data-row]")
            if (first) this.#setPrimary(first)
        }
        // A list section with no rows left goes back to being a chip — the
        // section has no remove of its own, its rows' are enough.
        const section = list?.closest("[data-section]")
        if (section && !section.querySelector("[data-row]") && !section.querySelector("[data-birthday]")) {
            section.hidden = true
            this.element.querySelector(`[data-chip="${section.dataset.section}"]`)?.removeAttribute("hidden")
        }
        this.#refresh()
    }

    makePrimary(event) {
        this.#setPrimary(event.currentTarget.closest("[data-row]"))
        this.#refresh()
    }

    // ── Sections ────────────────────────────────────────────────────────

    addSection({ params: { section } }) {
        const el = this.#showSection(section)
        const list = el?.querySelector("[data-list-rows]")
        if (list && !list.querySelector("[data-row]")) this.#addRow(list.dataset.listRows)
        el?.querySelector("input:not([type=hidden]), select, textarea")?.focus()
        this.#refresh()
    }

    discard() {
        const frame = this.element.closest("turbo-frame")
        if (frame?.src) frame.reload()
        else window.location.reload()
    }

    // ── Paste details and Improve with AI ───────────────────────────────

    openPaste() {
        this.mode = "paste"
        this.#openPanel(this.stringsValue.pasteTitle)
        this.pasteTextTarget.hidden = false
        this.pasteTextTarget.focus()
        if (this.pasteTextTarget.value.trim() !== "") this.pasted()
    }

    pasted() {
        clearTimeout(this.pasteTimer)
        this.pasteTimer = setTimeout(() => this.#readPaste(), 350)
    }

    async improve(event) {
        const button = event?.currentTarget
        this.mode = "ai"
        this.#openPanel(this.stringsValue.aiTitle)
        this.pasteTextTarget.hidden = true
        this.#note(this.stringsValue.aiRunning)
        this.#setSuggestions([])
        button?.setAttribute("disabled", "")

        this.abort?.abort()
        this.abort = new AbortController()
        try {
            const answer = await postForm(this.aiUrlValue, this.aiTokenValue, {}, this.abort.signal)
            const list = []
            if (answer.name) list.push({ kind: "name", label: "", value: answer.name.value, reason: answer.name.reason || "", ai: true })
            for (const f of answer.fields || []) list.push(this.#fromField(f, true))
            const when = answer.signature?.receivedAt ? new Date(answer.signature.receivedAt).toLocaleDateString() : null
            this.#note(when ? this.stringsValue.aiSent.replace("%date%", when) : this.stringsValue.aiSentNoMail)
            this.#setSuggestions(list)
            if (list.length === 0) this.#note(this.stringsValue.aiNothing)
        } catch (error) {
            if (error.name === "AbortError") return
            if (error.status === 409) {
                this.element.querySelector("[data-contacts-ai-button]")?.remove()
            }
            this.#note(this.stringsValue.aiFailed)
        } finally {
            button?.removeAttribute("disabled")
        }
    }

    closePanel() {
        this.abort?.abort()
        this.panelTarget.hidden = true
    }

    apply() {
        let applied = 0
        for (const s of this.suggestionsList) {
            if (!s.checked || s.status === "same") continue
            this.#applyOne(s)
            applied++
        }
        this.closePanel()
        if (applied > 0) {
            this.appliedNoteTarget.textContent = this.stringsValue.applied.replace("%count%", applied)
            this.appliedNoteTarget.hidden = false
        }
        this.#renderParts()
        this.#refresh()
    }

    // ── Internals: rows ─────────────────────────────────────────────────

    #addRow(list) {
        const container = this.element.querySelector(`[data-list-rows="${list}"]`)
        const template = this.element.querySelector(`template[data-list-template="${list}"]`)
        if (!container || !template) return null

        const index = parseInt(container.dataset.nextIndex || "0", 10)
        container.dataset.nextIndex = String(index + 1)

        const holder = document.createElement("div")
        holder.innerHTML = template.innerHTML.replaceAll("__INDEX__", String(index))
        const row = holder.firstElementChild

        const ghost = container.querySelector("[data-ghost]")
        container.insertBefore(row, ghost)

        // The first email or number is the preferred one.
        if (row.querySelector("[data-row-primary]") && container.querySelectorAll("[data-row]").length === 1) {
            this.#setPrimary(row)
        }

        const section = container.closest("[data-section]")
        if (section?.hidden) this.#showSection(section.dataset.section)

        return row
    }

    #setPrimary(row) {
        const list = row.parentElement
        for (const other of list.querySelectorAll("[data-row]")) {
            const on = other === row
            const input = other.querySelector("[data-row-primary]")
            const star = other.querySelector("[data-star]")
            if (!input || !star) continue
            input.disabled = !on
            star.setAttribute("aria-pressed", String(on))
            star.classList.toggle("text-accent", on)
            const icon = star.querySelector("i")
            icon?.classList.toggle("fa-solid", on)
            icon?.classList.toggle("fa-regular", !on)
        }
    }

    #guessLabel(row) {
        const value = row.querySelector("[data-row-value]")?.value || ""
        const select = row.querySelector("select[name$='[label]']")
        if (!select) return
        const list = row.closest("[data-list-rows]")?.dataset.listRows
        let label = select.value
        if (list === "emails") label = emailLabel(value)
        if (list === "phones" && looksMobile(value)) label = "mobile"
        if ([...select.options].some((o) => o.value === label)) select.value = label
        row.querySelector("[data-auto-mark]")?.removeAttribute("hidden")
    }

    #showSection(key) {
        const el = this.element.querySelector(`[data-section="${key}"]`)
        if (el) el.hidden = false
        this.element.querySelector(`[data-chip="${key}"]`)?.setAttribute("hidden", "")
        return el
    }

    // ── Internals: name parts, tags, save bar ───────────────────────────

    #parts() {
        const parts = {}
        for (const input of this.partTargets) parts[input.dataset.part] = input.value.trim()
        return parts
    }

    #renderParts() {
        const parts = this.#parts()
        this.lastJoined = joinName(parts)
        this.partsSummaryTarget.replaceChildren(...NAME_PARTS.filter((k) => parts[k]).map((k) => {
            const chip = document.createElement("span")
            chip.className = "inline-flex items-baseline gap-1 px-2 py-0.5 rounded-full bg-sunken"
            const label = document.createElement("span")
            label.className = "text-ink-faint"
            label.textContent = this.stringsValue.parts?.[k] ?? k
            const value = document.createElement("span")
            value.className = "text-ink font-medium"
            value.textContent = parts[k]
            chip.append(label, value)
            return chip
        }))
        this.partsStaleTarget.hidden = this.autoParts || this.lastJoined === "" || this.lastJoined === this.nameTarget.value.trim()
    }

    #renderTags() {
        if (!this.hasTagsTarget) return
        const tags = this.tagsTarget.value.split(",").map((t) => t.trim()).filter(Boolean)
        this.tagChipsTarget.replaceChildren(...tags.map((t) => {
            const chip = document.createElement("span")
            chip.className = "px-2 py-0.5 rounded-full bg-accent-soft text-accent text-xs font-medium"
            chip.textContent = t
            return chip
        }))
    }

    #refresh() {
        let invalid = 0
        for (const row of this.element.querySelectorAll('[data-list-rows="emails"] [data-row]')) {
            const input = row.querySelector("[data-row-value]")
            const bad = input.value.trim() !== "" && !EMAIL_RE.test(input.value.trim())
            input.classList.toggle("border-danger", bad)
            input.setAttribute("aria-invalid", String(bad))
            row.querySelector("[data-row-error]").hidden = !bad
            if (bad) invalid++
        }

        const count = this.#changedCount()
        const show = this.newValue || count > 0
        this.saveBarTarget.hidden = !show
        this.saveButtonTarget.disabled = invalid > 0
        if (invalid > 0) {
            this.dirtyTextTarget.textContent = this.stringsValue.fixEmails
        } else if (count > 0) {
            this.dirtyTextTarget.textContent = (count === 1 ? this.stringsValue.dirtyOne : this.stringsValue.dirtyMany).replace("%count%", count)
        }
    }

    /**
     * The form as the units a person thinks in: one entry per row of a list
     * (`emails[3]` — its value, type and star together) and one per single
     * field, each the row's values joined. Rows keep their index for the
     * life of the page (removing one renumbers nothing), so a row present in
     * both and different is one change, and a row in only one is one more.
     */
    #serialize() {
        const out = {}
        const data = new FormData(this.element)
        for (const [key, value] of data.entries()) {
            if (key === "_token" || typeof value !== "string") continue
            const unit = key.match(/^[^[]+\[\d+\]/)?.[0] ?? key.replace(/\[.*$/, "")
            out[unit] = out[unit] === undefined ? value.trim() : `${out[unit]}\u001f${value.trim()}`
        }
        // An empty single field and a missing one are the same thing.
        for (const [unit, value] of Object.entries(out)) if (value.replace(/\u001f/g, "") === "") delete out[unit]
        return out
    }

    #changedCount() {
        const now = this.#serialize()
        const units = new Set([...Object.keys(now), ...Object.keys(this.snapshot)])
        let count = 0
        for (const unit of units) if (now[unit] !== this.snapshot[unit]) count++
        return count
    }

    // ── Internals: the review list ──────────────────────────────────────

    #openPanel(title) {
        this.panelTarget.hidden = false
        this.panelTitleTarget.textContent = title
        this.appliedNoteTarget.hidden = true
        this.#note("")
        this.#setSuggestions([])
    }

    #note(text) {
        this.panelNoteTarget.textContent = text
        this.panelNoteTarget.hidden = text === ""
    }

    async #readPaste() {
        const text = this.pasteTextTarget.value
        if (text.trim() === "") {
            this.#note("")
            this.#setSuggestions([])
            return
        }
        this.#note(this.stringsValue.reading)
        this.abort?.abort()
        this.abort = new AbortController()
        try {
            const draft = await postForm(this.parseUrlValue, this.parseTokenValue, { text }, this.abort.signal)
            const list = []
            if (draft.name) list.push({ kind: "name", label: "", value: draft.name, ai: false })
            for (const f of draft.fields || []) list.push(this.#fromField(f, false))
            this.#setSuggestions(list)
            this.#note(list.length ? "" : this.stringsValue.nothingFound)
        } catch (error) {
            if (error.name !== "AbortError") this.#note(this.stringsValue.nothingFound)
        }
    }

    #fromField(f, ai) {
        return {
            kind: f.type,
            label: f.label || "",
            value: f.value,
            address: f.address || null,
            reason: f.reason,
            ai: ai && f.reason !== undefined,
        }
    }

    #setSuggestions(list) {
        this.suggestionsList = list.map((s) => this.#compare(s))
        this.#renderSuggestions()
    }

    /**
     * Where a found detail stands against the form: `same` (nothing to do),
     * `new` (adds a row), `fill` (an empty field), `update` (the same email
     * or number with another type or spelling), `conflict` (a different
     * value where there can only be one). New and filled start ticked; a
     * change to something already there starts unticked unless the AI was
     * asked for exactly that.
     */
    #compare(s) {
        const out = { ...s, status: "new", current: "", target: null }

        if (s.kind === "name") {
            const current = this.nameTarget.value.trim()
            const words = current.toLowerCase().split(/\s+/)
            if (current === "") out.status = "fill"
            else if (sameText(current, s.value) || s.value.toLowerCase().split(/\s+/).every((w) => words.includes(w))) out.status = "same"
            else { out.status = "conflict"; out.current = current }
        } else if (s.kind === "email" || s.kind === "phone") {
            const list = s.kind === "email" ? "emails" : "phones"
            for (const row of this.element.querySelectorAll(`[data-list-rows="${list}"] [data-row]`)) {
                const value = row.querySelector("[data-row-value]").value
                const match = s.kind === "email" ? sameText(value, s.value) : sameNumber(value, s.value)
                if (!match) continue
                const label = row.querySelector("select[name$='[label]']").value
                out.target = row
                out.current = value
                out.status = label === s.label && value.trim() === s.value.trim() ? "same" : "update"
                out.currentLabel = label
                break
            }
        } else if (s.kind === "address") {
            for (const row of this.element.querySelectorAll('[data-list-rows="addresses"] [data-row]')) {
                const street = row.querySelector('[data-address-part="street"]').value
                const postcode = row.querySelector('[data-address-part="postcode"]').value
                if (sameText(street, s.address?.street ?? s.value) && sameText(postcode, s.address?.postcode ?? postcode)) { out.status = "same"; break }
            }
        } else if (s.kind === "url") {
            const bare = (v) => (v || "").trim().toLowerCase().replace(/^https?:\/\//, "").replace(/^www\./, "").replace(/\/$/, "")
            for (const row of this.element.querySelectorAll('[data-list-rows="urls"] [data-row]')) {
                if (bare(row.querySelector("[data-row-value]").value) === bare(s.value)) { out.status = "same"; break }
            }
        } else if (s.kind === "birthday") {
            const current = this.#birthday()
            if (current === "") out.status = "fill"
            else if (current === s.value) out.status = "same"
            else { out.status = "conflict"; out.current = current }
        } else {
            const input = this.element.querySelector(`[data-field="${s.kind}"]`)
            const current = input?.value.trim() ?? ""
            if (current === "") out.status = "fill"
            else if (sameText(current, s.value)) out.status = "same"
            else { out.status = "conflict"; out.current = current }
        }

        out.checked = s.checked ?? (out.status === "new" || out.status === "fill" || (out.status === "update" && s.ai))
        return out
    }

    #renderSuggestions() {
        const strings = this.stringsValue
        const rows = this.suggestionsList.map((s, i) => {
            const node = this.suggestionTemplateTarget.content.firstElementChild.cloneNode(true)
            const check = node.querySelector("[data-check]")
            const locked = s.status === "same"
            check.checked = locked || s.checked
            check.disabled = locked
            check.setAttribute("aria-label", `${this.#kindText(s)}: ${s.value}`)
            check.addEventListener("change", () => { this.suggestionsList[i].checked = check.checked; this.#renderFoot() })
            if (locked) node.classList.add("opacity-60")

            const kinds = this.#kindOptions(s)
            if (kinds && !locked) {
                const select = node.querySelector("[data-kind-select]")
                select.hidden = false
                node.querySelector("[data-kind]").hidden = true
                for (const [value, text] of kinds) select.add(new Option(text, value, false, value === this.#kindKey(s)))
                select.setAttribute("aria-label", strings.kinds[s.kind] ?? s.kind)
                select.addEventListener("change", () => {
                    const [kind, label] = select.value.split(":")
                    this.suggestionsList[i] = this.#compare({ ...s, kind, label: label || "", checked: undefined })
                    this.#renderSuggestions()
                })
            } else {
                node.querySelector("[data-kind]").textContent = this.#kindText(s)
            }

            node.querySelector("[data-value]").textContent = s.value
            node.querySelector("[data-ai]").hidden = !s.ai
            const noteText = node.querySelector("[data-note-text]")
            const status = this.#statusText(s)
            noteText.textContent = s.reason ? (status ? `${s.reason} · ${status}` : s.reason) : status
            node.querySelector("[data-note]").classList.add(s.status === "conflict" ? "text-warning" : "text-ink-muted")
            return node
        })
        this.suggestionsTarget.replaceChildren(...rows)
        this.#renderFoot()
    }

    #renderFoot() {
        const strings = this.stringsValue
        const list = this.suggestionsList
        this.panelFootTarget.hidden = list.length === 0
        const n = (status) => list.filter((s) => status.includes(s.status)).length
        const parts = []
        if (n(["new", "fill", "update"])) parts.push(strings.summaryNew.replace("%count%", n(["new", "fill", "update"])))
        if (n(["conflict"])) parts.push(strings.summaryConflict.replace("%count%", n(["conflict"])))
        if (n(["same"])) parts.push(strings.summarySame.replace("%count%", n(["same"])))
        this.suggestSummaryTarget.textContent = parts.join(" · ")

        const picked = list.filter((s) => s.checked && s.status !== "same").length
        this.applyButtonTarget.disabled = picked === 0
        this.applyButtonTarget.textContent = picked === 0 ? strings.applyNone
            : (picked === 1 ? strings.applyOne : strings.applyMany.replace("%count%", picked))
    }

    #statusText(s) {
        const strings = this.stringsValue
        switch (s.status) {
            case "same": return strings.same
            case "fill": return strings.fills
            case "conflict": return strings.replaces.replace("%value%", s.current)
            case "update":
                return s.currentLabel && s.currentLabel !== s.label
                    ? strings.relabels.replace("%from%", strings.labels[s.currentLabel] ?? s.currentLabel).replace("%to%", strings.labels[s.label] ?? s.label)
                    : strings.replaces.replace("%value%", s.current)
            default: return strings.adds
        }
    }

    #kindText(s) {
        const kind = this.stringsValue.kinds[s.kind] ?? s.kind
        return s.label && this.stringsValue.labels[s.label] ? `${kind} · ${this.stringsValue.labels[s.label]}` : kind
    }

    #kindKey(s) {
        return `${s.kind}:${s.label || ""}`
    }

    /**
     * What a found detail can be changed into, without retyping it: another
     * type of email or number, another kind of address or website, or — for
     * a line of text — another of the one-line fields. A number does not
     * become a company; the families stay apart.
     */
    #kindOptions(s) {
        const k = this.stringsValue.kinds
        const l = this.stringsValue.labels
        const labelled = (kind, labels) => labels.map((label) => [`${kind}:${label}`, `${k[kind]} · ${l[label]}`])
        switch (s.kind) {
            case "email": return labelled("email", ["home", "work", "other"])
            case "phone": return labelled("phone", ["mobile", "home", "work", "other"])
            case "address": return labelled("address", ["home", "work", "other"])
            case "url": return labelled("url", ["home", "work", "blog", "profile", "other"])
            case "jobTitle": case "company": case "department": case "nickname": case "notes":
                return ["jobTitle", "company", "department", "nickname", "notes"].map((kind) => [`${kind}:`, k[kind]])
            default: return null
        }
    }

    #birthday() {
        const day = this.element.querySelector('[name="birthday[day]"]')?.value
        const month = this.element.querySelector('[name="birthday[month]"]')?.value
        const year = this.element.querySelector('[name="birthday[year]"]')?.value
        if (!day || !month) return ""
        const md = `${String(month).padStart(2, "0")}-${String(day).padStart(2, "0")}`
        return year ? `${year}-${md}` : `--${md}`
    }

    // ── Internals: applying ─────────────────────────────────────────────

    #applyOne(s) {
        const mark = (el) => el?.classList.add("!bg-accent-soft", "!border-accent")
        const setLabel = (row, label) => {
            const select = row.querySelector("select[name$='[label]']")
            if (select && [...select.options].some((o) => o.value === label)) select.value = label
        }

        switch (s.kind) {
            case "name": {
                this.nameTarget.value = s.value
                this.autoParts = true
                this.nameTyped()
                mark(this.nameTarget)
                return
            }
            case "email":
            case "phone": {
                const list = s.kind === "email" ? "emails" : "phones"
                const row = s.status === "update" ? s.target : this.#addRow(list)
                const input = row.querySelector("[data-row-value]")
                input.value = s.value
                setLabel(row, s.label)
                mark(input)
                return
            }
            case "address": {
                const row = this.#addRow("addresses")
                const parts = s.address || { street: s.value }
                for (const [key, value] of Object.entries(parts)) {
                    const input = row.querySelector(`[data-address-part="${key}"]`)
                    if (input) { input.value = value; mark(input) }
                }
                setLabel(row, s.label)
                return
            }
            case "url": {
                const row = this.#addRow("urls")
                const input = row.querySelector("[data-row-value]")
                input.value = /^https?:\/\//i.test(s.value) ? s.value : `https://${s.value}`
                setLabel(row, s.label)
                mark(input)
                return
            }
            case "birthday": {
                this.#showSection("dates")
                const m = s.value.match(/^(?:(\d{4})|-)-(\d{2})-(\d{2})$/)
                if (!m) return
                const set = (name, value) => {
                    const el = this.element.querySelector(`[name="${name}"]`)
                    if (el) { el.value = value; mark(el) }
                }
                set("birthday[day]", String(parseInt(m[3], 10)))
                set("birthday[month]", String(parseInt(m[2], 10)))
                set("birthday[year]", m[1] || "")
                return
            }
            default: {
                const input = this.element.querySelector(`[data-field="${s.kind}"]`)
                if (!input) return
                const section = input.closest("[data-section]")
                if (section) this.#showSection(section.dataset.section)
                const more = input.closest("details")
                if (more) more.open = true
                input.value = s.value
                mark(input)
            }
        }
    }
}
