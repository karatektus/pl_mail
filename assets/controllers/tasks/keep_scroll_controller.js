import { Controller } from "@hotwired/stimulus";

/**
 * The tasks list stays where it was when the pane is redrawn.
 *
 * A task's tick or a save answers with the whole pane, and a list redrawn from
 * the top is away from the row it was about — ticking something far down a long
 * list threw you back to its first task. So the list's scroll position is taken
 * before the frame renders and put back after, as long as the frame is showing
 * the same list (its `data-tasks-pane-scroll` names the address it was drawn
 * for); going to another list starts at the top, as it should.
 *
 * This lived in tasks--pane until #755 replaced the docks with workspaces. It is
 * the one thing that controller did for the pane's content rather than for
 * where the pane sat, so it stayed, on the tasks pane's own element: outside
 * the frame, so a redraw cannot take it away, and moved with the pane wherever
 * the workspace puts it.
 *
 * Mounted on the tasks pane (_layout/_mailbox.html.twig).
 */
export default class extends Controller {
    connect() {
        this._kept = null;

        this._before = (event) => {
            if ("tasks-pane-frame" !== event.target.id) {
                return;
            }

            const from = event.target.querySelector("[data-tasks-pane-scroll]");
            const to = event.detail.newFrame.querySelector("[data-tasks-pane-scroll]");

            this._kept = from?.dataset.tasksPaneScroll === to?.dataset.tasksPaneScroll ? from?.scrollTop : null;
        };

        // A frame later, once tasks--row has folded its rows back out: the list
        // is too short for this position until then.
        this._after = (event) => {
            if ("tasks-pane-frame" !== event.target.id || !this._kept) {
                return;
            }

            const top = this._kept;

            requestAnimationFrame(() => {
                const scroller = event.target.querySelector("[data-tasks-pane-scroll]");

                if (scroller) {
                    scroller.scrollTop = top;
                }
            });
        };

        this.element.addEventListener("turbo:before-frame-render", this._before);
        this.element.addEventListener("turbo:frame-render", this._after);
    }

    disconnect() {
        this.element.removeEventListener("turbo:before-frame-render", this._before);
        this.element.removeEventListener("turbo:frame-render", this._after);
    }
}
