import { Controller } from "@hotwired/stimulus";
import * as Turbo from "@hotwired/turbo";
import { flashCard, matchingKeyword, normalise } from "../../settings_search.js";

/**
 * The command box: ⌘K / Ctrl+K from anywhere in the app. It began as the
 * Settings search (#569) and finds everything else as well (FLT-154).
 *
 * ## Why it is in ui/ and loads with the page
 *
 * It was in settings/, whose controllers are lazy (AssetDeliveryTest), and a
 * lazy controller is fetched when its element appears — which for this one is
 * every page, so the laziness saved nothing and cost the first keystroke: ⌘K
 * pressed while the module was still on its way went nowhere, and the e2e
 * spec that opens the palette straight after a page load failed on CI's slower
 * runners. Eager, the shortcut works from the moment the page does.
 *
 * ## What it searches
 *
 * Built each time it opens, because each depends on the page under it:
 *
 *   - What the keyboard shortcuts would do right now, on the selection, the
 *     open conversation or the highlighted row: ui--shortcuts answers which
 *     (`ui--shortcuts:commands`) and runs the one picked (`ui--shortcuts:run`),
 *     so a command does exactly what its key does. With the shortcuts switched
 *     off that controller is not on the page, and there are none.
 *   - Every place the sidebar links to — folders, labels, accounts — and the
 *     inbox's tabs where the strip is showing, with the `g` key of the ones
 *     that have one.
 *
 * Then the settings, from GET /settings/search, fetched the first time the
 * palette opens and kept for the life of the page: every page and every card,
 * translated, with the keywords each card is also found by. About eighty
 * entries. All of it is filtered here, per keystroke, by the rule in
 * assets/settings_search.js that the tree's filter uses too.
 *
 * Ranked by where the words matched, best first: the start of a title, then
 * anywhere in a title, then the area or page it sits in, then a keyword. A
 * stable sort within each rank keeps the order above (commands, places, then
 * the tree's own), so equal matches come out the way Settings lists them.
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
 *   messages  — { count, empty, keyword, failed, page, action, place, tab },
 *               translated; count carries %count%
 *   commands  — { "e": "Archive", "* a": "Select all", … }, the name of each
 *               command ui--shortcuts may offer
 *
 * Targets:
 *   input   — the search field
 *   status  — "4 results" / "Nothing found", announced politely
 *   list    — the listbox the results go into
 *   row     — <template> of one result
 */
const LIMIT = 12;

/** Every link in the sidebar, in the desktop rail and the phone's drawer alike. */
const PLACES = "[data-ui--sidebar-target~='link'][href]";

const TABS = "a[data-category-tab-link]";

/** How a key is shown: `e`, and Shift for a capital, as the tooltips show it. */
function keyName(key) {
    return /^[A-Z]$/.test(key) ? `Shift+${key}` : key;
}

export default class extends Controller {
    static values = {
        url:      String,
        messages: Object,
        commands: Object,
    };

    static targets = ["input", "status", "list", "row", "key"];

    #entries = null;
    #failed = false;
    #here = [];
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
        this.#here = [...this.#commands(), ...this.#places()].map((entry) => this.#prepare(entry));
        this.inputTarget.value = "";
        this.#failed = false;
        this.element.showModal();
        this.inputTarget.focus();
        this.search();

        if (null === this.#entries) {
            await this.#load();

            // Whatever was typed or picked while they loaded stays picked.
            if (true === this.element.open) {
                this.#show(this.#shown[this.#selected]);
            }
        }
    }

    closed() {
        this.listTarget.replaceChildren();
        this.inputTarget.removeAttribute("aria-activedescendant");
    }

    search() {
        this.#show(undefined);
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

    /** Filters for what is typed, selecting `keep` where it is still listed, else the best match. */
    #show(keep) {
        const query = normalise(this.inputTarget.value.trim());
        const all = [...this.#here, ...(this.#entries ?? [])];

        // Before anything is typed: the commands, the places a key goes to, and
        // the settings pages — what a hand would reach for, not every label.
        this.#shown = "" === query
            ? all.filter((entry) => entry.page || (entry.keys ?? []).length > 0)
            : this.#rank(all, query).slice(0, LIMIT);
        this.#render(query);
        this.#select(Math.max(0, this.#shown.findIndex((entry) => entry.title === keep?.title && entry.crumb === keep?.crumb)));
    }

    async #load() {
        try {
            const response = await fetch(this.urlValue, { headers: { Accept: "application/json" } });

            if (false === response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const { entries } = await response.json();

            this.#entries = entries.map((entry) => this.#prepare(entry));
        } catch {
            // Left null, so the next open asks again rather than searching an
            // empty list and saying "nothing found" about every setting.
            this.#failed = true;
        }
    }

    #prepare(entry) {
        return { ...entry, normTitle: normalise(entry.title), normCrumb: normalise(entry.crumb) };
    }

    /** What ui--shortcuts would do now, named; see "What it searches". */
    #commands() {
        const ask = new CustomEvent("ui--shortcuts:commands", { detail: { commands: [] } });

        document.dispatchEvent(ask);

        return ask.detail.commands
            .filter((command) => Object.hasOwn(this.commandsValue, command))
            .map((command) => ({
                title: this.commandsValue[command],
                crumb: this.messagesValue.action ?? "",
                terms: "",
                keys: command.split(" "),
                run: command.split(" "),
            }));
    }

    /**
     * The sidebar's links and the inbox's tabs. A link is named by its title
     * where it has one (an account, by its address), and otherwise by its
     * text without the counts and new-mail dots the sidebar patches into it;
     * each place once, though the rail and the drawer both draw it. An
     * account's folders sit under its address, as every account has an Inbox,
     * and a nested label under its parents, as Work and Personal can both
     * have Invoices; its whole path ("Work/Invoices") finds it too.
     */
    #places() {
        const go = this.#goKeys();
        const seen = new Set();
        const places = [];

        const add = (link, crumb, parents = []) => {
            const url = new URL(link.getAttribute("href"), window.location.href);
            const title = (this.#title(link) || this.#text(link)).replace(/\s+/g, " ").trim();

            if ("" === title || seen.has(url.href)) {
                return;
            }

            seen.add(url.href);

            const key = ("" === url.search ? go[url.pathname] : undefined) ?? go[link.dataset.alsoOpenAt];

            places.push({
                title,
                crumb: [crumb, ...parents].join(" › "),
                terms: 0 === parents.length ? "" : [...parents, title].join("/"),
                url: url.href,
                keys: undefined === key ? [] : ["g", key],
            });
        };

        document.querySelectorAll(PLACES).forEach((link) => add(link, this.#crumb(link), this.#parents(link)));
        document.querySelectorAll(TABS).forEach((link) => add(link, this.messagesValue.tab ?? ""));

        return places;
    }

    #crumb(link) {
        const account = link.closest("[data-settings--account-expand-account-value]")?.querySelector(PLACES);

        if (account instanceof HTMLElement && account !== link) {
            return this.#title(account);
        }

        return link.closest("details.group\\/section")?.querySelector("summary")?.getAttribute("aria-label")
            ?? this.messagesValue.place
            ?? "";
    }

    /** A link's title, which ui--tooltip moves to data-tooltip once the link is hovered. */
    #title(link) {
        return link.getAttribute("title") || link.dataset.tooltip || "";
    }

    /** The labels a nested one sits in, outermost first. */
    #parents(link) {
        const parents = [];

        for (let tree = link.closest("details.group\\/tree"); null !== tree; tree = tree.parentElement.closest("details.group\\/tree")) {
            const summary = tree.querySelector(":scope > summary");

            if (false === summary.contains(link)) {
                parents.unshift(this.#text(summary.querySelector(PLACES) ?? summary).replace(/\s+/g, " ").trim());
            }
        }

        return parents;
    }

    #text(link) {
        const copy = link.cloneNode(true);

        copy.querySelectorAll("[data-count-key], .sr-only").forEach((count) => count.remove());

        return copy.textContent;
    }

    /** The `g` keys, by the path each goes to: { "/mail/inbox": "i", … }. */
    #goKeys() {
        try {
            const go = JSON.parse(document.body.getAttribute("data-ui--shortcuts-go-value") ?? "{}");

            return Object.fromEntries(Object.entries(go).map(([key, path]) => [path, key]));
        } catch {
            return {};
        }
    }

    #rank(entries, query) {
        const ranked = [];

        entries.forEach((entry, order) => {
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
            row.querySelector("[data-part='keys']").replaceChildren(...(entry.keys ?? []).map((key) => {
                const kbd = this.keyTarget.content.firstElementChild.cloneNode(true);

                kbd.textContent = keyName(key);

                return kbd;
            }));
            row.addEventListener("mousemove", () => this.#select(index));
            row.addEventListener("click", () => this.#go(entry));

            return row;
        });

        this.listTarget.replaceChildren(...rows);

        // A failed load says so over "nothing found": the settings were not
        // searched, so nothing is not what was found.
        if (true === this.#failed && ("" === query || 0 === rows.length)) {
            this.statusTarget.textContent = this.messagesValue.failed ?? "";
        } else {
            this.statusTarget.textContent = "" === query
                ? ""
                : (0 === rows.length ? this.messagesValue.empty : this.messagesValue.count.replace("%count%", String(rows.length)));
        }
    }

    #select(index) {
        this.#selected = index;

        if (0 === this.#shown.length) {
            this.inputTarget.removeAttribute("aria-activedescendant");
        }

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

        // After the close: a key is not pressed while a dialog is open.
        if (undefined !== entry.run) {
            document.dispatchEvent(new CustomEvent("ui--shortcuts:run", { detail: { keys: entry.run } }));

            return;
        }

        // A place opens by its own link where one can be pressed, the way the
        // sidebar opens it: into the list's frame where the link says so. The
        // phone's drawer is inert while shut, and the rail is hidden there.
        const link = [...document.querySelectorAll(`${PLACES}, ${TABS}`)]
            .find((candidate) => candidate.href === entry.url && null === candidate.closest("[inert]") && false !== candidate.checkVisibility?.());

        if (undefined !== link) {
            link.click();

            return;
        }

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
