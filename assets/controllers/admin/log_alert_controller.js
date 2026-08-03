import { Controller } from "@hotwired/stimulus";

/**
 * The unread-log outline on the user menu, and its counterpart beside the
 * Admin entry inside the menu.
 *
 * Both are rendered server-side — this only takes them away, when the log
 * browser reports itself open. The topbar is not inside that frame, so nothing
 * else would: reading the log marked it seen in the database and left the ring
 * exactly where it was until the next full page render.
 *
 * Removing rather than re-rendering, because "seen" is already true by then.
 * Anything logged afterwards brings the ring back on the next render, which is
 * what it is for.
 */
export default class extends Controller {
    static targets = ["ring", "badge"];

    static RING_CLASSES = ["ring-2", "ring-offset-1", "ring-red-500", "ring-amber-500"];

    clear() {
        if (this.hasRingTarget) {
            this.ringTarget.classList.remove(...this.constructor.RING_CLASSES);
        }

        this.badgeTargets.forEach((badge) => badge.remove());
    }
}
