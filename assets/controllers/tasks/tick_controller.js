import { Controller } from "@hotwired/stimulus";

/**
 * A task's tick: on screen the moment it is pressed, with the save behind it.
 *
 * The form posts as it always did, and the pane that answers moves the row where
 * it now belongs. That takes two requests, which on a slow server was most of a
 * second with nothing to show for it, and a second press in that time was ignored
 * without a sign (#722). The tick, the title and a busy pointer change at once
 * instead, and a save that does not get through puts them back.
 *
 * Drawn from aria-checked as the button is, so the server's answer and this agree
 * on what a tick looks like. A repeating task comes back open, on its next date:
 * that is the answer's to show, with the toast that says so.
 *
 * Targets:
 *   box  the checkbox button
 */
export default class extends Controller {
    static targets = ["box"];

    /** After tasks--row has read which way it goes, so it is declared after it. */
    show(event) {
        if (event.defaultPrevented) {
            return;
        }

        this.was = "true" === this.boxTarget.getAttribute("aria-checked");
        this._draw(!this.was);
        this.boxTarget.classList.add("cursor-progress");
    }

    settled(event) {
        this.boxTarget.classList.remove("cursor-progress");

        if (false === event.detail.success && undefined !== this.was) {
            this._draw(this.was);
        }
    }

    // ── Private ───────────────────────────────────────────────────────────

    _draw(done) {
        this.boxTarget.setAttribute("aria-checked", done ? "true" : "false");

        const title = this.element.closest("[data-task-row]")?.querySelector("[data-task-title]");

        title?.classList.toggle("text-ink", !done);
        title?.classList.toggle("text-ink-faint", done);
        title?.classList.toggle("line-through", done);
    }
}
