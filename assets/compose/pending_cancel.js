/**
 * A cancel that was pressed before there was anywhere to send it.
 *
 * WHY THIS CANNOT LIVE ON THE COMPOSE CONTROLLER
 * ──────────────────────────────────────────────
 * Pressing Send queues the real send on a worker with a ten-second delay and
 * gives the reader an eight-second window to call it off. The thing that knows
 * HOW to call it off — the undo URL — does not exist until the server has
 * answered with the message's id, so a cancel pressed before that answer lands
 * is recorded as standing and honoured when the arming markup arrives.
 *
 * That works, and it worked, right up until the reader also closed the window
 * in the same gap. The arming markup then connects into an emptied frame,
 * finds no composer to hand the cancel to, and returns — while the standing
 * flag goes to the grave with the controller that held it. Nobody tells the
 * server, and ten seconds later the worker sends a message the reader called
 * back. No error, no log line: from the code's point of view nothing failed.
 *
 * So the fact has to outlive the element that learned it. A module lives as
 * long as the page, which is longer than any one composer and shorter than the
 * session — exactly the lifetime of the thing being remembered.
 *
 * KEYED BY FRAME, NOT GLOBAL
 * ──────────────────────────
 * The dock and an inline reply can each hold a send at once, and a bare flag
 * would let a cancel in one call off the other — a worse bug than the one this
 * fixes, because it destroys mail somebody meant to send. The frame id is what
 * both halves can name: the composer records it at connect (it has to, since
 * `closest()` answers null once the frame is emptied) and the arming element
 * sits inside that same frame.
 *
 * TAKEN, NOT READ. A standing cancel is spent once. Leaving it behind would
 * make the NEXT send from that frame cancel itself.
 */

const pending = new Set();

/** A cancel was pressed with nowhere yet to send it. */
export function markPendingCancel(frameId) {
    if ("string" !== typeof frameId || "" === frameId) {
        return;
    }

    pending.add(frameId);
}

/**
 * Cleared because the composer is still here and will do it itself.
 *
 * Called when the hold arms normally. Without it a cancel honoured by a live
 * composer would ALSO be honoured by this, and the second one would land on
 * whatever that frame sent next.
 */
export function forgetPendingCancel(frameId) {
    pending.delete(frameId);
}

/** Whether this frame has a cancel owing, spending it if so. */
export function takePendingCancel(frameId) {
    if ("string" !== typeof frameId || "" === frameId) {
        return false;
    }

    return pending.delete(frameId);
}
