// assets/controllers/list_toolbar_controller.js
//
// Drives the Gmail-style toolbar above the message list.
//
// The master "checkbox" is a <button role="checkbox"> whose visual state
// (border colour, fill, checkmark/dash SVG) is set entirely here in JS.
// We avoid <input type="checkbox"> + CSS peer-* because the checkmark SVG
// lives *inside* the label, not as a peer sibling, so peer-checked: cannot
// reach it.

import { Controller } from "@hotwired/stimulus";
import * as Turbo from "@hotwired/turbo";
import { jsonCsrfHeaders } from "../../csrf.js";
import { announceWrite } from "../../mail_writes.js";

// Classes applied to the checkbox button in each state
const CB_BASE   = "border-field bg-field";
const CB_ACTIVE = "border-accent bg-accent";

// Base path prefix for all status actions — must match Symfony routing.
// Individual action URLs are built as: STATUS_BASE + "/thread/{id}/{action}"
const STATUS_BASE = "/status";

export default class extends Controller {
    static targets = [
        "checkboxBtn",     // the <button role="checkbox"> that acts as master checkbox
        "checkmark",       // the ✓ SVG inside checkboxBtn
        "indeterminate",   // the — SVG inside checkboxBtn
        "selectMenu",      // the dropdown
        "selectMenuBtn",
        "actions",         // wrapper div around bulk-action buttons
        "selectionCount",
        "viewBanner",       // the "select all N in this view" strip
        "viewBannerText",
        "viewBannerAction",
    ];

    static values = {
        total: Number,
        // What this list is showing, in the vocabulary the list template
        // already uses for itself ({% block list_scope %}) — see
        // App\Service\Mail\ListViewResolver. `scopeValue` carries the
        // category for the inbox and the label id for a label view.
        // `viewScope` / `viewValue`, not `scope` / `scopeValue`: Stimulus
        // spells a value as `data-…-<name>-value`, so a value called
        // `scopeValue` becomes `data-…-scope-value-value` while `scope` becomes
        // `data-…-scope-value`. The two are one character apart in the markup
        // and mean entirely different things, which is how the view descriptor
        // arrived empty and the bulk action archived nothing while answering
        // 200.
        viewScope: String,
        viewValue: String,
        unreadOnly: Boolean,
        i18n: Object,
    };

    /**
     * True once "select all N" has been pressed: the selection is the VIEW,
     * not the rows on screen.
     *
     * Cleared by anything that changes what is selected, including a bulk
     * action finishing — a person who deleted everything and then clicks
     * Archive must not archive everything that arrived since.
     */
    #allInView = false;

    // ── Lifecycle ─────────────────────────────────────────────────────────

    connect() {
        // Listen for the custom event fired by message_row_controller when
        // a row checkbox changes.
        this._onRowChange    = this._syncFromRows.bind(this);
        this._onClickOutside = this._closeSelectMenu.bind(this);

        this.element.addEventListener("mail--list-toolbar:row-changed", this._onRowChange);
        document.addEventListener("click", this._onClickOutside, { capture: true });

        this._syncFromRows();
    }

    disconnect() {
        this.element.removeEventListener("mail--list-toolbar:row-changed", this._onRowChange);
        document.removeEventListener("click", this._onClickOutside, { capture: true });
    }

    // ── Master checkbox (click handler) ───────────────────────────────────

    toggleAll() {
        // If anything is checked (all or some), uncheck everything.
        // If nothing is checked, check everything.
        const checkedCount = this._checkedRows().length;
        const targetState  = checkedCount === 0;

        this._setAllRows(targetState);
        this._syncFromRows();
    }

    // ── Select-type dropdown ──────────────────────────────────────────────

    toggleSelectMenu(event) {
        event.stopPropagation();
        this.selectMenuTarget.classList.toggle("hidden");
    }

    selectAll(event) {
        event?.preventDefault();
        this._setAllRows(true);
        this._syncFromRows();
        this._closeSelectMenu();
    }

    selectNone(event) {
        event?.preventDefault();
        this._setAllRows(false);
        this._syncFromRows();
        this._closeSelectMenu();
    }

    selectRead(event) {
        event?.preventDefault();
        this._selectBy((li) => li.dataset.unread !== "true");
        this._closeSelectMenu();
    }

    selectUnread(event) {
        event?.preventDefault();
        this._selectBy((li) => li.dataset.unread === "true");
        this._closeSelectMenu();
    }

    selectStarred(event) {
        event?.preventDefault();
        this._selectBy((li) => li.dataset.starred === "true");
        this._closeSelectMenu();
    }

    // ── Bulk actions ──────────────────────────────────────────────────────

    async archiveSelected() {
        await this._bulkPost("archive");
    }

    async deleteSelected() {
        await this._bulkPost("trash");
    }

    async markReadSelected() {
        await this._bulkPost("read", { read: true });
    }

    async markUnreadSelected() {
        await this._bulkPost("read", { read: false });
    }

    /**
     * Extend the selection past the page, to everything in this view.
     *
     * Offered only when every row on screen is already selected and there are
     * more than fit — the state where "select all" has visibly done less than
     * the words promise. It is what a bin holding a hundred and ninety-five
     * messages needs, and the same gesture as selecting every starred mail.
     */
    selectAllInView(event) {
        event?.preventDefault();

        this.#allInView = true;
        this._syncFromRows();
    }

    /** Back to the rows on screen. */
    clearViewSelection(event) {
        event?.preventDefault();

        this.#allInView = false;
        this._setAllRows(false);
        this._syncFromRows();
    }

    /**
     * The wake time arrives as a param from the snooze menu (mail--snooze-menu),
     * which computes it in the browser — the server has no timezone for the
     * session. Absent, this clears the snooze on the selection.
     */
    async snoozeSelected(event) {
        const ids = this._selectedIds();
        if (ids.length === 0) { return; }

        const { until = null } = event?.params ?? {};

        await this._bulkPost(ids, "snooze", { until });
    }

    // ── Private ───────────────────────────────────────────────────────────

    /** All row checkboxes in the visible list */
    _rowCheckboxes() {
        return Array.from(
            document.querySelectorAll(
                "#message-list [data-controller~='mail--message-row'] input[type='checkbox']",
            ),
        );
    }

    _checkedRows() {
        return this._rowCheckboxes().filter((cb) => cb.checked);
    }

    _selectedIds() {
        return this._checkedRows().map((cb) => {
            const li = cb.closest("[data-mail--message-row-id-value]");
            return li ? parseInt(li.getAttribute("data-mail--message-row-id-value"), 10) : null;
        }).filter(Boolean);
    }

    _setAllRows(checked) {
        this._rowCheckboxes().forEach((cb) => { cb.checked = checked; });
    }

    _selectBy(predicate) {
        this._rowCheckboxes().forEach((cb) => {
            const li = cb.closest("li");
            cb.checked = li ? predicate(li) : false;
        });
        this._syncFromRows();
    }

    /**
     * Posts the given action to every selected thread in parallel, then
     * renders all returned Turbo Stream fragments in document order.
     *
     * Reuses the same routes as single-row actions:
     *   POST /status/thread/{id}/{action}
     *
     * @param {number[]} ids     - thread IDs to act on
     * @param {string}   action  - route suffix: archive | trash | read | snooze | star
     * @param {object}   body    - optional JSON body (e.g. { read: true })
     */
    /**
     * One request for the whole selection.
     *
     * It used to be one request per conversation, fired in parallel. That is
     * survivable for the fifty rows a page holds and impossible for what this
     * now offers: "select all" over a bin of two hundred would have opened two
     * hundred connections, and a real mailbox is worse.
     *
     * It also could not answer for the list. Each response redrew its own row
     * and knew nothing else, so a list that lost four of five rows still said
     * "1–5 of 5" and an emptied one never showed its empty state — the pager
     * and the placeholder belong to the LIST, and no row-shaped response
     * carries them. The bulk response refreshes the frame instead.
     */
    async _bulkPost(action, body = {}) {
        const ids = this.#allInView ? [] : this._selectedIds();

        if (false === this.#allInView && ids.length === 0) {
            return;
        }

        // Holds the mail pane's refresh of the list frame for the duration —
        // see mail--mail-pane#hold. Without it the frame reloads mid-run and
        // the rows being acted on are replaced underneath the request.
        this.dispatch("writing");

        try {
            const response = await fetch(`${STATUS_BASE}/bulk/${action}`, {
                method: "POST",
                headers: jsonCsrfHeaders(),
                body: JSON.stringify({
                    ...body,
                    ids,
                    all: this.#allInView,
                    scope: this.viewScopeValue,
                    value: this.viewValueValue,
                    unreadOnly: this.unreadOnlyValue,
                }),
            });

            if (false === response.ok) {
                console.error(`[list-toolbar] bulk ${action} failed`, response.status);

                return;
            }

            // The rows first, so the click has an answer immediately, then the
            // list itself for the pager and the empty state — see
            // thread/status/_bulk.stream.html.twig for why it takes both.
            Turbo.renderStreamMessage(await response.text());

            // The list itself — the pager, the empty state, the total — is
            // re-read by the mail pane when the "written" event below releases
            // its hold. NOT by frame.reload(): this frame is rendered inline
            // and has no `src`, and reload() on a src-less turbo-frame does
            // nothing at all, silently. That is worth writing down, because it
            // looks exactly like a refresh that ran and found no changes.
        } finally {
            this.dispatch("written");
            announceWrite();

            // The selection is spent either way. Leaving #allInView set is how
            // a second click would act on everything that had arrived since —
            // which is the shape of the desync that was reported, made worse.
            this.#allInView = false;
            this._setAllRows(false);
            this._syncFromRows();
        }
    }

    /**
     * Single source of truth for all visual state.
     * Called after any change to row checkboxes or the master button.
     */
    _syncFromRows() {
        const all          = this._rowCheckboxes();
        const checkedCount = all.filter((cb) => cb.checked).length;
        const allChecked   = all.length > 0 && checkedCount === all.length;
        const someChecked  = checkedCount > 0 && checkedCount < all.length;
        const hasSelection = checkedCount > 0;

        // ── Master checkbox button visual state ──────────────────────────
        this._setCheckboxState(allChecked, someChecked);

        if (this.hasActionsTarget) {
            this.actionsTarget.classList.toggle("hidden",  !hasSelection);
            this.actionsTarget.classList.toggle("flex",     hasSelection);
            this.actionsTarget.setAttribute("aria-hidden", String(!hasSelection));
        }

        // ── Count label ──────────────────────────────────────────────────
        if (this.hasSelectionCountTarget) {
            this.selectionCountTarget.textContent = this.#allInView
                ? String(this.totalValue)
                : (checkedCount > 0 ? String(checkedCount) : "");
        }

        this.#syncViewBanner(allChecked);
    }

    /**
     * The "and the other 145" offer.
     *
     * Shown only where "select all" has visibly done less than the words
     * promise: every row on screen is ticked AND the view holds more than fit.
     * On a list that fits on one page there is nothing to extend to, and
     * offering it anyway would be a control that does nothing.
     */
    #syncViewBanner(allChecked) {
        if (false === this.hasViewBannerTarget) {
            return;
        }

        const extendable = allChecked && this.totalValue > this._rowCheckboxes().length;

        this.viewBannerTarget.classList.toggle("hidden", false === (extendable || this.#allInView));

        if (false === this.hasViewBannerTextTarget) {
            return;
        }

        // Two sentences, because they say opposite things: one offers to widen
        // the selection, the other reports that it IS widened and offers the
        // way back. A single string with a number swapped in would have to be
        // one or the other.
        const i18n = this.i18nValue ?? {};

        this.viewBannerTextTarget.textContent = this.#allInView
            ? (i18n.allSelected ?? "").replace("%count%", String(this.totalValue))
            : (i18n.selectAll ?? "").replace("%count%", String(this.totalValue));

        if (this.hasViewBannerActionTarget) {
            this.viewBannerActionTarget.textContent = this.#allInView
                ? (i18n.clear ?? "")
                : (i18n.selectAllAction ?? "");

            this.viewBannerActionTarget.setAttribute(
                "data-action",
                this.#allInView
                    ? "click->mail--list-toolbar#clearViewSelection"
                    : "click->mail--list-toolbar#selectAllInView",
            );
        }
    }

    /**
     * Sets the visual state of the master checkbox button.
     *
     * States:
     *   unchecked     — plain bordered box, no icon
     *   indeterminate — blue fill, dash icon
     *   checked       — blue fill, checkmark icon
     */
    _setCheckboxState(checked, indeterminate) {
        if (!this.hasCheckboxBtnTarget) { return; }

        const btn = this.checkboxBtnTarget;

        // aria
        btn.setAttribute("aria-checked", indeterminate ? "mixed" : String(checked));

        // background / border
        const isActive = checked || indeterminate;
        btn.classList.remove(...CB_BASE.split(" "), ...CB_ACTIVE.split(" "));
        btn.classList.add(...(isActive ? CB_ACTIVE : CB_BASE).split(" "));

        // icons
        if (this.hasCheckmarkTarget) {
            this.checkmarkTarget.classList.toggle("hidden", !checked || indeterminate);
        }
        if (this.hasIndeterminateTarget) {
            this.indeterminateTarget.classList.toggle("hidden", !indeterminate);
        }
    }

    _closeSelectMenu() {
        if (this.hasSelectMenuTarget) {
            this.selectMenuTarget.classList.add("hidden");
        }
    }
}
