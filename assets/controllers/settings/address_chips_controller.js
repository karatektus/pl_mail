/* stimulusFetch: 'lazy' */
import { Controller } from "@hotwired/stimulus"
import TomSelect from "tom-select"

/**
 * A list of email addresses as chips, with the composer's contact suggestions,
 * that saves itself (#692, Always Bcc).
 *
 * The element is the <form>; inside it a native <select multiple
 * name="addresses[]"> holds the stored list and stays the source of truth, as
 * ui--select keeps it. Every chip added or removed submits the form, the way
 * the read-receipt select next to it submits itself: an address that is a
 * chip is an address the person has chosen, and a Save button after it would
 * be a second click to confirm it.
 *
 * NOT ContactAutocompleteField. That one is an entity field and posts contact
 * ids, which a draft can turn back into recipients and a settings value
 * cannot — the setting is addresses. So the suggestions come from the same
 * endpoint (ContactController::recipients) and are turned into their address
 * here: a suggestion offered under a contact id is taken by the address in its
 * label.
 *
 * The rows and chips borrow compose's classes (ts-option-row, ts-item-label),
 * so the two pickers look the same; tom-select.css themes both.
 */
export default class extends Controller {
    static values = {
        /** The suggestion endpoint: /contacts/autocomplete/recipients. */
        url: String,
        /** {create, noResults, add} — translated in Twig. */
        i18n: { type: Object, default: {} },
        /**
         * What the input says while the list is empty, when that means more
         * than "type here" — "Nobody, this address sends no copy" on an
         * address's own list. With chips in the field it says `add` instead:
         * "nobody" beside two addresses would be wrong.
         */
        empty: { type: String, default: "" },
    }

    static ADDRESS = /^[^@\s<>]+@[^@\s<>]+\.[^@\s<>]+$/

    connect() {
        this.select = this.element.querySelector("select[multiple]")

        if (null === this.select) {
            return
        }

        this.select.tomselect?.destroy()
        this.saveTimer = null

        const parts = (text) => {
            const raw = String(text ?? "")
            const match = raw.match(/^(.*?)\s*<([^<>]+)>$/)

            return null !== match && "" !== match[1].trim()
                ? { name: match[1].trim(), email: match[2].trim().toLowerCase() }
                : { name: "", email: raw.trim().toLowerCase() }
        }

        this.tom = new TomSelect(this.select, {
            plugins: ["remove_button"],
            persist: false,
            create: (input) => {
                const { email } = parts(input)

                return { value: email, text: email }
            },
            createFilter: (input) => this.constructor.ADDRESS.test(parts(input).email),
            // A pasted list becomes one chip per address.
            splitOn: /[\s,;]+/,
            openOnFocus: false,
            hideSelected: true,
            closeAfterSelect: true,
            selectOnTab: true,
            maxOptions: 8,
            valueField: "value",
            labelField: "text",
            searchField: ["text"],
            loadThrottle: 200,
            shouldLoad: (query) => query.trim().length >= 1,
            load: (query, callback) => {
                const url = new URL(this.urlValue, window.location.origin)
                url.searchParams.set("query", query)

                fetch(url, { headers: { Accept: "application/json" } })
                    .then((response) => (response.ok ? response.json() : { results: [] }))
                    .then((data) => {
                        // Keyed by address, whatever the endpoint keyed it by.
                        callback((data.results ?? []).map((row) => {
                            const { name, email } = parts(row.text)

                            return { value: email, text: "" !== name ? `${name} <${email}>` : email }
                        }))
                    })
                    .catch(() => callback())
            },
            render: {
                option: (data, escape) => {
                    const { name, email } = parts(data.text)
                    const initials = "" !== name
                        ? name.split(/\s+/).map((word) => word[0] ?? "").slice(0, 2).join("")
                        : (email[0] ?? "?")

                    return '<div><div class="ts-option-row">'
                        + `<span class="ts-option-avatar">${escape(initials.toUpperCase())}</span>`
                        + '<span class="ts-option-text">'
                        + ("" !== name ? `<span class="ts-option-name">${escape(name)}</span>` : "")
                        + `<span class="ts-option-email">${escape(email)}</span>`
                        + "</span></div></div>"
                },
                item: (data, escape) => {
                    const { email } = parts(data.text)

                    return `<div title="${escape(email)}"><span class="ts-item-label">${escape(email)}</span></div>`
                },
                option_create: (data, escape) => {
                    const label = (this.i18nValue.create ?? "Add %s").replace("%s", escape(data.input))

                    return `<div class="create">${label}</div>`
                },
                no_results: () => {
                    const div = document.createElement("div")
                    div.className = "ts-no-results"
                    div.textContent = this.i18nValue.noResults ?? ""

                    return div
                },
            },
            onItemAdd: () => {
                this.tom.setTextboxValue("")
                this.tom.refreshOptions(false)
                this.#placeholder()
                this.#save()
            },
            onItemRemove: () => {
                this.#placeholder()
                this.#save()
            },
        })

        this.#placeholder()

        this.teardown = this.teardown.bind(this)
        document.addEventListener("turbo:before-cache", this.teardown)
    }

    disconnect() {
        document.removeEventListener("turbo:before-cache", this.teardown)
        this.teardown()
    }

    teardown() {
        clearTimeout(this.saveTimer)
        this.tom?.destroy()
        this.tom = null
    }

    #placeholder() {
        if ("" === this.emptyValue || null === this.tom) {
            return
        }

        const text = 0 === this.tom.items.length ? this.emptyValue : (this.i18nValue.add ?? "")

        this.tom.settings.placeholder = text
        this.tom.control_input.placeholder = text
    }

    /**
     * Submit once per burst: pasting three addresses adds three chips in one
     * go, and that is one change, not three requests racing each other.
     */
    #save() {
        clearTimeout(this.saveTimer)
        this.saveTimer = setTimeout(() => this.element.requestSubmit(), 150)
    }
}
