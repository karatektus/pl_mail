// assets/controllers/search_controller.js
//
// Wires the topbar search input to /mail/search?q=...
// - Enter / form submit navigates
// - Escape clears the input
// - Stores recent searches in localStorage (up to 8)
// - Shows a dropdown of recent searches on focus

import { Controller } from "@hotwired/stimulus";

const STORAGE_KEY  = "mail_recent_searches";
const MAX_RECENTS  = 8;
const SEARCH_ROUTE = "/mail/search";

export default class extends Controller {
    static targets = ["input", "dropdown", "recentsList"];

    connect() {
        this._boundOutside = this._handleOutsideClick.bind(this);
        document.addEventListener("click", this._boundOutside, { capture: true });
    }

    disconnect() {
        document.removeEventListener("click", this._boundOutside, { capture: true });
    }

    // ── Input events ──────────────────────────────────────────────────────

    onKeydown(event) {
        if (event.key === "Enter") {
            event.preventDefault();
            this._submit();
            return;
        }

        if (event.key === "Escape") {
            this.inputTarget.value = "";
            this._closeDropdown();
            this.inputTarget.blur();
            return;
        }

        if (event.key === "ArrowDown") {
            event.preventDefault();
            this._focusFirstRecent();
        }
    }

    onFocus() {
        const recents = this._loadRecents();
        if (recents.length > 0) {
            this._renderRecents(recents);
            this._openDropdown();
        }
    }

    onInput() {
        // Close the dropdown while actively typing — it's distracting
        this._closeDropdown();
    }

    // ── Recent search item click ──────────────────────────────────────────

    selectRecent(event) {
        const item = event.currentTarget;
        const query = item.dataset.query;
        if (!query) { return; }

        this.inputTarget.value = query;
        this._closeDropdown();
        this._navigate(query);
    }

    removeRecent(event) {
        event.stopPropagation();
        const query = event.currentTarget.closest("[data-query]")?.dataset.query;
        if (!query) { return; }

        const recents = this._loadRecents().filter((r) => r !== query);
        this._saveRecents(recents);
        this._renderRecents(recents);

        if (recents.length === 0) {
            this._closeDropdown();
        }
    }

    clearRecents(event) {
        event?.preventDefault();
        this._saveRecents([]);
        this._closeDropdown();
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
        if (this.hasDropdownTarget) {
            this.dropdownTarget.classList.add("hidden");
        }
    }

    _handleOutsideClick(event) {
        if (!this.element.contains(event.target)) {
            this._closeDropdown();
        }
    }

    _focusFirstRecent() {
        if (!this.hasRecentsListTarget) { return; }
        const first = this.recentsListTarget.querySelector("[data-query]");
        first?.focus();
    }

    _renderRecents(recents) {
        if (!this.hasRecentsListTarget) { return; }

        if (recents.length === 0) {
            this.recentsListTarget.innerHTML = "";
            return;
        }

        this.recentsListTarget.innerHTML = recents.map((r) => `
            <li>
                <button
                    type="button"
                    data-query="${this._escape(r)}"
                    data-action="click->search#selectRecent"
                    class="group w-full flex items-center gap-2.5 px-3 py-2
                           text-sm text-ink-soft
                           hover:bg-hover
                           transition-colors text-left"
                >
                    <i class="fa-solid fa-clock-rotate-left text-ink-faint w-3.5 flex-shrink-0" aria-hidden="true"></i>
                    <span class="flex-1 truncate">${this._escape(r)}</span>
                    <span
                        role="button"
                        tabindex="0"
                        data-action="click->search#removeRecent"
                        class="opacity-0 group-hover:opacity-100 p-0.5 rounded
                               text-ink-faint hover:text-ink-soft
                               transition-opacity"
                        aria-label="Remove"
                    >
                        <i class="fa-solid fa-xmark text-xs" aria-hidden="true"></i>
                    </span>
                </button>
            </li>
        `).join("");
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
        return str
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;");
    }
}
