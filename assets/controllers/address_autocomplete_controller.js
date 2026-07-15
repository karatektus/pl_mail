// assets/controllers/address_autocomplete_controller.js
//
// Uses Tom Select to power the recipient address fields in the compose window.
// Replaces the Symfony CollectionType approach with a single Tom Select
// instance per field (To / Cc / Bcc) that handles multiple addresses itself.
//
// Requires Tom Select in your importmap. Run once:
//   php bin/console importmap:require tom-select

import { Controller } from "@hotwired/stimulus";
import TomSelect from "tom-select";

const AUTOCOMPLETE_URL = "/contacts/autocomplete";
const MIN_CHARS        = 1;

export default class extends Controller {
    static targets = ["input"];

    #tomSelect = null;

    connect() {
        const input = this.inputTarget;

        // Forward clicks anywhere in the row to the TS input.
        this._boundRowClick = (e) => {
            console.log("row click", e.target);
            if (e.target.closest(".item")) { return; }
            this.#tomSelect?.focus();
        };
        this.element.addEventListener("click", this._boundRowClick);

        // Gather any pre-filled addresses from the hidden input's value
        // (e.g. when editing a draft or doing reply/forward).
        const prefilledJson = input.dataset.prefilled;
        let prefilledItems  = [];
        if (prefilledJson) {
            try { prefilledItems = JSON.parse(prefilledJson); } catch (_) {}
        }

        this.#tomSelect = new TomSelect(input, {
            // Allow typing any address freely
            create: true,
            createOnBlur: true,
            createFilter: /\S+@\S+\.\S+/,   // only create if it looks like an email
            maxItems: null,                   // unlimited recipients
            persist: false,                   // don't keep typed text after blur
            valueField: "value",
            labelField: "label",
            searchField: ["label", "email"],
            openOnFocus: false,
            hideSelected: true,
            closeAfterSelect: false,          // keep open so you can add another quickly
            preload: false,
            onItemAdd: function() {
                this.setTextboxValue('');
                this.close();
            },

            // ── Seed with pre-filled addresses (reply/forward/draft) ───────
            options: prefilledItems,
            items:   prefilledItems.map((i) => i.value),

            // ── Remote search ─────────────────────────────────────────────
            load: async (query, callback) => {
                if (query.length < MIN_CHARS) {
                    callback([]);
                    return;
                }

                try {
                    const response = await fetch(
                        `${AUTOCOMPLETE_URL}?q=${encodeURIComponent(query)}`,
                        { headers: { "X-Requested-With": "XMLHttpRequest" } },
                    );

                    if (!response.ok) { callback([]); return; }

                    const contacts = await response.json();

                    callback(contacts.map((c) => ({
                        value:       c.email,
                        label:       c.displayName ? `${c.displayName} <${c.email}>` : c.email,
                        email:       c.email,
                        displayName: c.displayName ?? "",
                        initials:    c.initials ?? c.email[0].toUpperCase(),
                    })));
                } catch (_) {
                    callback([]);
                }
            },

            // ── Renderers ────────────────────────────────────────────────
            render: {
                // Suggestion row in the dropdown
                option: (data, escape) => `
                    <div class="ts-custom-option">
                        <span class="ts-avatar">${escape(data.initials ?? data.email?.[0]?.toUpperCase() ?? "?")}</span>
                        <span class="ts-text">
                            ${data.displayName ? `<span class="ts-name">${escape(data.displayName)}</span>` : ""}
                            <span class="ts-email">${escape(data.email ?? data.value)}</span>
                        </span>
                    </div>`,

                // Selected chip inside the control
                item: (data, escape) => `
                    <div class="ts-chip" title="${escape(data.email ?? data.value)}">
                        ${escape(data.displayName || data.email || data.value)}
                    </div>`,

                // "Add <typed text>" row — rendered subtly at the bottom
                option_create: (data, escape) => `
                    <div class="ts-create-option">
                        <i class="fa-solid fa-plus ts-create-icon"></i>
                        Add <strong>${escape(data.input)}</strong>
                    </div>`,

                no_results: () => `<div class="ts-no-results">No contacts found</div>`,
                loading:    () => `<div class="ts-loading-msg">Searching…</div>`,
            },
        });
    }

    disconnect() {
        this.element.removeEventListener("click", this._boundRowClick);
        this.#tomSelect?.destroy();
        this.#tomSelect = null;
    }
}
