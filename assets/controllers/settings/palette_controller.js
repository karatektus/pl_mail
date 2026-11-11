/* stimulusFetch: 'lazy' */
import { Controller } from "@hotwired/stimulus";
import * as Turbo from "@hotwired/turbo";
import { flashCard, matchingKeyword, normalise } from "../../settings_search.js";

/**
 * The Settings search palette: ⌘K / Ctrl+K from anywhere in the app (#569).
 *
 * ## What it searches
 *
 * GET /settings/search, fetched the first time the palette opens and kept for
 * the life of the page: every page and every card, translated, with the
 * keywords each card is also found by. About eighty entries — filtered here,
 * per keystroke, by the rule in assets/settings_search.js that the tree's
 * filter uses too.
 *
 * Ranked by where the words matched, best first: the start of a title, then
 * anywhere in a title, then the area or page it sits in, then a keyword. A
 * stable sort within each rank keeps the tree's order, so equal matches come
 * out the way Settings lists them.
 *
 * ## When ⌘K is not ours
 *
 * Inside a text area or anything editable the key is left alone: ⌘K is
 * "insert link" in most rich-text editors people's hands have learned, and a
 * dialog thrown over a half-written mail is the wrong answer to it even where
 * plMail's own composer does not bind it. A handler that already took the key
 * (defaultPrevented) keeps it too. While another dialog is open the
 * palette stays shut rather than stacking a second modal on the first. In a
 * plain input it opens: there is nothing a one-line field does with ⌘K, and
 * the settings filter is such a field.
 *
 * ## Where a result lands
 *
 * On another page, a Turbo visit to /settings/<page>#card-…, and the tree's
 * controller rings the card when the page connects. On the page already open
 * nothing would reconnect, so the card is ringed here and the address updated
 * in place.
 *
 * Values:
 *   url       — GET /settings/search
 *   messages  — { count, empty, keyword, failed, page }, translated; count
 *               carries %count%
 *
 * Targets:
 *   input   — the search field
 *   status  — "4 results" / "Nothing found", announced politely
 *   list    — the listbox the results go into
 *   row     — <template> of one result
 */
const LIMIT = 12;

export default class extends Controller {
    static values = {
        url:      String,
        messages: Object,
    };

    static targets = ["input", "status", "list", "row"];

    #entries = null;
    #shown = [];
    #selected = 0;

    shortcut(event) {
        if (false === (event.metaKey || event.ctrlKey) || event.altKey || event.shiftKey || "k" !== event.key.toLowerCase()) {
            return;
        }

        if (event.defaultPrevented || this.element.open || this.#inEditor(event.target) || this.#otherDialogOpen()) {
            return;
        }

        event.preventDefault();
        this.open();
    }

    async open() {
        this.inputTarget.value = "";
        this.element.showModal();
        this.inputTarget.focus();

        if (null === this.#entries) {
            await this.#load();
        }

        this.search();
    }

    closed() {
        this.listTarget.replaceChildren();
        this.inputTarget.removeAttribute("aria-activedescendant");
    }

    search() {
        if (null === this.#entries) {
            return;
        }

        const query = normalise(this.inputTarget.value.trim());

        this.#shown = "" === query ? this.#entries.filter((entry) => entry.page) : this.#rank(query).slice(0, LIMIT);
        this.#selected = 0;
        this.#render(query);
    }

    navigate(event) {
        if ("ArrowDown" === event.key || "ArrowUp" === event.key) {
            event.preventDefault();

            if (0 === this.#shown.length) {
                return;
            }

            const step = "ArrowDown" === event.key ? 1 : -1;

            this.#select((this.#selected + step + this.#shown.length) % this.#shown.length);

            return;
        }

        if ("Enter" === event.key) {
            event.preventDefault();

            const entry = this.#shown[this.#selected];

            if (undefined !== entry) {
                this.#go(entry);
            }
        }
    }

    // ── Private ─────────────────────────────────────────────────────────────

    async #load() {
        try {
            const response = await fetch(this.urlValue, { headers: { Accept: "application/json" } });

            if (false === response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const { entries } = await response.json();

            this.#entries = entries.map((entry) => ({
                ...entry,
                normTitle: normalise(entry.title),
                normCrumb: normalise(entry.crumb),
            }));
        } catch {
            // Left null, so the next open asks again rather than searching an
            // empty list and saying "nothing found" about every setting.
            this.statusTarget.textContent = this.messagesValue.failed ?? "";
        }
    }

    #rank(query) {
        const ranked = [];

        this.#entries.forEach((entry, order) => {
            let rank = -1;
            let keyword = null;

            if (entry.normTitle.startsWith(query)) {
                rank = 0;
            } else if (entry.normTitle.includes(query)) {
                rank = 1;
            } else if (entry.normCrumb.includes(query)) {
                rank = 2;
            } else {
                keyword = matchingKeyword(entry.terms, query);
                rank = null === keyword ? -1 : 3;
            }

            if (-1 !== rank) {
                ranked.push({ ...entry, rank, order, keyword });
            }
        });

        return ranked.sort((a, b) => a.rank - b.rank || a.order - b.order);
    }

    #render(query) {
        const rows = this.#shown.map((entry, index) => {
            const row = this.rowTarget.content.firstElementChild.cloneNode(true);

            row.id = `settings-palette-option-${index}`;
            row.querySelector("[data-part='title']").textContent = entry.title;
            row.querySelector("[data-part='crumb']").textContent = entry.crumb;
            row.querySelector("[data-part='via']").textContent = entry.keyword
                ? `${this.messagesValue.keyword}: ${entry.keyword}`
                : (entry.page ? this.messagesValue.page : "");
            row.addEventListener("mousemove", () => this.#select(index));
            row.addEventListener("click", () => this.#go(entry));

            return row;
        });

        this.listTarget.replaceChildren(...rows);

        this.statusTarget.textContent = "" === query
            ? ""
            : (0 === rows.length ? this.messagesValue.empty : this.messagesValue.count.replace("%count%", String(rows.length)));

        this.#select(0);
    }

    #select(index) {
        this.#selected = index;

        this.listTarget.querySelectorAll("[role='option']").forEach((row, at) => {
            row.setAttribute("aria-selected", at === index ? "true" : "false");

            if (at === index) {
                row.scrollIntoView({ block: "nearest" });
                this.inputTarget.setAttribute("aria-activedescendant", row.id);
            }
        });
    }

    #go(entry) {
        this.element.close();

        const target = new URL(entry.url, window.location.href);

        if (target.pathname === window.location.pathname && "" !== target.hash) {
            window.history.replaceState(window.history.state, "", target.href);
            flashCard(target.hash.slice(1));

            return;
        }

        Turbo.visit(target.href);
    }

    #inEditor(element) {
        return element instanceof HTMLElement && (element.isContentEditable || "TEXTAREA" === element.tagName);
    }

    #otherDialogOpen() {
        return null !== document.querySelector("dialog[open]:not(#settings-palette)");
    }
}
