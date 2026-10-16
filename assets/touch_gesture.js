/*
 * touch_gesture.js — who owns a touch sequence.
 *
 * Several controllers read the same touches: the pull-to-refresh gesture on the
 * list, and (in later changes) row swipes and the long-press sheet. Left to each
 * of them, a touch that starts a swipe would also scroll the list, pull it, and
 * open a hold. This module is the one place that decides what a touch IS.
 *
 * Three things live here, and nothing else:
 *
 *   1. The decision. A sequence is measured from its touchstart. Once its reach
 *      (max of |dx| and |dy|) is DIRECTION_LOCK_PX, it is decided: vertical or
 *      horizontal. A tie counts as vertical, so the scroll wins a diagonal that is
 *      exactly even. Holding still for HOLD_MS, with no movement past
 *      HOLD_SLOP_PX, decides a hold instead. Once decided, it stays decided.
 *      Movement past the slop cancels a future hold for the rest of the sequence,
 *      so a hold cannot be started by a finger that wandered and then rested.
 *
 *   2. The claim. One consumer owns a sequence. claimSequence() refuses a second
 *      claimant until its holder calls releaseSequence(kind) at touchend or touchcancel.
 *
 *   3. The open surface. A surface (the sidebar drawer, and later an open row or
 *      the action sheet) registers a predicate that reads its own state, so
 *      nothing here mirrors a DOM flag and goes stale. While any surface reports
 *      open, claimSequence() refuses: no gesture starts over an open surface.
 *
 * What it does NOT do: read the DOM, time anything, or see a touch event. The
 * caller passes in displacement and elapsed milliseconds, which keeps every rule
 * above testable in Node (see tests/e2e/touch-gesture-arbiter.spec.ts). A hold
 * with no movement at all is only decided when the caller asks again after the
 * time has passed, so a caller that wants the hold to fire while the finger is
 * still must arrange its own timer and call move() from it.
 *
 * Measured on displacement from touchstart, never on the rendered translation,
 * so the rules do not depend on any easing applied to what the user sees.
 */

/** Movement, in CSS pixels, after which a sequence is decided as vertical or horizontal. */
export const DIRECTION_LOCK_PX = 10;

/** Time, in milliseconds, a finger must rest before the sequence is a hold. */
export const HOLD_MS = 450;

/**
 * Movement, in CSS pixels, a hold tolerates. Movement strictly past this cancels
 * the hold for the rest of the sequence. Exactly this much does not.
 */
export const HOLD_SLOP_PX = 8;

/** What a sequence has been decided to be, or that it is still undecided. */
export const Intent = Object.freeze({
    UNDECIDED: "undecided",
    HOLD: "hold",
    VERTICAL: "vertical",
    LEFT: "left",
    RIGHT: "right",
});

/** Open surfaces: name -> predicate. A surface is open while its predicate returns true. */
const surfaces = new Map();

/** Who owns the current sequence, or null when nobody does. */
let claimedBy = null;

/**
 * A new per-sequence holder. Call move() on every sample with the displacement
 * from touchstart and the time since it, and act on the returned Intent.
 *
 * @returns {{ move(dx: number, dy: number, elapsedMs: number): string }}
 */
export function createSequence() {
    let intent = Intent.UNDECIDED;
    let holdCancelled = false;

    return {
        move(dx, dy, elapsedMs) {
            // Sticky: once decided, a later sample cannot change the answer.
            if (intent !== Intent.UNDECIDED) {
                return intent;
            }

            const reach = Math.max(Math.abs(dx), Math.abs(dy));

            if (reach > HOLD_SLOP_PX) {
                holdCancelled = true;
            }

            if (reach >= DIRECTION_LOCK_PX) {
                if (Math.abs(dy) >= Math.abs(dx)) {
                    intent = Intent.VERTICAL;
                } else {
                    intent = dx < 0 ? Intent.LEFT : Intent.RIGHT;
                }

                return intent;
            }

            if (!holdCancelled && elapsedMs >= HOLD_MS) {
                intent = Intent.HOLD;
            }

            return intent;
        },
    };
}

/**
 * Register a surface. `isOpen` is read every time a claim is asked for, so it
 * must describe the surface's real state (a drawer reads its own `inert`), not a
 * copy of it.
 *
 * @param {string} name
 * @param {() => boolean} isOpen
 */
export function registerSurface(name, isOpen) {
    surfaces.set(name, isOpen);
}

/** Remove a surface, on disconnect. */
export function unregisterSurface(name) {
    surfaces.delete(name);
}

/** The name of the first registered surface that is open, or null. */
export function openSurface() {
    for (const [name, isOpen] of surfaces) {
        if (isOpen()) {
            return name;
        }
    }

    return null;
}

/**
 * Claim the current sequence for `kind`. Refused while another consumer holds it
 * and while any surface is open. The refusal is silent: the caller does nothing,
 * and the touch stays with whatever the browser would do with it.
 *
 * @param {string} kind  "pull", later "swipe" or "hold"
 * @returns {boolean} true when the caller now owns the sequence
 */
export function claimSequence(kind) {
    if (claimedBy !== null || openSurface() !== null) {
        return false;
    }

    claimedBy = kind;

    return true;
}

/**
 * Release the claim, if `kind` holds it. A caller that does not hold the claim
 * releases nothing and is told so: a touchend from a sequence that never claimed
 * must not free the claim of a consumer that is still mid-gesture.
 *
 * @param {string} kind  the consumer releasing, which must be the one that claimed
 * @returns {boolean} true when the claim was held by `kind` and is now released
 */
export function releaseSequence(kind) {
    if (claimedBy !== kind) {
        return false;
    }

    claimedBy = null;

    return true;
}
