import { Controller } from "@hotwired/stimulus";

const STORAGE_KEY = "tasks.open-rows";

function remembered() {
    try {
        return JSON.parse(sessionStorage.getItem(STORAGE_KEY) ?? "[]");
    } catch {
        return [];
    }
}

/*
 * Which rows have their steps folded out. Out here rather than on a controller,
 * because a task's tick or a save reloads the pane's frame and takes the rows with
 * it, and the ones being worked through have to come back open. Storage only carries
 * it across a reload of the page, so a blocked one costs no more than that.
 */
const openRows = new Set(remembered());

/**
 * A task's steps folding out under its row.
 *
 * Values:
 *   id  Number  the task
 *
 * Targets:
 *   toggle  the arrow
 *   steps   the list it folds
 */
export default class extends Controller {
    static targets = ["toggle", "steps"];
    static values = { id: Number };

    connect() {
        this._render(openRows.has(this.idValue));
    }

    toggle() {
        this._set(false === openRows.has(this.idValue));
    }

    /**
     * The task itself was ticked off: nothing left to work through under it. Not when
     * it is unticked, which is no reason to touch what is open.
     */
    taskTicked(event) {
        if ("false" === event.target.querySelector("[role='checkbox']").getAttribute("aria-checked")) {
            this._set(false);
        }
    }

    // ── Private ───────────────────────────────────────────────────────────

    _set(open) {
        if (open) {
            openRows.add(this.idValue);
        } else {
            openRows.delete(this.idValue);
        }

        this._render(open);

        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify([...openRows]));
        } catch {
            // See openRows: this page keeps working from the set.
        }
    }

    _render(open) {
        this.stepsTarget.hidden = !open;
        this.toggleTarget.setAttribute("aria-expanded", open ? "true" : "false");
    }
}
