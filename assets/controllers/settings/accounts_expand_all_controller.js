/* stimulusFetch: 'lazy' */
import { Controller } from "@hotwired/stimulus";
import { jsonCsrfHeaders } from "../../csrf.js";

/**
 * Opens every account's folder list in the sidebar, or closes them all (#690).
 *
 * One button rather than two: it opens all while any is closed, and closes all
 * once every one is open, so it always does the useful thing and the heading
 * keeps one control beside Add account. What it will do is read off the rows
 * when it is pressed, not kept here, because each row opens and closes on its
 * own (settings--account-expand) and nothing tells this button.
 *
 * The rows are told through a window event, so the desktop rail and the
 * mobile drawer, which both render the accounts, go the same way. The server
 * is told once, for all of them, rather than once per row.
 *
 * Values:
 *   persistUrl  — POST /mail/sidebar/account-expanded, sent `{ all: bool }`
 *   expandLabel / collapseLabel — the button's name for each direction
 *
 * Targets:
 *   icon        — turned to say which way it goes next
 */
export default class extends Controller {
    static targets = ["icon"];
    static values = { persistUrl: String, expandLabel: String, collapseLabel: String };

    connect() {
        this._onChange = () => this.#describe();
        window.addEventListener("settings--account-expand:changed", this._onChange);
        this.#describe();
    }

    disconnect() {
        window.removeEventListener("settings--account-expand:changed", this._onChange);
    }

    /**
     * Inside the heading's <summary>, so the click would also fold the whole
     * section away: preventDefault stops that, as ui--modal#open does for Add
     * account beside it.
     */
    toggle(event) {
        event.preventDefault();
        event.stopPropagation();

        const expanded = false === this.#allOpen();

        window.dispatchEvent(new CustomEvent("settings--account-expand:all", { detail: { expanded } }));

        fetch(this.persistUrlValue, {
            method: "POST",
            headers: jsonCsrfHeaders(),
            body: JSON.stringify({ all: expanded }),
            keepalive: true,
        }).catch(() => {});

        this.#describe();
    }

    // ── Private ───────────────────────────────────────────────────────────

    #toggles() {
        const section = this.element.closest("details") ?? document;

        return Array.from(section.querySelectorAll('[data-settings--account-expand-target="toggle"]'));
    }

    #allOpen() {
        const toggles = this.#toggles();

        return toggles.length > 0 && toggles.every((toggle) => "true" === toggle.getAttribute("aria-expanded"));
    }

    #describe() {
        const open  = this.#allOpen();
        const label = open ? this.collapseLabelValue : this.expandLabelValue;

        this.element.setAttribute("aria-label", label);
        this.element.setAttribute("title", label);

        if (this.hasIconTarget) {
            this.iconTarget.classList.toggle("fa-angles-down", false === open);
            this.iconTarget.classList.toggle("fa-angles-up", open);
        }
    }
}
