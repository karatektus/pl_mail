import { Controller } from "@hotwired/stimulus";

/**
 * Reported mail: which rows are shown, which are ticked, and what leaves with
 * them.
 *
 * WHAT THIS REPLACED. The export used to take a scope — everything, or only the
 * unhandled part — which answers the question an admin has on their first visit
 * and none of the ones they have afterwards. The question that actually comes
 * up is "these six, the ones about the shop". No fixed scope expresses that, so
 * the page filters and ticks, and the export takes what is ticked.
 *
 * SELECTION IS CHECKED **AND** VISIBLE. Filtering does not untick anything: a
 * row hidden by a chip is simply not part of the selection while it is hidden,
 * and comes back ticked when it is shown again. The alternative — unticking on
 * filter — quietly destroys a selection somebody built up across two chips, and
 * they only find out from the file.
 *
 * WHY IT DOES NOT COPY ANYTHING ITSELF. It writes the chosen lines into the
 * `sink` element, which is also ui--clipboard's `source` target on the same
 * wrapper, so copying stays entirely that controller's job — including the
 * execCommand path a self-hosted plMail on plain HTTP depends on. A second copy
 * implementation here would be a second one to keep working.
 *
 * The export is a real form submission, not fetch: what comes back is a file,
 * and the browser is the only thing here that can save one. The ticked keys are
 * written into it as hidden inputs on every change, so the form is always
 * submittable — including by a keyboard user who reaches it with Enter.
 *
 * Targets:
 *   row     — a report's checkbox. `data-line` is its text, `data-key` its
 *             `kind:id`, and its `[data-report-row]` ancestor is what gets
 *             hidden.
 *   all     — the select-everything-visible checkbox.
 *   chip    — a filter button. `data-problem` / `data-status`; empty means "any".
 *   sink    — hidden; receives the selected lines, one per line.
 *   count   — optional; its text becomes "n selected".
 *   action  — buttons disabled while nothing is selected.
 *   keys    — container the hidden `keys[]` inputs are written into.
 */
export default class extends Controller {
    static targets = ["row", "all", "chip", "sink", "count", "action", "keys"];

    static values = {
        // "{n} selected" — translated at the call site, where `%count%` has
        // already been rendered as `{n}` (a literal `%count%` in the HTML reads
        // as a translation that failed to interpolate).
        countLabel: { type: String, default: "{n} selected" },
        filename: { type: String, default: "reported-mail.txt" },
    };

    connect() {
        this.problem = "";
        this.status = "";
        this.#apply();
    }

    /** A single report was toggled. */
    toggleRow() {
        this.#apply();
    }

    /** Everything currently on screen follows the header box. */
    toggleAll(event) {
        for (const row of this.#visibleRows()) {
            row.checked = event.target.checked;
        }

        this.#apply();
    }

    /**
     * A chip was pressed. Same chip again clears it, which is the only way back
     * to "everything" without hunting for an All chip on every row of them.
     */
    filter(event) {
        const chip = event.currentTarget;
        const group = "problem" in chip.dataset ? "problem" : "status";
        const value = chip.dataset[group] ?? "";

        this[group] = this[group] === value ? "" : value;
        this.#apply();
    }

    download() {
        const text = this.sinkTarget.textContent;

        if ("" === text) {
            return;
        }

        // Blob rather than a data: URI — a hundred reports is comfortably past
        // the length some browsers will follow in an href.
        const url = URL.createObjectURL(new Blob([text + "\n"], { type: "text/plain" }));
        const link = document.createElement("a");

        link.href = url;
        link.download = this.filenameValue;
        document.body.appendChild(link);
        link.click();
        link.remove();

        // Not revoked immediately: Firefox has cancelled the download of a URL
        // revoked in the same tick.
        setTimeout(() => URL.revokeObjectURL(url), 10_000);
    }

    /** Rows the current chips leave on screen. */
    #visibleRows() {
        return this.rowTargets.filter((row) => {
            const matchesProblem = "" === this.problem || row.dataset.problem === this.problem;
            const matchesStatus = "" === this.status || row.dataset.status === this.status;

            return matchesProblem && matchesStatus;
        });
    }

    /**
     * Show what the chips allow, then bring every indicator into agreement.
     *
     * One pass rather than a filter step and a selection step, because the two
     * are not independent: hiding a row changes the selection, and every count,
     * every hidden input and the state of the header box all follow from the
     * same list.
     */
    #apply() {
        const visible = new Set(this.#visibleRows());

        for (const row of this.rowTargets) {
            row.closest("[data-report-row]").hidden = false === visible.has(row);
        }

        for (const chip of this.chipTargets) {
            const group = "problem" in chip.dataset ? "problem" : "status";

            chip.dataset.active = String(this[group] === (chip.dataset[group] ?? ""));
        }

        const chosen = [...visible].filter((row) => row.checked);

        this.sinkTarget.textContent = chosen.map((row) => row.dataset.line).join("\n");

        // Rewritten wholesale rather than patched: the form has to hold exactly
        // the current selection at every moment, and reconciling inputs one at
        // a time is how it comes to hold a key for a row somebody unticked.
        this.keysTarget.replaceChildren(
            ...chosen.map((row) => {
                const field = document.createElement("input");

                field.type = "hidden";
                field.name = "keys[]";
                field.value = row.dataset.key;

                return field;
            }),
        );

        if (true === this.hasAllTarget) {
            this.allTarget.checked = 0 < chosen.length && chosen.length === visible.size;
            this.allTarget.indeterminate = 0 < chosen.length && chosen.length < visible.size;
        }

        if (true === this.hasCountTarget) {
            this.countTarget.textContent = this.countLabelValue.replace("{n}", chosen.length);
        }

        for (const action of this.actionTargets) {
            action.disabled = 0 === chosen.length;
        }
    }
}
