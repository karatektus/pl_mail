/* stimulusFetch: 'lazy' */
import { Controller } from "@hotwired/stimulus";
import Sortable from "sortablejs";
import { openPane } from "../../panes.js";
import { requestFailed } from "../../request_errors.js";

/**
 * The order of the task lists, in the Lists dialog: drag on a pointer, buttons
 * everywhere else.
 *
 * settings--account-order's design, for its reasons: sortablejs directly so the
 * write carries a CSRF token, the whole order sent as JSON so a repeated request
 * lands on the same arrangement, one write at a time with only the latest order,
 * and a failed write puts the rows back. That controller is the account list's,
 * with its rows and its handle, which is why this is not it.
 *
 * Once an order is saved the pane behind is read again, since its pills follow it:
 * through openPane(), as a save's open_pane stream does, and not by reloading the
 * frame, which does nothing to a pane drawn open with the page — its frame has no
 * src to reload.
 *
 * Values:
 *   url      String  POST { ids }
 *   csrf     String  X-CSRF-Token
 *   paneUrl  String  where the pane is, to read it again
 *
 * Targets:
 *   list  the rows, each with data-task-list="id"
 */
export default class extends Controller {
    static targets = ["list"];
    static values = { url: String, csrf: String, paneUrl: String };

    connect() {
        this.sortable = Sortable.create(this.listTarget, {
            handle: "[data-task-list-handle]",
            animation: 150,
            onEnd: () => {
                this._syncEdges();
                this._persist();
            },
        });

        this._syncEdges();
        this.saved = this._ids();
        this.inFlight = false;
        this.pending = false;
    }

    disconnect() {
        this.sortable?.destroy();
    }

    /** What a drag does, for a keyboard and a phone. */
    move(event) {
        const row = event.currentTarget.closest("[data-task-list]");
        const direction = "up" === event.currentTarget.dataset.direction ? -1 : 1;
        const rows = this._rows();
        const target = rows.indexOf(row) + direction;

        if (target < 0 || target >= rows.length) {
            return;
        }

        -1 === direction ? rows[target].before(row) : rows[target].after(row);

        this._syncEdges();

        // Focus stays where it was pressed, or on the other arrow when this one
        // has just become a dead end.
        const pressed = event.currentTarget.dataset.direction;
        const same = row.querySelector(`[data-direction="${pressed}"]`);
        const other = row.querySelector(`[data-direction="${"up" === pressed ? "down" : "up"}"]`);

        (same?.disabled ? other : same)?.focus();

        this._persist();
    }

    // ── Private ───────────────────────────────────────────────────────────

    _rows() {
        return [...this.listTarget.querySelectorAll("[data-task-list]")];
    }

    _ids() {
        return this._rows().map((row) => Number(row.dataset.taskList));
    }

    _syncEdges() {
        const rows = this._rows();

        rows.forEach((row, index) => {
            const up = row.querySelector('[data-direction="up"]');
            const down = row.querySelector('[data-direction="down"]');

            if (up) up.disabled = 0 === index;
            if (down) down.disabled = rows.length - 1 === index;
        });
    }

    _persist() {
        if (this.inFlight) {
            this.pending = true;

            return;
        }

        const ids = this._ids();

        this.inFlight = true;
        this.pending = false;

        fetch(this.urlValue, {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": this.csrfValue, "X-Requested-With": "XMLHttpRequest" },
            body: JSON.stringify({ ids }),
        }).then((response) => {
            if (requestFailed(response)) {
                this._revert();
            } else {
                this.saved = ids;
                openPane("tasks", { src: this.paneUrlValue });
            }
        }).catch(() => {
            requestFailed(null);
            this._revert();
        }).finally(() => {
            this.inFlight = false;

            if (this.pending) {
                this._persist();
            }
        });
    }

    _revert() {
        this.pending = false;

        const rows = new Map(this._rows().map((row) => [Number(row.dataset.taskList), row]));

        this.saved.forEach((id) => {
            const row = rows.get(id);

            if (row) this.listTarget.append(row);
        });

        this._syncEdges();
    }
}
