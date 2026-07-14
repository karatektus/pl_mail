// assets/controllers/address_autocomplete_controller.js
//
// Attaches to each address input inside a compose collection row.
// Fetches /contacts/autocomplete?q=... and renders a dropdown of matches.
// Selecting a result fills the input and fires a change event.

import { Controller } from "@hotwired/stimulus";

const AUTOCOMPLETE_URL = "/contacts/autocomplete";
const MIN_CHARS        = 1;
const DEBOUNCE_MS      = 180;

export default class extends Controller {
    static targets = ["input", "dropdown"];

    #debounceTimer  = null;
    #activeIndex    = -1;
    #results        = [];

    connect() {
        this._boundKeydown   = this._onKeydown.bind(this);
        this._boundClickOut  = this._onClickOutside.bind(this);

        this.inputTarget.addEventListener("input",   this._onInput.bind(this));
        this.inputTarget.addEventListener("keydown", this._boundKeydown);
        this.inputTarget.addEventListener("focus",   this._onFocus.bind(this));
        document.addEventListener("click", this._boundClickOut, { capture: true });
    }

    disconnect() {
        clearTimeout(this.#debounceTimer);
        this.inputTarget.removeEventListener("keydown", this._boundKeydown);
        document.removeEventListener("click", this._boundClickOut, { capture: true });
    }

    // ── Event handlers ────────────────────────────────────────────────────

    _onInput() {
        clearTimeout(this.#debounceTimer);
        this.#debounceTimer = setTimeout(() => this._fetch(), DEBOUNCE_MS);
    }

    _onFocus() {
        const q = this.inputTarget.value.trim();
        if (q.length >= MIN_CHARS) {
            this._fetch();
        }
    }

    _onKeydown(event) {
        if (!this._isOpen()) {
            return;
        }

        if (event.key === "ArrowDown") {
            event.preventDefault();
            this._move(1);
        } else if (event.key === "ArrowUp") {
            event.preventDefault();
            this._move(-1);
        } else if (event.key === "Enter" && this.#activeIndex >= 0) {
            event.preventDefault();
            this._select(this.#results[this.#activeIndex]);
        } else if (event.key === "Escape") {
            this._close();
        }
    }

    _onClickOutside(event) {
        if (!this.element.contains(event.target)) {
            this._close();
        }
    }

    // ── Fetch & render ────────────────────────────────────────────────────

    async _fetch() {
        const q = this.inputTarget.value.trim();

        if (q.length < MIN_CHARS) {
            this._close();
            return;
        }

        try {
            const url      = `${AUTOCOMPLETE_URL}?q=${encodeURIComponent(q)}`;
            const response = await fetch(url, {
                headers: { "X-Requested-With": "XMLHttpRequest" },
            });

            if (!response.ok) {
                return;
            }

            this.#results = await response.json();
            this._render();
        } catch (_) {
            // Network error — silently ignore, don't break compose.
        }
    }

    _render() {
        if (this.#results.length === 0) {
            this._close();
            return;
        }

        this.#activeIndex = -1;
        const dropdown    = this.dropdownTarget;

        dropdown.innerHTML = "";

        this.#results.forEach((contact, index) => {
            const item = document.createElement("button");
            item.type  = "button";
            item.className = [
                "w-full flex items-center gap-3 px-3 py-2 text-left",
                "text-sm text-gray-700 dark:text-gray-200",
                "hover:bg-blue-50 dark:hover:bg-blue-500/10",
                "hover:text-blue-700 dark:hover:text-blue-300",
                "transition-colors",
            ].join(" ");
            item.dataset.index = String(index);

            // Initials avatar
            const avatar = document.createElement("span");
            avatar.className = [
                "flex-shrink-0 w-7 h-7 rounded-full",
                "flex items-center justify-center",
                "text-xs font-semibold text-white",
                "bg-gradient-to-br from-blue-500 to-indigo-600",
            ].join(" ");
            avatar.textContent = contact.initials;

            // Text block
            const text = document.createElement("span");
            text.className = "flex flex-col min-w-0";

            if (contact.displayName) {
                const name = document.createElement("span");
                name.className = "font-medium truncate";
                name.textContent = contact.displayName;
                text.appendChild(name);
            }

            const email = document.createElement("span");
            email.className = "text-xs text-gray-400 dark:text-gray-500 truncate";
            email.textContent = contact.email;
            text.appendChild(email);

            item.appendChild(avatar);
            item.appendChild(text);

            item.addEventListener("mousedown", (e) => {
                // Prevent input blur before we can read the selection.
                e.preventDefault();
            });

            item.addEventListener("click", () => {
                this._select(contact);
            });

            dropdown.appendChild(item);
        });

        dropdown.classList.remove("hidden");
    }

    // ── Selection ─────────────────────────────────────────────────────────

    _select(contact) {
        this.inputTarget.value = contact.email;

        // Fire change so Symfony's form collection knows the value updated.
        this.inputTarget.dispatchEvent(new Event("change", { bubbles: true }));

        this._close();
        this.inputTarget.focus();
    }

    // ── Keyboard navigation ───────────────────────────────────────────────

    _move(direction) {
        const items = this.dropdownTarget.querySelectorAll("button");

        if (items.length === 0) {
            return;
        }

        // Remove highlight from current item.
        if (this.#activeIndex >= 0) {
            items[this.#activeIndex].classList.remove(
                "bg-blue-50", "dark:bg-blue-500/10",
                "text-blue-700", "dark:text-blue-300",
            );
        }

        this.#activeIndex = Math.max(
            -1,
            Math.min(this.#activeIndex + direction, items.length - 1),
        );

        if (this.#activeIndex >= 0) {
            items[this.#activeIndex].classList.add(
                "bg-blue-50", "dark:bg-blue-500/10",
                "text-blue-700", "dark:text-blue-300",
            );
            items[this.#activeIndex].scrollIntoView({ block: "nearest" });
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    _isOpen() {
        return !this.dropdownTarget.classList.contains("hidden");
    }

    _close() {
        this.dropdownTarget.classList.add("hidden");
        this.dropdownTarget.innerHTML = "";
        this.#results     = [];
        this.#activeIndex = -1;
    }
}
