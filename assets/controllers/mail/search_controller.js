// Wires the topbar search input to /mail/search?q=...
//
// - Enter / form submit navigates
// - Escape clears the input
// - Stores recent searches in localStorage (up to 8)
// - Shows recent searches on focus of an empty box
// - Suggests operators as they are typed, and real contacts for the three
//   that take an address, because `from:` is only useful if you can remember
//   how the sender spells their name — which is what the search was for.

import { Controller } from "@hotwired/stimulus";

const STORAGE_KEY   = "mail_recent_searches";
const MAX_RECENTS   = 8;
const SEARCH_ROUTE  = "/mail/search";
const CONTACT_ROUTE = "/contacts/autocomplete";

/**
 * The operators SearchQueryParser understands. Kept in the same order the
 * parser documents them so the two stay comparable by eye; `hint` is what the
 * operator takes, not an example of a value, since the values come from the
 * user's own mail.
 */
const OPERATORS = [
    { token: "from:",           hint: "sender" },
    { token: "to:",             hint: "recipient" },
    { token: "cc:",             hint: "copied recipient" },
    { token: "subject:",        hint: "words in the subject" },
    { token: "label:",          hint: "a label you made" },
    { token: "has:attachment",  hint: "has an attachment" },
    { token: "is:unread",       hint: "not read yet" },
    { token: "is:read",         hint: "already read" },
    { token: "is:starred",      hint: "starred" },
    { token: "in:inbox",        hint: "in the inbox" },
    { token: "in:sent",         hint: "sent" },
    { token: "in:drafts",       hint: "a draft" },
    { token: "in:archive",      hint: "archived" },
    { token: "in:trash",        hint: "in the trash" },
    { token: "in:junk",         hint: "in junk" },
    { token: "after:",          hint: "YYYY-MM-DD" },
    { token: "before:",         hint: "YYYY-MM-DD" },
];

/** Operator values that are a closed set — suggested rather than searched. */
const VALUES = {
    has: ["attachment"],
    is: ["unread", "read", "starred"],
    in: ["inbox", "sent", "drafts", "archive", "trash", "junk", "snoozed"],
};

/** Operators whose value is an address, and so comes from the contact book. */
const ADDRESS_OPERATORS = ["from", "to", "cc"];

const MAX_SUGGESTIONS = 8;

export default class extends Controller {
    static targets = ["input", "dropdown", "recentsList", "dropdownTitle", "clear"];

    #items = [];
    #active = -1;
    #debounce = null;
    #requestId = 0;

    connect() {
        this._boundOutside = this._handleOutsideClick.bind(this);
        document.addEventListener("click", this._boundOutside, { capture: true });

        // The box must always say what the results below it were filtered by.
        // Clicking a folder after a search used to leave the query sitting in
        // the box above an unfiltered list — the search input survives the
        // visit, and the folder page has no `q` to overwrite it with, so the
        // box claimed a filter that was not applied.
        //
        // Synced from the URL rather than cleared on navigation, because the
        // rule is not "clear it when leaving search" — it is "the box shows the
        // query in the address bar". That is also true going the other way: a
        // link straight to /mail/search?q=x, a reload, or back into a search
        // from a folder all fill the box in, and none of them are a case
        // clearing logic would have covered.
        this._boundSync = this.#syncToUrl.bind(this);
        this.#syncToUrl();

        // connect() alone is not enough: the element can survive a Turbo visit,
        // in which case the controller is never re-connected and only the URL
        // changed.
        document.addEventListener("turbo:load", this._boundSync);
        document.addEventListener("turbo:frame-load", this._boundSync);
    }

    disconnect() {
        clearTimeout(this.#debounce);
        document.removeEventListener("click", this._boundOutside, { capture: true });
        document.removeEventListener("turbo:load", this._boundSync);
        document.removeEventListener("turbo:frame-load", this._boundSync);
    }

    /** Make the box agree with `?q=` in the address bar. */
    #syncToUrl() {
        if (false === this.hasInputTarget) {
            return;
        }

        // Never yank the text out from under someone mid-type. A frame load can
        // land while the box has focus — the list refreshing underneath is the
        // ordinary case — and the address bar is not the authority on a query
        // that has not been submitted yet.
        if (document.activeElement === this.inputTarget) {
            return;
        }

        const query = new URLSearchParams(window.location.search).get("q") ?? "";

        if (this.inputTarget.value !== query) {
            this.inputTarget.value = query;
        }
    }

    // ── Input events ──────────────────────────────────────────────────────

    onKeydown(event) {
        if (event.key === "Enter") {
            event.preventDefault();

            // A highlighted suggestion is what Enter means while the list is
            // open; submitting the half-typed operator underneath it is not.
            if (this.#active >= 0) {
                this.#apply(this.#items[this.#active]);
                return;
            }

            this._submit();
            return;
        }

        if (event.key === "Tab" && this.#active >= 0) {
            event.preventDefault();
            this.#apply(this.#items[this.#active]);
            return;
        }

        if (event.key === "Escape") {
            // First Escape dismisses the list, the second clears the box: a
            // suggestion list is not worth losing a typed query over.
            //
            // preventDefault is what makes that true. This is an
            // <input type="search">, and Chromium clears one natively on
            // Escape — so without this the first press did both, and the
            // query was gone before the user had dismissed anything.
            if (false === this.dropdownTarget.classList.contains("hidden")) {
                event.preventDefault();
                this._closeDropdown();

                return;
            }

            this.inputTarget.value = "";
            this.inputTarget.blur();
            return;
        }

        if (event.key === "ArrowDown") {
            event.preventDefault();
            this.#move(1);
            return;
        }

        if (event.key === "ArrowUp") {
            event.preventDefault();
            this.#move(-1);
        }
    }

    onFocus() {
        this.#refresh();
    }

    onInput() {
        this.#refresh();
    }

    // ── Dropdown item clicks ──────────────────────────────────────────────

    selectRecent(event) {
        const query = event.currentTarget.dataset.query;
        if (!query) { return; }

        this.inputTarget.value = query;
        this._closeDropdown();
        this._navigate(query);
    }

    selectSuggestion(event) {
        const index = Number(event.currentTarget.dataset.index);
        this.#apply(this.#items[index]);
    }

    removeRecent(event) {
        event.stopPropagation();
        const query = event.currentTarget.closest("[data-query]")?.dataset.query;
        if (!query) { return; }

        const recents = this._loadRecents().filter((r) => r !== query);
        this._saveRecents(recents);
        this.#refresh();
    }

    clearRecents(event) {
        event?.preventDefault();
        this._saveRecents([]);
        this._closeDropdown();
    }

    // ── Suggestions ───────────────────────────────────────────────────────

    /** Recents for an empty box, suggestions for anything else. */
    #refresh() {
        clearTimeout(this.#debounce);

        if (this.inputTarget.value.trim() === "") {
            this.#showRecents();
            return;
        }

        this.#debounce = setTimeout(() => this.#suggest(), 120);
    }

    #showRecents() {
        const recents = this._loadRecents();

        this.#items = recents.map((query) => ({ kind: "recent", query }));
        this.#active = -1;

        if (recents.length === 0) {
            this._closeDropdown();
            return;
        }

        this.#setTitle("Recent searches", true);
        this.#render();
        this._openDropdown();
    }

    async #suggest() {
        const { operator, value } = this.#currentToken();
        let items = [];

        if (operator === null) {
            // Still typing the operator itself.
            items = OPERATORS
                .filter((entry) => entry.token.startsWith(value.toLowerCase()))
                .map((entry) => ({ kind: "operator", token: entry.token, hint: entry.hint }));
        } else if (ADDRESS_OPERATORS.includes(operator)) {
            items = (await this.#contacts(value)).map((contact) => ({
                kind: "contact",
                token: `${operator}:${this.#quote(contact.email)}`,
                label: contact.displayName || contact.email,
                hint: contact.email,
            }));
        } else if (VALUES[operator]) {
            items = VALUES[operator]
                .filter((candidate) => candidate.startsWith(value.toLowerCase()))
                .map((candidate) => ({ kind: "operator", token: `${operator}:${candidate}`, hint: "" }));
        }

        this.#items = items.slice(0, MAX_SUGGESTIONS);
        this.#active = -1;

        if (this.#items.length === 0) {
            this._closeDropdown();
            return;
        }

        this.#setTitle("Suggestions", false);
        this.#render();
        this._openDropdown();
    }

    /**
     * The token the caret sits in, split into operator and value. `operator`
     * is null while the token has no colon yet — that is the case where the
     * operator list itself is what to suggest.
     */
    #currentToken() {
        const upToCaret = this.inputTarget.value.slice(0, this.inputTarget.selectionStart ?? undefined);
        const token = upToCaret.split(/\s+/).pop() ?? "";
        const colon = token.indexOf(":");

        if (colon === -1) {
            return { operator: null, value: token };
        }

        return {
            operator: token.slice(0, colon).toLowerCase(),
            value: token.slice(colon + 1).replace(/^["']/, ""),
        };
    }

    /** @returns {Promise<Array<{email: string, displayName: string}>>} */
    async #contacts(query) {
        if (query === "") {
            return [];
        }

        // Only the newest response may render: two keystrokes in flight can
        // come back out of order, and the older one would overwrite what the
        // user is looking at with results for a prefix they have moved past.
        const requestId = ++this.#requestId;

        try {
            const response = await fetch(`${CONTACT_ROUTE}?q=${encodeURIComponent(query)}`, {
                headers: { Accept: "application/json" },
            });

            if (false === response.ok || requestId !== this.#requestId) {
                return [];
            }

            return await response.json();
        } catch {
            return [];
        }
    }

    /** Replace the token under the caret with the chosen one. */
    #apply(item) {
        if (!item) { return; }

        if (item.kind === "recent") {
            this.inputTarget.value = item.query;
            this._closeDropdown();
            this._navigate(item.query);
            return;
        }

        const input = this.inputTarget;
        const caret = input.selectionStart ?? input.value.length;
        const before = input.value.slice(0, caret);
        const after = input.value.slice(caret);
        const start = before.lastIndexOf(" ") + 1;

        // A bare operator leaves the caret against the colon, ready for a
        // value; a complete one ends the token with a space.
        const trailing = item.token.endsWith(":") ? "" : " ";
        const replaced = before.slice(0, start) + item.token + trailing;

        input.value = replaced + after;
        input.setSelectionRange(replaced.length, replaced.length);
        input.focus();

        this.#refresh();
    }

    #move(delta) {
        if (this.#items.length === 0) {
            return;
        }

        const next = this.#active + delta;

        // Wraps at both ends: the list is short, and a highlight that sticks
        // at the bottom edge just makes the arrow key feel broken.
        this.#active = next < 0
            ? this.#items.length - 1
            : (next >= this.#items.length ? 0 : next);

        this.#render();
    }

    #setTitle(text, clearable) {
        if (this.hasDropdownTitleTarget) {
            this.dropdownTitleTarget.textContent = text;
        }

        if (this.hasClearTarget) {
            this.clearTarget.classList.toggle("hidden", false === clearable);
        }
    }

    #render() {
        if (!this.hasRecentsListTarget) { return; }

        this.recentsListTarget.innerHTML = this.#items
            .map((item, index) => (item.kind === "recent"
                ? this.#recentMarkup(item, index)
                : this.#suggestionMarkup(item, index)))
            .join("");
    }

    #recentMarkup(item, index) {
        return `
            <li>
                <button
                    type="button"
                    data-query="${this._escape(item.query)}"
                    data-index="${index}"
                    data-action="click->mail--search#selectRecent"
                    class="group w-full flex items-center gap-2.5 px-3 py-2 text-sm text-ink-soft
                           ${index === this.#active ? "bg-hover" : ""} hover:bg-hover transition-colors text-left"
                >
                    <i class="fa-solid fa-clock-rotate-left text-ink-faint w-3.5 flex-shrink-0" aria-hidden="true"></i>
                    <span class="flex-1 truncate">${this._escape(item.query)}</span>
                    <span
                        role="button"
                        tabindex="0"
                        data-action="click->mail--search#removeRecent"
                        class="opacity-0 group-hover:opacity-100 p-0.5 rounded text-ink-faint hover:text-ink-soft transition-opacity"
                        aria-label="Remove"
                    >
                        <i class="fa-solid fa-xmark text-xs" aria-hidden="true"></i>
                    </span>
                </button>
            </li>
        `;
    }

    #suggestionMarkup(item, index) {
        const icon = item.kind === "contact" ? "fa-user" : "fa-terminal";
        const label = item.label ?? item.token;

        return `
            <li>
                <button
                    type="button"
                    data-index="${index}"
                    data-action="click->mail--search#selectSuggestion"
                    class="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-ink-soft
                           ${index === this.#active ? "bg-hover" : ""} hover:bg-hover transition-colors text-left"
                >
                    <i class="fa-solid ${icon} text-ink-faint w-3.5 flex-shrink-0" aria-hidden="true"></i>
                    <span class="font-mono text-xs text-ink truncate">${this._escape(label)}</span>
                    ${item.hint ? `<span class="ml-auto text-xs text-ink-faint truncate">${this._escape(item.hint)}</span>` : ""}
                </button>
            </li>
        `;
    }

    #quote(value) {
        return /\s/.test(value) ? `"${value}"` : value;
    }

    // ── Private ───────────────────────────────────────────────────────────

    _submit() {
        const q = this.inputTarget.value.trim();

        if (q === "") {
            return;
        }

        this._addRecent(q);
        this._closeDropdown();
        this._navigate(q);
    }

    _navigate(q) {
        const url = `${SEARCH_ROUTE}?q=${encodeURIComponent(q)}`;
        // Use Turbo visit to keep the SPA feel
        if (typeof Turbo !== "undefined") {
            Turbo.visit(url);
        } else {
            window.location.href = url;
        }
    }

    _openDropdown() {
        if (this.hasDropdownTarget) {
            this.dropdownTarget.classList.remove("hidden");
        }
    }

    _closeDropdown() {
        this.#active = -1;

        if (this.hasDropdownTarget) {
            this.dropdownTarget.classList.add("hidden");
        }
    }

    _handleOutsideClick(event) {
        if (!this.element.contains(event.target)) {
            this._closeDropdown();
        }
    }

    _loadRecents() {
        try {
            return JSON.parse(localStorage.getItem(STORAGE_KEY) ?? "[]");
        } catch {
            return [];
        }
    }

    _saveRecents(recents) {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(recents));
    }

    _addRecent(query) {
        const recents = this._loadRecents().filter((r) => r !== query);
        recents.unshift(query);
        this._saveRecents(recents.slice(0, MAX_RECENTS));
    }

    _escape(str) {
        return String(str)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;");
    }
}
