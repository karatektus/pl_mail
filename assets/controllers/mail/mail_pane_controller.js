import { Controller } from "@hotwired/stimulus";

// MailboxSpecialUse values (see App\Domain\Enum\MailboxSpecialUse) mapped to
// the data-sync-scope tokens used by the list templates.
/** The frame wrapping the list itself — see templates/_layout/_mailbox.html.twig. */
const LIST_FRAME_ID = "inbox-list-frame";

const SYNC_SCOPES = {
    "\\Inbox": "inbox",
    "\\Sent": "sent",
    "\\Trash": "trash",
    "\\Drafts": "drafts",
    "\\Junk": "junk",
    "\\Archive": "archive",
};

/**
 * The floor between two list refreshes.
 *
 * A sync run publishes one mailbox.synced per mailbox per account, so a poll
 * across three accounts arrives as a burst rather than as one event. The old
 * in-flight guard only stopped two fetches overlapping, which left a burst of
 * eight producing eight sequential full-page requests — measured at eight
 * requests per ten seconds of an idle inbox. A burst is one refresh now; the
 * rest coalesce into a single trailing one.
 */
const MIN_REFRESH_MS = 15000;

/** Asks the server for the list frame alone — see App\Twig\ListFragmentGlobal. */
const FRAGMENT_HEADER = "X-List-Fragment";

export default class extends Controller {
    static targets = ["list", "reading"];
    static values = { open: Boolean , mailBoxId: Number};

    connect() {
        this._listUrl = this.openValue ? null : window.location.href;
        this._onPopState = this._handlePopState.bind(this);
        window.addEventListener("popstate", this._onPopState);

        // The sidebar's mail links navigate the LIST FRAME rather than the
        // page (so the calendar pane holds perfectly still). This pane sits
        // outside that frame, so a swap would otherwise leave the previous
        // message open beside a list it was never part of, with a back-URL
        // pointing at the label the user just left. A swap shows the new
        // list and adopts its URL as the place `close` returns to.
        this._onListSwap = (event) => {
            if (LIST_FRAME_ID !== event.target.id) {
                return;
            }

            this._listUrl = window.location.href;
            this._showList();
        };
        document.addEventListener("turbo:frame-load", this._onListSwap);

        // A refresh that came due while the tab was in the background is held
        // rather than dropped, and taken the moment it is looked at again.
        this._refreshPending = false;
        this._lastRefreshAt = 0;
        this._refreshTimer = null;

        // Depth, not a boolean, for the reason auto_refresh_controller gives:
        // a bulk action fires one write per selected row and they overlap, so
        // the first to finish would otherwise resume refreshing while the rest
        // are still in flight.
        this._holds = 0;

        this._onVisibility = () => {
            if (!document.hidden && this._refreshPending) {
                this._refreshList();
            }
        };
        document.addEventListener("visibilitychange", this._onVisibility);

        // Restore correct visual state on direct load / refresh
        if (this.openValue) {
            this._showReading();
        } else {
            this._showList();
        }
    }

    disconnect() {
        window.removeEventListener("popstate", this._onPopState);
        document.removeEventListener("turbo:frame-load", this._onListSwap);
        document.removeEventListener("visibilitychange", this._onVisibility);

        // A trailing refresh outlives this controller otherwise: Turbo replaces
        // <body> on every visit, so an uncleared timer here is a fetch fired by
        // a controller that no longer has an element to write to — and one more
        // of them after every navigation.
        if (this._refreshTimer !== null) {
            clearTimeout(this._refreshTimer);
            this._refreshTimer = null;
        }
    }

    /**
     * A write started in the list — hold the refresh until it lands.
     *
     * The list refresh renders from server state, so one *issued* before a
     * write commits swaps the pre-write markup back in. That is bad enough on
     * its own; with a bulk action it is worse, because the rows are removed by
     * turbo-streams and a refresh landing mid-run replaces them with fresh
     * elements the remaining streams can no longer find. The archived rows then
     * stay on screen until something else redraws them.
     *
     * The same reasoning, and the same fix, as auto_refresh_controller#hold —
     * which is where this pattern is explained at length.
     */
    hold() {
        this._holds++;
    }

    /**
     * The write finished. The list is re-read from the server.
     *
     * Unconditionally, where this used to refresh only if a sync had come due
     * while the write was held. That covered the rows — the streams the write
     * returns redraw those — and missed everything else in the frame, which no
     * stream addresses: the inbox's category tabs carry their own unread
     * numbers, and marking two threads unread left "General 2" over four bold
     * rows until a reload. The frame is one fragment fetch, and a person who
     * just pressed a bulk-action button is waiting on the answer.
     */
    release() {
        this._holds = Math.max(0, this._holds - 1);

        if (0 !== this._holds) {
            return;
        }

        this._refreshList({ immediate: true });
    }

    async open(event) {
        event.preventDefault();

        const link = event.currentTarget;
        const url = link.href;

        // Remember where to go back to, if we don't already know.
        if (!this._listUrl) {
            this._listUrl = window.location.href;
        }

        await this._loadMessage(url);

        history.pushState({ mailPaneOpen: true }, "", url);
    }

    close(event) {
        if (event) {
            event.preventDefault();
        }

        // The URL moves back to the list BEFORE the list is shown, because
        // showing it may have to fetch it and _refreshList asks for wherever the
        // page currently is. The other way round it would fetch the thread's own
        // URL — whose list frame is deliberately empty — and fill the list with
        // the emptiness this is meant to cure.
        if (this._listUrl) {
            history.pushState({ mailPaneOpen: false }, "", this._listUrl);
            this._showList({ revalidate: true });

            return;
        }

        // No remembered list URL: the thread was loaded directly, so there is a
        // real previous entry and the browser renders it. popstate follows and
        // shows the list once the URL is the list's.
        history.back();
    }

    async _handlePopState(event) {
        const state = event.state;

        if (state && state.mailPaneOpen) {
            await this._loadMessage(window.location.href);
        } else {
            // The browser's own Back out of a thread, which uncovers the same
            // snapshot the in-app arrow does.
            this._showList({ revalidate: true });
        }
    }

    async _loadMessage(url) {
        const response = await fetch(url, {
            headers: { "X-Requested-With": "fetch" },
        });

        if (!response.ok) {
            window.location.href = url; // fall back to a real navigation on failure
            return;
        }

        const html = await response.text();
        this.readingTarget.innerHTML = html;
        this._showReading();
    }

    _showReading() {
        this.listTarget.classList.add("hidden");
        this.readingTarget.classList.remove("hidden");
    }

    /**
     * Reveal the list — and make sure there is one to reveal.
     *
     * A thread page renders the list frame empty on purpose
     * (templates/mail/thread.html.twig leaves `message_list` and `inbox_tabs`
     * blank, and the toolbar falls back to a total of zero), because the thread
     * route has no list to render. Going back from a thread therefore used to
     * uncover that empty frame — no rows, no tabs, no pagination — and it
     * stayed that way until the next poll happened to fetch a URL that had a
     * list in it.
     *
     * So the emptiness is asked about rather than assumed: the server marks the
     * frame with whether it actually rendered a list, and an unrendered one is
     * filled before it is shown.
     *
     * The other half of the same question is whether what IS there is still
     * true. Opening a conversation leaves the list in the DOM untouched, so
     * coming back uncovered the snapshot taken at the moment it was covered —
     * and the whole point of opening a thread is to change it. Reply to a
     * conversation and go back and the list still showed the message count, the
     * timestamp and the unread state from before the reply, until a reload.
     *
     * A revalidation cannot be allowed to reintroduce the blank pane, so the
     * two cases stay separate: an unrendered frame is filled BEFORE it is
     * shown, a rendered one is shown at once and corrected behind the reveal.
     * The list is therefore immediate and current, rather than one or the other.
     *
     * @param {{revalidate?: boolean}} options  `revalidate` for the paths that
     *        uncover a list which has been sitting behind something — going
     *        back from a thread. Not for a frame that has just been rendered
     *        by the server, which would be a second fetch of what just arrived.
     */
    _showList({ revalidate = false } = {}) {
        if (this._listNeedsRendering()) {
            // Whatever is on screen stays there for the moment it takes, rather
            // than being replaced by a blank pane and then by the list. The
            // fetch is a fragment now, so that moment is short.
            this._refreshList({ immediate: true }).finally(() => this._reveal());

            return;
        }

        this._reveal();

        if (true === revalidate) {
            this._refreshList({ immediate: true });
        }
    }

    _reveal() {
        this.readingTarget.classList.add("hidden");
        this.listTarget.classList.remove("hidden");
    }

    /**
     * Whether the frame currently holds a list that was actually rendered.
     *
     * `data-list-rendered` is written by the mailbox layout, so this is the
     * server's own answer rather than a guess from the DOM — an empty folder
     * legitimately has no rows and must not be confused with a frame that was
     * never populated.
     */
    _listNeedsRendering() {
        const frame = document.getElementById(LIST_FRAME_ID);

        return frame !== null && frame.dataset.listRendered !== "1";
    }

    onMailboxSynced(event) {
        const data = event.detail;

        // The list views are unified across accounts, so a synced mailbox is
        // relevant when its special-use role matches what the view shows.
        // Views that span every mailbox (label, search, starred) use "*".
        if (this._affectsCurrentView(data)) {
            this._refreshList();
        }
    }

    _affectsCurrentView(data) {
        if (!this.hasListTarget) {
            return false;
        }

        // The polling fallback carries no mailbox, because it is not reporting
        // one — it fires when the stream is down and the list may be stale for
        // any reason at all, so it has to refresh whatever view is open.
        if (data.poll) {
            return true;
        }

        const scope = this.listTarget.dataset.syncScope || "*";
        if (scope === "*") {
            return true;
        }

        return scope.split(" ").includes(SYNC_SCOPES[data.specialUse] ?? "");
    }

    /**
     * Refresh the list after a sync, and nothing else.
     *
     * Fetched and swapped by hand rather than through Turbo. A page visit
     * replaces the whole document and takes an open dialog, a half-typed form
     * and the compose window with it — which is how connecting a mail account
     * kept destroying the setup wizard it was connected from, since the account
     * triggers the sync that triggers this.
     *
     * `frame.reload()` looked like the scoped answer and is not: this frame is
     * server-rendered with no `src`, so Turbo has nothing to re-fetch and falls
     * back to reloading the page — the very thing being avoided, and with
     * `data-turbo-action="advance"` on the frame it navigates too.
     *
     * So: ask for the current URL, take the matching frame out of the response,
     * and swap its contents in. It cannot navigate, because nothing here
     * navigates.
     *
     * The sidebar keeps its own counts up to date from the same Mercure
     * updates, so nothing outside the frame needs this to redraw it.
     *
     * Only the frame comes back now, not the page it lives in. The request
     * carries X-List-Fragment and the mailbox layout answers with the list
     * alone — the same content the DOMParser below was extracting from 80 KB of
     * document and discarding the rest of.
     *
     * @param {{immediate?: boolean}} options  `immediate` skips the rate limit,
     *        for a refresh a person is waiting on rather than one a sync asked
     *        for — going back to an unrendered list, specifically.
     */
    async _refreshList({ immediate = false } = {}) {
        const frame = document.getElementById(LIST_FRAME_ID);

        if (frame === null) {
            console.warn("[mail-pane] no list frame to refresh");

            return;
        }

        // Nobody is looking. Remembered, not dropped: the visibilitychange
        // handler takes it the moment the tab is looked at again.
        if (document.hidden && !immediate) {
            this._refreshPending = true;

            return;
        }

        // The user is writing to this list right now. Held rather than dropped,
        // and taken by release() once the last write lands — see hold().
        if (this._holds > 0) {
            this._refreshPending = true;

            return;
        }

        // One at a time: a burst of sync events would otherwise have several
        // responses racing to write the same element.
        if (this._refreshing === true) {
            return;
        }

        // A sync run publishes one event per mailbox per account, so "a sync
        // happened" arrives as a burst. Take the first and coalesce the rest
        // into one trailing refresh, instead of serialising the whole burst.
        const waited = Date.now() - this._lastRefreshAt;

        if (!immediate && waited < MIN_REFRESH_MS) {
            if (this._refreshTimer === null) {
                this._refreshTimer = setTimeout(() => {
                    this._refreshTimer = null;
                    this._refreshList();
                }, MIN_REFRESH_MS - waited);
            }

            return;
        }

        this._refreshing = true;
        this._refreshPending = false;
        this._lastRefreshAt = Date.now();

        try {
            const response = await fetch(window.location.href, {
                headers: {
                    [FRAGMENT_HEADER]: LIST_FRAME_ID,
                    Accept: "text/html",
                },
                credentials: "same-origin",
            });

            if (response.ok === false) {
                return;
            }

            const fresh = new DOMParser()
                .parseFromString(await response.text(), "text/html")
                .getElementById(LIST_FRAME_ID);

            if (fresh === null) {
                console.warn("[mail-pane] no list frame in the response");

                return;
            }

            // A refresh asks for whatever URL the page is on, and while a
            // conversation is open that URL is the thread's — whose list frame
            // is deliberately empty. Copying that over a real list is what
            // emptied it, and the emptiness was only noticed later, on the way
            // back. A response that says it holds no list has nothing to swap
            // in, so it is not swapped in.
            if ("1" !== (fresh.dataset.listRendered ?? "1")) {
                return;
            }

            frame.innerHTML = fresh.innerHTML;

            // Carried over with the content: the response says whether it
            // actually rendered a list, and _listNeedsRendering() reads it back
            // off the live frame on the next Back.
            frame.dataset.listRendered = fresh.dataset.listRendered ?? "1";
        } catch (error) {
            // A failed refresh is not worth surfacing: the next sync event, or
            // the next navigation, redraws it anyway.
            console.warn("[mail-pane] list refresh failed", error);
        } finally {
            this._refreshing = false;
        }
    }
}
