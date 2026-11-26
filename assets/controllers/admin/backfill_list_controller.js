/* stimulusFetch: 'lazy' */
import { Controller } from "@hotwired/stimulus";

/**
 * Keeps the highlighted row in the Backfills list on the task the detail
 * beside it shows.
 *
 * The rows load the detail into a frame of their own, so the list itself is
 * never re-rendered — that is what keeps its scroll position — and nothing
 * on the server can move the highlight any more. This moves it: at once on a
 * click, and again whenever the detail frame loads, from the task the loaded
 * detail names (`data-task` on its `[data-backfill-detail]` element — inside
 * the frame, because a frame load replaces the frame's children and keeps
 * the frame's own attributes as they were), so a back button or a failed load
 * cannot leave the highlight on a task that is not the one shown.
 *
 * The look is the template's: `aria-[current=true]:` classes on each row.
 *
 * Actions:
 *   select — click on a row (carries `data-task`)
 *   sync   — turbo:frame-load on the document; acts on admin-backfill-detail only
 */
export default class extends Controller {
    select(event) {
        this.#mark(event.currentTarget.dataset.task);
    }

    sync(event) {
        if ("admin-backfill-detail" !== event.target.id) {
            return;
        }

        this.#mark(event.target.querySelector("[data-backfill-detail]")?.dataset.task);
    }

    // ── Private ───────────────────────────────────────────────────────────

    #mark(task) {
        if (!task) {
            return;
        }

        for (const row of this.element.querySelectorAll("a[data-task]")) {
            row.setAttribute("aria-current", row.dataset.task === task ? "true" : "false");
        }
    }
}
