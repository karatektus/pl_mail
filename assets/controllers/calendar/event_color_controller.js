/* stimulusFetch: 'lazy' */
import { Controller } from "@hotwired/stimulus"

/**
 * The event editor's colour picker (#616): the dot in the title field and the
 * popover it opens. Markup and the reasons for it: calendar/_event_color.
 *
 * The popover is native and opened by the dot's `popovertarget`, so opening,
 * closing on Escape and closing on an outside click are the browser's. What is
 * left here is where it opens (under the dot — a popover in the top layer is
 * centred on the screen until somebody says otherwise), what a pick does, and
 * the search.
 *
 * **The search looks through `named`, never the whole CSS table.** Those are the
 * forty colours with a translated label; searching 147 names would turn up
 * "lightgoldenrodyellow" in a German interface. A typed hex ("#ff8800") offers
 * the nearest of the forty instead, because COLOR on the wire only takes names.
 * Colours the user has used come first, in the order "Often used" shows them.
 *
 * Values:
 *   named        — [{value, hex, label}], the colours the search looks through
 *   often        — the values "Often used" shows, most used first
 *   calendarHex  — what the event is drawn in without a colour of its own
 *   labels       — {calendar, button, nearest, none}, translated, with
 *                  %color% / %hex% left in for this file to fill
 *
 * Targets:
 *   input        — the hidden `color` field the form posts
 *   dot, chevron — the button in the title field, and its glyph
 *   panel        — the popover
 *   query        — its search field
 *   calendarRow, calendarDot — the "Calendar colour" choice
 *   browse       — the swatches, shown while nothing is typed
 *   results      — the list shown while something is
 *   peekDot, peekName — the line naming the colour under the pointer
 */
export default class extends Controller {
    static targets = [
        "input", "dot", "chevron", "panel", "query",
        "calendarRow", "calendarDot", "browse", "results", "peekDot", "peekName",
    ]

    static values = {
        named:       Array,
        often:       Array,
        calendarHex: String,
        labels:      Object,
    }

    connect() {
        this.matches = []
        this.active = 0

        this.onToggle = (event) => this.#toggled(event)
        this.panelTarget.addEventListener("beforetoggle", this.onToggle)

        // The calendar the event falls back to is whichever box is ticked
        // first, and that can change while the editor is open.
        this.form = this.element.closest("form")
        this.onFormChange = (event) => {
            if ("calendars[]" === event.target.name) {
                this.#followCalendar()
            }
        }
        this.form?.addEventListener("change", this.onFormChange)
    }

    disconnect() {
        this.panelTarget.removeEventListener("beforetoggle", this.onToggle)
        this.form?.removeEventListener("change", this.onFormChange)
    }

    /** A swatch, a search result or the calendar row was chosen. */
    pick(event) {
        const { value = "" } = event.params

        this.#choose(String(value))
        this.panelTarget.hidePopover?.()
        this.dotTarget.focus()
    }

    /** Name the colour under the pointer or the keyboard. */
    peek(event) {
        const value = event.currentTarget.dataset.value ?? ""

        this.#showPeek(value)
    }

    search() {
        const query = this.queryTarget.value.trim()

        if ("" === query) {
            this.matches = []
            this.resultsTarget.hidden = true
            this.browseTarget.hidden = false
            this.calendarRowTarget.hidden = false
            this.queryTarget.setAttribute("aria-expanded", "false")
            this.queryTarget.removeAttribute("aria-activedescendant")

            return
        }

        const { matches, nearestOf } = this.#find(query)
        const showsCalendar = this.#fold(this.labelsValue.calendar ?? "").includes(this.#fold(query))

        this.matches = matches
        this.active = 0
        this.browseTarget.hidden = true
        this.calendarRowTarget.hidden = false === showsCalendar
        this.resultsTarget.hidden = false
        this.queryTarget.setAttribute("aria-expanded", "true")
        this.#renderResults(nearestOf, showsCalendar)
    }

    /** Enter picks the highlighted result; the arrows move the highlight. */
    key(event) {
        if (0 === this.matches.length) {
            if ("Enter" === event.key) {
                event.preventDefault()

                // "Calendar colour" is the one thing left that a search can
                // match, and the only sensible reading of Enter on it.
                if (false === this.calendarRowTarget.hidden && "" !== this.queryTarget.value.trim()) {
                    this.#choose("")
                    this.panelTarget.hidePopover?.()
                    this.dotTarget.focus()
                }
            }

            return
        }

        if ("ArrowDown" === event.key || "ArrowUp" === event.key) {
            event.preventDefault()
            const step = "ArrowDown" === event.key ? 1 : -1
            this.active = (this.active + step + this.matches.length) % this.matches.length
            this.#highlight()

            return
        }

        if ("Enter" === event.key) {
            event.preventDefault()
            this.#choose(this.matches[this.active].value)
            this.panelTarget.hidePopover?.()
            this.dotTarget.focus()
        }
    }

    // ── Private ──────────────────────────────────────────────────────────

    #toggled(event) {
        if ("open" !== event.newState) {
            return
        }

        this.queryTarget.value = ""
        this.search()
        this.#place()
        this.#showPeek(this.inputTarget.value)

        // After the popover is shown, or the focus call lands on an element
        // that is not rendered yet and does nothing.
        requestAnimationFrame(() => this.queryTarget.focus())
    }

    /**
     * Under the dot, kept inside the screen; above it when there is not room
     * below. Fixed coordinates, because a popover's containing block is the
     * viewport whatever the page around the dot is doing.
     */
    #place() {
        const panel = this.panelTarget
        const box = this.dotTarget.getBoundingClientRect()
        const margin = 8

        panel.style.position = "fixed"
        panel.style.inset = "auto"

        const width = panel.offsetWidth || 296
        const height = panel.offsetHeight || 380
        const left = Math.max(margin, Math.min(box.left - 6, window.innerWidth - width - margin))
        const below = box.bottom + 6
        const top = below + height <= window.innerHeight - margin
            ? below
            : Math.max(margin, box.top - 6 - height)

        panel.style.left = `${left}px`
        panel.style.top = `${top}px`
    }

    #choose(value) {
        this.inputTarget.value = value

        const hex = this.#hexOf(value) ?? this.calendarHexValue
        const label = "" === value ? this.labelsValue.calendar : this.#labelOf(value)

        this.dotTarget.style.backgroundColor = hex
        this.chevronTarget.style.color = this.#inkOn(hex)

        const button = (this.labelsValue.button ?? "%color%").replace("%color%", label)
        this.dotTarget.setAttribute("aria-label", button)
        this.dotTarget.setAttribute("title", button)

        this.element.querySelectorAll("[data-value]").forEach((swatch) => {
            const pressed = swatch.dataset.value.toLowerCase() === value.toLowerCase()
            swatch.setAttribute("aria-pressed", pressed ? "true" : "false")
        })
    }

    #showPeek(value) {
        const hex = this.#hexOf(value) ?? this.calendarHexValue

        this.peekDotTarget.style.backgroundColor = hex
        this.peekNameTarget.textContent = "" === value ? this.labelsValue.calendar : this.#labelOf(value)
    }

    #followCalendar() {
        const ticked = this.form?.querySelector("input[name='calendars[]']:checked[data-color]")

        if (null === ticked || undefined === ticked) {
            return
        }

        this.calendarHexValue = ticked.dataset.color
        this.calendarDotTarget.style.backgroundColor = this.calendarHexValue

        if ("" === this.inputTarget.value) {
            this.dotTarget.style.backgroundColor = this.calendarHexValue
            this.chevronTarget.style.color = this.#inkOn(this.calendarHexValue)
        }
    }

    /**
     * Matching names, used ones first. `nearestOf` is the typed hex when the
     * one result is a stand-in for it rather than a match.
     */
    #find(query) {
        const hex = /^#?([0-9a-f]{6}|[0-9a-f]{3})$/i.exec(query)

        if (null !== hex) {
            const full = this.#expand(hex[1])
            const nearest = this.#nearest(full)

            return { matches: null === nearest ? [] : [nearest], nearestOf: `#${full}` }
        }

        const folded = this.#fold(query)
        const rank = (color) => {
            const used = this.oftenValue.indexOf(color.value)

            return -1 === used ? this.oftenValue.length : used
        }
        const starts = (color) => (this.#fold(color.label).startsWith(folded) ? 0 : 1)

        const matches = this.namedValue
            .filter((color) => this.#fold(color.label).includes(folded) || color.value.includes(folded))
            .sort((a, b) => rank(a) - rank(b) || starts(a) - starts(b))

        return { matches, nearestOf: null }
    }

    #renderResults(nearestOf, showsCalendar) {
        const list = this.resultsTarget

        list.replaceChildren()

        this.matches.forEach((color, index) => {
            const row = document.createElement("button")
            row.type = "button"
            row.id = `event-color-result-${index}`
            row.setAttribute("role", "option")
            row.dataset.value = color.value
            row.dataset.action = "calendar--event-color#pick mouseenter->calendar--event-color#peek"
            row.setAttribute("data-calendar--event-color-value-param", color.value)
            row.setAttribute("aria-pressed", color.value === this.inputTarget.value ? "true" : "false")
            row.className = "flex w-full items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-left text-sm text-ink cursor-pointer "
                + "hover:bg-hover aria-pressed:font-medium data-[active]:bg-hover focus:outline-none"

            const dot = document.createElement("span")
            dot.className = "size-5 shrink-0 rounded-full ring-1 ring-inset ring-line"
            dot.style.backgroundColor = color.hex
            dot.setAttribute("aria-hidden", "true")

            const name = document.createElement("span")
            name.className = "min-w-0 flex-1 truncate"
            name.textContent = color.label

            row.append(dot, name)

            if (null !== nearestOf) {
                const note = document.createElement("span")
                note.className = "text-xs text-ink-faint"
                note.textContent = (this.labelsValue.nearest ?? "%hex%").replace("%hex%", nearestOf)
                row.append(note)
            }

            list.append(row)
        })

        if (0 === this.matches.length && false === showsCalendar) {
            const none = document.createElement("p")
            none.className = "px-2.5 py-3 text-sm text-ink-muted"
            none.textContent = this.labelsValue.none ?? ""
            list.append(none)
        }

        this.#highlight()
    }

    #highlight() {
        this.resultsTarget.querySelectorAll("[role='option']").forEach((row, index) => {
            const on = index === this.active
            row.toggleAttribute("data-active", on)
            row.setAttribute("aria-selected", on ? "true" : "false")

            if (on) {
                this.queryTarget.setAttribute("aria-activedescendant", row.id)
                row.scrollIntoView({ block: "nearest" })
                this.#showPeek(row.dataset.value)
            }
        })
    }

    #hexOf(value) {
        if ("" === value) {
            return null
        }

        const named = this.namedValue.find((color) => color.value === value.toLowerCase())

        if (undefined !== named) {
            return named.hex
        }

        // A colour outside the forty — a phone's "lightsteelblue", a client's
        // "#ff8800" — is drawn by the swatch the server rendered for it.
        const swatch = this.element.querySelector(`[data-value="${CSS.escape(value)}"][data-hex]`)

        return swatch?.dataset.hex ?? null
    }

    #labelOf(value) {
        const named = this.namedValue.find((color) => color.value === value.toLowerCase())

        if (undefined !== named) {
            return named.label
        }

        const swatch = this.element.querySelector(`[data-value="${CSS.escape(value)}"][data-label]`)

        return swatch?.dataset.label ?? value
    }

    #nearest(hex) {
        const want = this.#channels(hex)
        let best = null
        let distance = Infinity

        this.namedValue.forEach((color) => {
            const have = this.#channels(color.hex.slice(1))
            const d = (have[0] - want[0]) ** 2 + (have[1] - want[1]) ** 2 + (have[2] - want[2]) ** 2

            if (d < distance) {
                distance = d
                best = color
            }
        })

        return best
    }

    #expand(hex) {
        return (3 === hex.length ? hex.split("").map((c) => c + c).join("") : hex).toLowerCase()
    }

    #channels(hex) {
        return [0, 2, 4].map((at) => parseInt(hex.slice(at, at + 2), 16))
    }

    /** Ink or white on a colour; the same rule as EventColor::onColor(). */
    #inkOn(hex) {
        const luminance = (h) => {
            const [r, g, b] = this.#channels(h.replace("#", "")).map((c) => {
                const v = c / 255

                return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4
            })

            return 0.2126 * r + 0.7152 * g + 0.0722 * b
        }

        const l = luminance(hex)
        const ink = luminance("#232220")

        return 1.05 / (l + 0.05) >= (l + 0.05) / (ink + 0.05) ? "#ffffff" : "#232220"
    }

    /** Lower case, no accents, no spaces: "Dunkelgrün" finds "dunkelgrun". */
    #fold(text) {
        return text.normalize("NFD").replace(/[̀-ͯ]/g, "").replace(/\s+/g, "").toLowerCase()
    }
}
