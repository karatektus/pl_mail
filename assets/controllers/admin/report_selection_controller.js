import { Controller } from "@hotwired/stimulus";

/**
 * Choose which reports to take, and keep the copy/download text in step.
 *
 * WHY IT DOES NOT COPY ANYTHING ITSELF. It writes the chosen lines into the
 * `sink` element, which is also ui--clipboard's `source` target on the same
 * wrapper — so copying stays entirely that controller's job, including the
 * execCommand path a self-hosted plMail on plain HTTP depends on. A second copy
 * implementation here would be a second one to keep working.
 *
 * Download is its own, because it has no such prior art: a Blob and an <a
 * download>, which is the whole of it.
 *
 * Everything starts CHECKED. The common case is still "take the lot", and a
 * panel that opens with nothing selected asks every reader to do work before
 * they can do the thing the card is for. Selecting is for the reader who wants
 * one group out of five.
 *
 * Targets:
 *   row     — a report's checkbox. `data-line` carries the text it stands for.
 *   group   — a group's checkbox. `data-group` matches the rows' own.
 *   all     — the select-everything checkbox.
 *   sink    — hidden; receives the selected lines, one per line.
 *   count   — optional; its text becomes "n selected".
 *   action  — buttons disabled while nothing is selected.
 */
export default class extends Controller {
    static targets = ["row", "group", "all", "sink", "count", "action"];

    static values = {
        // "%count% selected" — translated at the call site.
        countLabel: { type: String, default: "%count% selected" },
        filename: { type: String, default: "category-reports.txt" },
    };

    connect() {
        this.#sync();
    }

    /** A single report was toggled. */
    toggleRow() {
        this.#sync();
    }

    /** A group heading was toggled: its rows follow it. */
    toggleGroup(event) {
        const group = event.target.dataset.group;

        for (const row of this.rowTargets) {
            if (row.dataset.group === group) {
                row.checked = event.target.checked;
            }
        }

        this.#sync();
    }

    toggleAll(event) {
        for (const row of this.rowTargets) {
            row.checked = event.target.checked;
        }

        this.#sync();
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

    /**
     * The chosen lines into the sink, and every indicator into agreement.
     *
     * Group and select-all boxes are DERIVED here rather than tracked, so a row
     * unticked by hand shows up immediately as a half-ticked group above it.
     */
    #sync() {
        const chosen = this.rowTargets.filter((row) => row.checked);

        this.sinkTarget.textContent = chosen.map((row) => row.dataset.line).join("\n");

        for (const group of this.groupTargets) {
            const rows = this.rowTargets.filter((row) => row.dataset.group === group.dataset.group);
            const ticked = rows.filter((row) => row.checked).length;

            group.checked = 0 < ticked && ticked === rows.length;
            group.indeterminate = 0 < ticked && ticked < rows.length;
        }

        if (true === this.hasAllTarget) {
            this.allTarget.checked = 0 < chosen.length && chosen.length === this.rowTargets.length;
            this.allTarget.indeterminate =
                0 < chosen.length && chosen.length < this.rowTargets.length;
        }

        if (true === this.hasCountTarget) {
            this.countTarget.textContent = this.countLabelValue.replace("%count%", chosen.length);
        }

        for (const action of this.actionTargets) {
            action.disabled = 0 === chosen.length;
        }
    }
}
