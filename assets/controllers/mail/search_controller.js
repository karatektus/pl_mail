// Wires the topbar search input to /mail/search?q=...
//
// - Enter / form submit navigates
// - Escape clears the input
// - Stores recent searches in localStorage (up to 8)
// - Shows recent searches on focus of an empty box
// - Suggests operators as they are typed, and real contacts for the three
//   that take an address, because `from:` is only useful if you can remember
//   how the sender spells their name — which is what the search was for.
// - Shows the mail itself as you type: ten conversations from
//   /mail/search/suggest, which runs only the passes cheap enough to spend on
//   a keystroke. Enter still runs the complete search — see TypeAheadSearch
//   for what the preview leaves out and why.
//
// The whole thing is one ARIA combobox: the input holds the state, the popup
// is a single listbox holding both lists, and this file's job on every arrow
// key is to keep aria-activedescendant pointing at the row that looks
// highlighted. Highlight by class alone would be a highlight only sighted
// users have.

import { Controller } from "@hotwired/stimulus";

const STORAGE_KEY   = "mail_recent_searches";
const MAX_RECENTS   = 8;
const SEARCH_ROUTE  = "/mail/search";
const CONTACT_ROUTE = "/contacts/autocomplete";

/**
 * Long enough that a burst of typing costs one request instead of six, short
 * enough that pausing over a word feels like the list was already there. Above
 * roughly 200ms the list starts arriving after the reader has looked away.
 */
const DEBOUNCE_MS = 150;

/**
 * Two characters match an enormous share of a large mailbox, so the ten rows
 * they buy are ten rows of noise. The endpoint enforces the same floor — this
 * copy exists to not ask at all, which is cheaper than being told no.
 */
const MIN_LIVE_LENGTH = 3;

/**
 * A query carrying an operator gets no live results, because the preview
 * cannot honour operators and a preview that silently ignored `is:unread`
 * would look exactly like one that had not. Checked here so the request is
 * never made; SearchSuggestController decides for real.
 */
const OPERATOR_TOKEN = /(^|\s)(from|to|cc|subject|label|has|is|in|after|before):/i;

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
    static targets = [
        "input", "dropdown", "recentsList", "dropdownTitle", "clear", "header",
        "resultsGroup", "resultsList", "status",
    ];

    static values = {
        suggestUrl:      String,
        recentsTitle:    String,
        operatorsTitle:  String,
    };

    /**
     * Every row the arrow keys can reach, in the order they appear on screen:
     * the recents-or-operators list first, then the live results. One array
     * across both lists, because "the third thing down" is what the reader
     * sees and it must not matter to them which list it came out of.
     */
    #items = [];
    #active = -1;
    #debounce = null;
    #requestId = 0;

    /** The in-flight suggestion request, so a newer keystroke can cancel it. */
    #liveRequest = null;

    /**
     * Whether Escape has dismissed the list for the query now in the box.
     *
     * Escape closing the dropdown is not enough on its own: the live results
     * for the same query are already on their way, and landing they called
     * `#settle()`, which reopened the list the reader had just dismissed. On a
     * fast local connection the response beats the keypress and nothing is
     * seen; the slower the connection, the more reliably the suggestions
     * spring back up by themselves a moment after being sent away.
     *
     * Cleared by anything that means "I want the list again" — typing, a fresh
     * focus, arrowing into it — so it only ever suppresses the reopen for the
     * exact query that was dismissed.
     */
    #dismissed = false;

    /** The first list: recent searches, or operator and contact completions. */
    #suggestions = [];

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
        this.#liveRequest?.abort();
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

            // A highlighted row is what Enter means while the list is open;
            // submitting the half-typed operator underneath it is not. With
            // nothing highlighted it searches, which is the ordinary case and
            // the reason nothing is highlighted by default.
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
                this.#dismissed = true;
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

    /**
     * Clicking a live result. The anchor navigates on its own — this only
     * takes the list down, so it is not left hanging over the mail that was
     * just opened.
     */
    selectResult() {
        this._closeDropdown();
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

    /** Recents for an empty box, suggestions and live results for anything else. */
    #refresh() {
        clearTimeout(this.#debounce);

        // A new keystroke — or a fresh focus — is a new question, so whatever
        // Escape dismissed is no longer what is being asked for.
        this.#dismissed = false;

        // Whatever is on its way back is about a query that no longer exists.
        // Left to land it would paint results for a prefix the user has
        // already typed past, which reads as the list lagging a keystroke
        // behind — and it is one fewer connection held open on the way in.
        this.#liveRequest?.abort();
        this.#liveRequest = null;

        if (this.inputTarget.value.trim() === "") {
            this.#clearResults();
            this.#showRecents();
            return;
        }

        this.#debounce = setTimeout(() => this.#suggest(), DEBOUNCE_MS);
    }

    #showRecents() {
        const recents = this._loadRecents();

        this.#suggestions = recents.map((query) => ({ kind: "recent", query }));
        this.#active = -1;

        this.#render();
        this.#settle();
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

        this.#suggestions = items.slice(0, MAX_SUGGESTIONS);
        this.#active = -1;

        // The operator list is drawn from what is already in the browser, so it
        // is drawn AND SHOWN now rather than waiting on the network. The
        // results land underneath it whenever they land, and drop in without
        // disturbing the rows above — which is what lets a highlight survive
        // them, and what stops the list waiting on a round trip to tell
        // somebody that `is:` takes `unread`.
        this.#render();
        this.#settle();
        this.#live(this.inputTarget.value.trim());
    }

    /**
     * Ten conversations for what is in the box, from the type-ahead endpoint.
     *
     * Rendered by the server and inserted as it arrives: the rows carry
     * translated text and a subject fallback, and this file has neither.
     */
    async #live(query) {
        if (query.length < MIN_LIVE_LENGTH || OPERATOR_TOKEN.test(query)) {
            this.#clearResults();
            this.#settle();
            return;
        }

        const controller = new AbortController();
        this.#liveRequest = controller;

        let html;

        try {
            const response = await fetch(
                `${this.suggestUrlValue}?q=${encodeURIComponent(query)}`,
                { headers: { Accept: "text/html" }, signal: controller.signal },
            );

            if (false === response.ok) {
                return;
            }

            html = await response.text();
        } catch {
            // Including the abort above, which is not a failure — it is this
            // request being told it is no longer the current one, and the
            // newer one is already responsible for what the list shows.
            return;
        }

        // A response that arrived after its request was superseded has nothing
        // to say about the box as it now reads.
        if (controller !== this.#liveRequest) {
            return;
        }

        this.#liveRequest = null;
        this.#showResults(html);
        this.#settle();
    }

    /** Put the server's rows in, and take the count out of them. */
    #showResults(html) {
        if (false === this.hasResultsListTarget) {
            return;
        }

        this.resultsListTarget.innerHTML = html;

        // The count comes down inside the markup so it can be translated and
        // pluralised where the rest of the wording lives; it is moved into the
        // live region rather than left in the list, where it would be an
        // eleventh row nobody asked for.
        const status = this.resultsListTarget.querySelector("[data-search-status]");

        if (status && this.hasStatusTarget) {
            this.statusTarget.textContent = status.content.textContent.trim();
            status.remove();
        }

        this.#render();
    }

    #clearResults() {
        if (true === this.hasResultsListTarget) {
            this.resultsListTarget.innerHTML = "";
        }

        // The count goes with the rows it counted. Left behind it would be
        // read out on the next unrelated change to the region, which is how a
        // live region ends up announcing yesterday's number.
        if (true === this.hasStatusTarget) {
            this.statusTarget.textContent = "";
        }

        this.#render();
    }

    /** Open on anything to show, closed on nothing — after the rows settled. */
    #settle() {
        if (this.#items.length === 0) {
            this._closeDropdown();
            return;
        }

        // Results that arrive after Escape still go into the list — they are
        // what the next focus should show — but they do not reopen it.
        if (true === this.#dismissed) {
            return;
        }

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

        // A live result is a conversation, not a query: it opens the mail
        // rather than putting anything in the box, and it is not remembered as
        // a recent search — nobody searched. What the box then reads is
        // #syncToUrl's business, and its answer for a thread page is nothing.
        if (item.kind === "result") {
            this._closeDropdown();
            this._visit(item.url);
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

        // Arrowing into a list that is hidden would move a highlight nobody
        // can see, and the next Enter would then open something the reader
        // never chose. Reaching for the list is also a way of asking for it
        // back, so it overrides an earlier Escape.
        this.#dismissed = false;
        this._openDropdown();

        const next = this.#active + delta;

        // Wraps at both ends: the list is short, and a highlight that sticks
        // at the bottom edge just makes the arrow key feel broken.
        this.#active = next < 0
            ? this.#items.length - 1
            : (next >= this.#items.length ? 0 : next);

        // Paint, not render: moving the highlight is not a reason to rebuild
        // the rows, and rebuilding them under a fetch that is about to land
        // is how a list flickers.
        this.#paint();
    }

    /**
     * Draw both lists, work out what the arrow keys can now reach, and put the
     * highlight back where it was.
     *
     * One method rather than one per list, because the two are not independent:
     * the index the highlight sits on only means anything against the combined
     * order, so a list redrawing on its own would leave the other list's
     * highlight pointing at a row that had moved.
     */
    #render() {
        if (true === this.hasRecentsListTarget) {
            this.recentsListTarget.innerHTML = this.#suggestions
                .map((item, index) => (item.kind === "recent"
                    ? this.#recentMarkup(item, index)
                    : this.#suggestionMarkup(item, index)))
                .join("");
        }

        // Read back out of the DOM rather than kept alongside the HTML the
        // server sent: the rows carry their own destination in the href, so
        // there is no second copy of it here to fall out of step.
        const results = [...this.#resultOptions()].map((option) => ({
            kind: "result",
            url:  option.getAttribute("href"),
        }));

        this.#items = [...this.#suggestions, ...results];

        // A highlight with no row under it any more is not a highlight.
        if (this.#active >= this.#items.length) {
            this.#active = -1;
        }

        this.#layout(results.length);
        this.#paint();
    }

    /** Show each section only when it has rows, and title the first one. */
    #layout(resultCount) {
        const recents = this.#suggestions.length > 0 && this.#suggestions[0].kind === "recent";

        if (this.hasDropdownTitleTarget) {
            this.dropdownTitleTarget.textContent = recents
                ? this.recentsTitleValue
                : this.operatorsTitleValue;
        }

        // The header belongs to the first list, so it goes when that list does
        // — otherwise a query with results and no operator completions gets a
        // heading reading "Suggestions" over rows that are messages.
        if (this.hasHeaderTarget) {
            this.headerTarget.classList.toggle("hidden", this.#suggestions.length === 0);
        }

        // "Clear" clears recent searches; over anything else it is a button
        // whose label is a lie.
        if (this.hasClearTarget) {
            this.clearTarget.classList.toggle("hidden", false === recents);
        }

        if (this.hasResultsGroupTarget) {
            this.resultsGroupTarget.classList.toggle("hidden", resultCount === 0);
        }
    }

    /**
     * The highlight, in the three places it has to exist at once: the class a
     * sighted user sees, aria-selected on the option, and aria-activedescendant
     * on the input — which is the only one a screen reader reads, since focus
     * never leaves the text box.
     */
    #paint() {
        const options = [
            ...(this.hasRecentsListTarget ? this.recentsListTarget.querySelectorAll('[role="option"]') : []),
            ...this.#resultOptions(),
        ];

        options.forEach((option, index) => {
            const active = index === this.#active;

            // Ids assigned here rather than rendered, because
            // aria-activedescendant needs one per row and half these rows come
            // from the server, which does not know their place in the combined
            // order.
            option.id = `search-option-${index}`;
            option.setAttribute("aria-selected", active ? "true" : "false");
            option.classList.toggle("bg-hover", active);

            if (active) {
                // `nearest`, so a highlight already on screen does not make the
                // list jump under the reader's eyes.
                option.scrollIntoView({ block: "nearest" });
            }
        });

        const active = options[this.#active];

        if (active) {
            this.inputTarget.setAttribute("aria-activedescendant", active.id);
        } else {
            this.inputTarget.removeAttribute("aria-activedescendant");
        }
    }

    #resultOptions() {
        return this.hasResultsListTarget
            ? this.resultsListTarget.querySelectorAll('[role="option"]')
            : [];
    }

    #recentMarkup(item, index) {
        return `
            <li role="presentation">
                <button
                    type="button"
                    role="option"
                    aria-selected="false"
                    data-query="${this._escape(item.query)}"
                    data-index="${index}"
                    data-action="click->mail--search#selectRecent"
                    class="group w-full flex items-center gap-2.5 px-3 py-2 text-sm text-ink-soft
                           hover:bg-hover transition-colors text-left"
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
            <li role="presentation">
                <button
                    type="button"
                    role="option"
                    aria-selected="false"
                    data-index="${index}"
                    data-action="click->mail--search#selectSuggestion"
                    class="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-ink-soft
                           hover:bg-hover transition-colors text-left"
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
        this._visit(`${SEARCH_ROUTE}?q=${encodeURIComponent(q)}`);
    }

    _visit(url) {
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

        this.inputTarget.setAttribute("aria-expanded", "true");
    }

    _closeDropdown() {
        this.#active = -1;

        if (this.hasDropdownTarget) {
            this.dropdownTarget.classList.add("hidden");
        }

        // Both, every time. A combobox that says it is expanded over a hidden
        // list, or points aria-activedescendant at a row nobody can see, tells
        // a screen reader the opposite of what the screen shows.
        this.inputTarget.setAttribute("aria-expanded", "false");
        this.inputTarget.removeAttribute("aria-activedescendant");
    }

    _handleOutsideClick(event) {
        if (!this.element.contains(event.target)) {
            // Dismissed for the same reason Escape dismisses, and it has to be
            // marked as such: clicking away leaves the request for the query
            // still in the box in flight, and without this the list reopens
            // itself on top of whatever the reader has just clicked onto.
            this.#dismissed = true;
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
