/**
 * "Something about my mail just changed on the server — re-read your numbers."
 *
 * The sidebar badges are patched from /mail/sidebar/counts, and the only
 * immediate trigger they ever had was `mail--list-toolbar:written`, dispatched
 * by the bulk toolbar. That covered the one path a person almost never uses.
 * The three paths they do use — opening a conversation (mail--thread-read),
 * the envelope button on a row (mail--message-row) and the reading pane's
 * overflow menu (mail--message-actions) — all POST to the same status
 * endpoints, redraw their row from the returned Turbo Stream, and said nothing
 * to anybody else. So the row went un-bold while "Inbox 4" sat beside it,
 * and nothing corrected it: a mark-read publishes no Mercure sync, so the
 * badges' other trigger never came either. Measured at a full minute with no
 * refetch; only a reload or a navigation put the number right.
 *
 * Reported as the counter updating "nicht zuverlässig" rather than never,
 * because navigating between folders re-renders the badges server-side — so
 * whether it looked broken depended on whether you happened to click away
 * after reading.
 *
 * Hence one announcement, in one place, that every write path makes. A new
 * one that forgets to call this has the same bug, but it has it in a form
 * somebody can grep for.
 *
 * Deliberately NOT the toolbar's existing `:writing`/`:written` pair, which
 * stays exactly as it was. That pair is a different statement — "hold the list
 * frame refresh, I am part-way through a run of posts" — and it is answered by
 * mail--mail-pane#hold/#release with a fetch of the whole list frame. Firing
 * it from mail--thread-read would re-read the entire list on every mail
 * opened, which is the most common action in the app. This event asks only for
 * the counts, which is the thing that was actually stale.
 */

/** The document-level event. Listened for by ui--sidebar. */
export const WRITE_EVENT = "mail:written";

/**
 * Announce that a write landed.
 *
 * Bubbling and dispatched on `document`, because the listeners are the two
 * sidebars (the mobile drawer and the desktop column) and the writers are
 * inside the list frame or the reading pane — several DOM subtrees away, and
 * in the frame's case a subtree that is replaced wholesale.
 */
export function announceWrite() {
    document.dispatchEvent(new CustomEvent(WRITE_EVENT, { bubbles: true }));
}
