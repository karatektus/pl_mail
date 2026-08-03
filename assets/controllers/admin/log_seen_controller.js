import { Controller } from "@hotwired/stimulus";

/**
 * Announces that the log browser is on screen.
 *
 * The server marks the log seen when it renders this frame, but the outline it
 * clears is on the user menu in the topbar — outside the frame, and so still
 * showing the state from whenever the page around it was rendered. Clearing
 * the log did not help either: same frame, same stale topbar.
 *
 * Announced rather than reached for directly: this frame knows the log was
 * opened, and nothing else. What that means for a ring somewhere else is the
 * topbar's business.
 */
export default class extends Controller {
    connect() {
        this.dispatch("seen", { target: document, prefix: "admin--log-seen" });
    }
}
