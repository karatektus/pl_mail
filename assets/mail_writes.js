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

/** The response header the server sets on a request that changed mail. */
const CHANGED_HEADER = "X-Mail-Changed";

/**
 * How long announcements are gathered before one is made.
 *
 * A write is now announced twice on most paths: once by watchForMailChanges()
 * below, the moment the response arrives, and once by the controller that made
 * the request, after it has drawn the result. The sidebar answers each
 * announcement with a counts request of its own unless they arrive together,
 * and "one request per write" is a property this file has been broken on
 * before. So they are gathered: every announcement inside the window is one
 * event. Long enough to cover a controller parsing and rendering its response,
 * far too short to see.
 */
const ANNOUNCE_WINDOW_MS = 120;

let announcing = null;

/**
 * Announce that a write landed.
 * Bubbling and dispatched on `document`, because the listeners are the two
 * sidebars (the mobile drawer and the desktop column) and the writers are
 * inside the list frame or the reading pane — several DOM subtrees away, and
 * in the frame's case a subtree that is replaced wholesale.
 */
export function announceWrite() {
    if (null !== announcing) {
        return;
    }

    announcing = setTimeout(() => {
        announcing = null;
        document.dispatchEvent(new CustomEvent(WRITE_EVENT, { bubbles: true }));
    }, ANNOUNCE_WINDOW_MS);
}

/**
 * Announce a write for every response the server marks as having changed mail.
 *
 * THE ANNOUNCEMENT USED TO BE A CONVENTION, AND CONVENTIONS HAVE GAPS. Each
 * controller that wrote was meant to call announceWrite() after its fetch. The
 * docblock at the top of this file even says what happens to one that forgets
 * — "the same bug, in a form somebody can grep for" — and the composer was one:
 * saving a draft, discarding it and sending it are all writes, none of them
 * announced, and the Drafts badge kept whatever number it had at page load. So
 * did every form that posts through Turbo rather than through a controller's
 * own fetch(), because there was nowhere in such a form to make the call.
 *
 * The server knows whether a request changed mail without being told — it has
 * to record every change for the phone apps — and says so on the response
 * (MailChangedHeaderSubscriber). Reading that here covers every request this
 * page makes, by whatever route, including ones not written yet.
 *
 * TWO HOOKS, BECAUSE THERE ARE TWO WAYS A REQUEST LEAVES THIS PAGE. Controllers
 * call fetch(); Turbo makes its own for form submissions, frames and visits,
 * through a reference to fetch it took when it loaded and which wrapping
 * `window.fetch` afterwards cannot reach. So fetch is wrapped for the first and
 * Turbo's own event is listened to for the second. A response both hooks see
 * is announced once — and announceWrite() gathers the rest.
 *
 * The explicit announceWrite() calls stay where they are. A write that changes
 * what the sidebar shows without touching a message would not be marked, and
 * they cost nothing beside this.
 */
export function watchForMailChanges() {
    if (true === window.__plMailWatchesChanges) {
        return;
    }

    window.__plMailWatchesChanges = true;

    const seen = new WeakSet();

    const inspect = (response) => {
        if (!(response instanceof Response) || seen.has(response)) {
            return;
        }

        seen.add(response);

        // Null for a cross-origin response that does not expose the header,
        // which is every one of them: only this server sets it.
        if ("1" === response.headers.get(CHANGED_HEADER)) {
            announceWrite();
        }
    };

    const nativeFetch = window.fetch.bind(window);

    window.fetch = async (...args) => {
        const response = await nativeFetch(...args);

        inspect(response);

        return response;
    };

    document.addEventListener("turbo:before-fetch-response", (event) => {
        inspect(event.detail?.fetchResponse?.response);
    });
}
