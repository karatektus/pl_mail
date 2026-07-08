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

// Classes applied to the checkbox button in each state
const CB_BASE      = "border-gray-400 dark:border-gray-500 bg-white dark:bg-gray-800";
const CB_ACTIVE    = "border-blue-600 bg-blue-600 dark:border-blue-500 dark:bg-blue-500";

export default class extends Controller {
    static targets = [
        "checkboxBtn",     // the <button role="checkbox"> that acts as master checkbox
        "checkmark",       // the ✓ SVG inside checkboxBtn
        "indeterminate",   // the — SVG inside checkboxBtn
        "selectMenu",      // the dropdown
        "selectMenuBtn",
        "refreshSlot",     // wrapper div around the Refresh button
        "actions",         // wrapper div around bulk-action buttons
        "selectionCount",
    ];

    static values = { total: Number };

    // ── Lifecycle ─────────────────────────────────────────────────────────

    connect() {
        // Listen for the custom event fired by message_row_controller when
        // a row checkbox changes.
        this._onRowChange     = this._syncFromRows.bind(this);
        this._onClickOutside  = this._closeSelectMenu.bind(this);

        this.element.addEventListener("list-toolbar:row-changed", this._onRowChange);
        document.addEventListener("click", this._onClickOutside, { capture: true });

        this._syncFromRows();
    }

    disconnect() {
        this.element.removeEventListener("list-toolbar:row-changed", this._onRowChange);
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

    // ── Refresh ───────────────────────────────────────────────────────────

    refresh() {
        const frame = document.getElementById("inbox-list-frame");
        const icon  = this.refreshSlotTarget.querySelector("i");

        if (icon) { icon.classList.add("fa-spin"); }

        if (frame) {
            frame.addEventListener(
                "turbo:frame-load",
                () => { if (icon) { icon.classList.remove("fa-spin"); } },
                { once: true },
            );
            frame.reload();
        } else {
            Turbo.visit(window.location.href, { action: "replace" });
        }
    }

    // ── Bulk action stubs ─────────────────────────────────────────────────

    archiveSelected() {
        const ids = this._selectedIds();
        if (ids.length === 0) { return; }
        console.log("[list-toolbar] archive", ids);
        // TODO: POST /threads/bulk-archive { ids }
    }

    deleteSelected() {
        const ids = this._selectedIds();
        if (ids.length === 0) { return; }
        console.log("[list-toolbar] delete", ids);
        // TODO: POST /threads/bulk-delete { ids }
    }

    markReadSelected() {
        const ids = this._selectedIds();
        if (ids.length === 0) { return; }
        console.log("[list-toolbar] mark-read", ids);
        // TODO: POST /threads/bulk-mark-read { ids }
    }

    markUnreadSelected() {
        const ids = this._selectedIds();
        if (ids.length === 0) { return; }
        console.log("[list-toolbar] mark-unread", ids);
        // TODO: POST /threads/bulk-mark-unread { ids }
    }

    snoozeSelected() {
        const ids = this._selectedIds();
        if (ids.length === 0) { return; }
        console.log("[list-toolbar] snooze", ids);
        // TODO: open snooze picker then POST /threads/bulk-snooze { ids, until }
    }

    // ── Private ───────────────────────────────────────────────────────────

    /** All row checkboxes in the visible list */
    _rowCheckboxes() {
        return Array.from(
            document.querySelectorAll(
                "#message-list [data-controller~='message-row'] input[type='checkbox']",
            ),
        );
    }

    _checkedRows() {
        return this._rowCheckboxes().filter((cb) => cb.checked);
    }

    _selectedIds() {
        return this._checkedRows().map((cb) => {
            const li = cb.closest("[data-message-row-id-value]");
            return li ? parseInt(li.dataset.messageRowIdValue, 10) : null;
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

        // ── Refresh ↔ Actions slot swap ──────────────────────────────────
        if (this.hasRefreshSlotTarget) {
            this.refreshSlotTarget.classList.toggle("hidden", hasSelection);
            this.refreshSlotTarget.classList.toggle("flex",  !hasSelection);
        }

        if (this.hasActionsTarget) {
            this.actionsTarget.classList.toggle("hidden",  !hasSelection);
            this.actionsTarget.classList.toggle("flex",     hasSelection);
            this.actionsTarget.setAttribute("aria-hidden", String(!hasSelection));
        }

        // ── Count label ──────────────────────────────────────────────────
        if (this.hasSelectionCountTarget) {
            this.selectionCountTarget.textContent =
                checkedCount > 0 ? String(checkedCount) : "";
        }
    }

    /**
     * Sets the visual state of the master checkbox button.
     *
     * States:
     *   unchecked    — plain bordered box, no icon
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
