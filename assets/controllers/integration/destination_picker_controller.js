import { Controller } from "@hotwired/stimulus";

/**
 * Hands a folder chosen in the destination picker back to whoever opened it.
 *
 * Only mounted in the picker's part-less "pick" mode — see
 * integration/_destination.html.twig. With an attachment in hand a choice is a
 * save and the row posts it; with nothing in hand the choice is an answer, and
 * this is what carries it out of the dialog.
 *
 * The dialog and the thing that opened it are in different Turbo frames — the
 * chooser renders into the body-level #modal frame, the filter editor into
 * #filter-editor — so they cannot address each other through the DOM. The event
 * is the seam, dispatched at `document` and picked up with a `@document` action
 * the way core--mercure's are in _layout/app.html.twig. This controller knows
 * nothing about filters beyond the shape of the payload.
 *
 * Routes used: none. Nothing here talks to the server; the picker frame has
 * already done the browsing by the time a choice is made.
 */
export default class extends Controller {
    /**
     * Report the chosen container and close the dialog.
     *
     * `folder` is the service's own opaque id and travels verbatim; `label` is
     * the readable trail the driver built for it, for a listener that has to
     * show the choice to a person once the picker is gone.
     */
    choose(event) {
        const button = event.currentTarget;

        this.dispatch("chosen", {
            target: document,
            detail: {
                folder: this.#attribute(button, "data-destination-folder"),
                label: this.#attribute(button, "data-destination-label"),
            },
        });

        // Bubbles to #modal-backdrop, which turns ui--modal:close into a close
        // — the same seam integration--integration-picker uses after attaching.
        this.dispatch("close", { prefix: "ui--modal" });
    }

    // ── Private ─────────────────────────────────────────────────────────────

    /**
     * Read straight off the element rather than through Stimulus action params.
     *
     * Params are typecast: Stimulus runs JSON.parse over the value and keeps
     * the result when it parses. A folder id is an opaque string to everything
     * that touches it, and services do hand out ids that look like numbers — a
     * "4711" would arrive as a number and an id with a leading zero would come
     * back a different id than the one that was clicked, which is a rule
     * quietly saving somewhere else. The DOM holds the exact string; this takes
     * it.
     */
    #attribute(element, name) {
        return element?.getAttribute(name) ?? "";
    }
}
