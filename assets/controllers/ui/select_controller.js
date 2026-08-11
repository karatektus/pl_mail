import { Controller } from "@hotwired/stimulus"
import TomSelect from "tom-select"

/**
 * Replaces a native <select> with a Tom Select the app can actually style.
 *
 * `appearance: none` gets the closed control looking right — that is what the
 * `select-field` utility does — but the popup it opens is drawn by the OS and
 * no stylesheet can reach inside it. `color-scheme` gets it to at least pick
 * light or dark, and that is where CSS stops: the list is still the platform's
 * font, the platform's row height and the platform's blue highlight sitting in
 * front of a themed app. Compose already had a Tom Select for its address
 * fields, themed from the palette tokens; this is that same widget applied to
 * every other select, so there is one dropdown in the app instead of two.
 *
 * Progressive enhancement, not replacement. The original <select> stays in the
 * DOM as the source of truth — Tom Select writes back to it and dispatches a
 * real `change` event on it, which is what keeps `onchange="…requestSubmit()"`,
 * `data-action="change->…"` and plain form submission working untouched. With
 * JS off the native control is what renders, which is also what the public
 * booking pages need.
 */
export default class extends Controller {
    static values = {
        /**
          * {noResults} — translated in Twig, see _partials/_select.html.twig.
          *
          * One string, because in this configuration it is the only English
          * Tom Select would otherwise print. It has a "type to filter" hint
          * too, but that one is unreachable: it lives in `settings.placeholder`
          * and Tom Select clears it the moment the control holds an item,
          * which for a select rendered from a chosen value is always.
          */
        i18n: { type: Object, default: {} },
        /**
         * Below this many options the search box is removed entirely and the
         * widget behaves like a native select: click, arrow, enter. A text
         * caret blinking in a five-item "Repeat" picker invites typing that
         * has nothing to filter.
         */
        searchAfter: { type: Number, default: 8 },
        /** Frameless variant for inline toolbar controls (compose's font pickers). */
        bare: { type: Boolean, default: false },
    }

    connect() {
        if (!(this.element instanceof HTMLSelectElement)) return

        // Multi-selects stay native. The only one in the app is compose's
        // address field, which has its own Tom Select with contact rows and
        // chips; a generic widget would be a downgrade.
        if (this.element.multiple) return

        this.#enhance()

        // Turbo snapshots the DOM for its cache *before* it swaps the body, so
        // a live widget gets cached as markup and the restored page would come
        // back with a .ts-wrapper already around the select — which connect()
        // would then wrap a second time.
        this.teardown = this.teardown.bind(this)
        document.addEventListener("turbo:before-cache", this.teardown)
    }

    disconnect() {
        document.removeEventListener("turbo:before-cache", this.teardown)
        this.teardown()
    }

    teardown() {
        this.#unbindScroll()
        this.element.tomselect?.destroy()
    }

    #enhance() {
        // A restored cache entry, or a stream that re-rendered the frame around
        // a widget we already built. Idempotent either way.
        this.element.tomselect?.destroy()

        // Measured while the native control is still the thing being laid out,
        // so the widget inherits the height the call site asked for — h-8 in the
        // admin filter rows, h-9 in settings — rather than imposing one of its
        // own. Zero when the select is in a pane that has not been shown yet;
        // the stylesheet's default covers that.
        const height = this.element.getBoundingClientRect().height
        const fontSize = getComputedStyle(this.element).fontSize

        const searchable = this.#optionCount() > this.searchAfterValue

        const settings = {
            create: false,
            // Several selects use an empty value as a real choice ("All
            // accounts", "Follow language"). Without this Tom Select drops
            // those options and the filter loses its reset.
            allowEmptyOption: true,
            // Tom Select renders 50 by default and silently stops. The timezone
            // picker has several hundred, and "not in the list" is a worse
            // failure than a long list — a native select showed all of them.
            maxOptions: null,
            // Every consumer of this controller is inside something that clips:
            // the modal body scrolls, the settings pane scrolls, the calendar
            // event sheet scrolls. Anchored to the control, the panel would be
            // cut off at the pane's edge.
            dropdownParent: "body",
            render: {
                no_results: () => {
                    const div = document.createElement("div")
                    div.className = "ts-no-results"
                    div.textContent = this.i18nValue.noResults ?? ""

                    return div
                },
            },
            onDropdownOpen: () => {
                this.#place()
                this.#bindScroll()
            },
            // Filtering changes the panel's height, and when it is flipped the
            // height is what its position is derived from.
            onType: () => this.#place(),
            onDropdownClose: () => this.#unbindScroll(),
        }

        // Only set when there is to be no search box. Deliberately an absent
        // KEY rather than `controlInput: undefined`: Tom Select merges settings
        // with Object.assign, which copies an explicit `undefined` straight
        // over its own default — and that default is the input's HTML. Naming
        // the key at all, whatever the value, therefore took the search box
        // away from every select in the app, the 420-entry timezone picker
        // included. `null` is the documented way to ask for no input; leaving
        // it out is the only way to ask for the standard one.
        if (!searchable) {
            // Nothing to search in a short list, and no input means no caret
            // and no keyboard trap — arrow keys and Enter still work.
            settings.controlInput = null
        }

        const select = new TomSelect(this.element, settings)

        if (height > 0) select.wrapper.style.setProperty("--ts-control-h", `${height}px`)

        // Tells the stylesheet this panel is positioned from inline coordinates
        // rather than anchored to a wrapper. `.single` alone would not do:
        // compose's provider-preset field is single too and comes from UX
        // Autocomplete, which parents its panel the normal way.
        select.dropdown.classList.add("ts-dropdown-floating")

        // The panel lives on <body> now, so it is outside the reach of the call
        // site's text-size utility. The tokens still reach it — themes are
        // declared on <html>.
        select.dropdown.style.fontSize = fontSize

        if (this.bareValue) select.wrapper.classList.add("ts-bare")

        this.#carryAccessibleName(select)
    }

    /**
     * Carry the native select's accessible name onto the widget that replaces
     * it.
     *
     * Tom Select builds its own text input and leaves the <select> hidden
     * behind it. A name declared on the select — `aria-label`, or a <label>
     * bound by id — therefore names an element the user can no longer reach,
     * and the control they *can* reach reports as an unnamed combobox. The
     * compose toolbar's typeface and size pickers were two of those.
     *
     * Only ever fills a gap: a widget Tom Select already named (it does wire
     * `aria-labelledby` up from some label arrangements) is left alone.
     */
    #carryAccessibleName(select) {
        const input = select.control_input

        if (!input || input.hasAttribute("aria-label") || input.hasAttribute("aria-labelledby")) return

        const label = this.element.getAttribute("aria-label")

        if (label) {
            input.setAttribute("aria-label", label)

            return
        }

        const labelledBy = this.element.getAttribute("aria-labelledby")

        if (labelledBy) {
            input.setAttribute("aria-labelledby", labelledBy)

            return
        }

        const bound = this.element.id
            ? document.querySelector(`label[for="${CSS.escape(this.element.id)}"]`)
            : null

        if (bound?.textContent.trim()) input.setAttribute("aria-label", bound.textContent.trim())
    }

    #optionCount() {
        return this.element.querySelectorAll("option").length
    }

    /**
     * Puts the panel where there is room for it.
     *
     * Tom Select positions it below the control and stops there. That is fine
     * until the control is near the bottom of the window — the "Alert" row at
     * the foot of the event modal is the case that prompted this — and because
     * a body-parented panel is absolutely positioned, running off the bottom
     * does not clip it, it lengthens the document. So: flip above when there is
     * more room above, and cap the list to the room actually available either
     * way. A native popup does the same thing; it just gets to do it outside
     * the window.
     */
    #place() {
        const select = this.element.tomselect

        if (!select?.isOpen) return

        const panel = select.dropdown
        const content = panel.querySelector(".ts-dropdown-content")
        const rect = select.control.getBoundingClientRect()

        // Enough to keep the panel off the window edge and clear of the control.
        const gap = 4
        const margin = 8

        const below = window.innerHeight - rect.bottom - gap - margin
        const above = rect.top - gap - margin

        content.style.maxHeight = ""
        const flip = panel.offsetHeight > below && above > below

        // The floor stops a control wedged against the edge from collapsing the
        // panel to nothing; a short scroll beats no list.
        content.style.maxHeight = `${Math.max(96, Math.floor(flip ? above : below))}px`

        panel.style.top = flip
            ? `${rect.top + window.scrollY - panel.offsetHeight - gap}px`
            : `${rect.bottom + window.scrollY + gap}px`
    }

    /**
     * A body-parented panel is positioned once, at open, from the control's
     * viewport rectangle. Scrolling the pane underneath it moves the control
     * and leaves the panel behind, and nothing about scrolling blurs the
     * control, so Tom Select never hears about it. Closing is the honest
     * answer — the same thing a native select popup does.
     */
    #bindScroll() {
        this.onScroll ??= (event) => {
            if (event.target instanceof Node && this.element.tomselect?.dropdown.contains(event.target)) return

            this.element.tomselect?.close()
        }

        document.addEventListener("scroll", this.onScroll, { capture: true, passive: true })
        window.addEventListener("resize", this.onScroll, { passive: true })
    }

    #unbindScroll() {
        if (!this.onScroll) return

        document.removeEventListener("scroll", this.onScroll, { capture: true })
        window.removeEventListener("resize", this.onScroll)
    }
}
