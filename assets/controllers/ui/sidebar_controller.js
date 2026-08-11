import { Controller } from "@hotwired/stimulus";

// `is-active` carries no colour of its own — it is the hook the sidebar's
// Gmail-style pill shape hangs off in app.css.
const ACTIVE_CLASSES   = ["is-active", "bg-accent-soft", "text-accent", "font-medium"];
// A parent of the open row. Its own class rather than the active set: the
// trail should read as "you are in here", not as a second selection.
const ANCESTOR_CLASS   = "is-active-ancestor";
const INACTIVE_CLASSES = ["text-ink-muted", "hover:bg-hover"];
const SYNC_EVENTS      = ["core--mercure:mailbox-synced", "core--mercure:account-synced"];
/**
 * Where the nav was scrolled to, and which label trees were open.
 *
 * Session storage for the scroll — it belongs to this run of the app, and
 * restoring yesterday's scroll would be a surprise; local for the trees, which
 * are a preference, and are the same shape as the collapsed admin panels the
 * server stores per user.
 */
const SCROLL_KEY = "sidebar_scroll";
const TREES_KEY  = "sidebar_closed_trees";

/**
 * One counts request, however many sidebars are listening.
 *
 * The sidebar partial is included twice — once for the mobile drawer, once for
 * the desktop column — so there are always two instances of this controller on
 * a mail page, both subscribed to the same sync events on `document`. Every
 * sync therefore fetched the counts twice, and a sync run publishes one event
 * per mailbox per account, so an idle inbox was measured at thirty-two counts
 * requests in ten seconds.
 *
 * Both problems are the same problem — the fetch belongs to the page, not to
 * the instance — so it lives here, at module scope, and both instances patch
 * their badges from the one answer.
 */
let inFlight = null;
let lastFetchAt = 0;

/** The floor between two counts requests. */
const MIN_COUNTS_MS = 15000;

function fetchCounts(url) {
    // Already asking: the second caller waits for the first one's answer rather
    // than starting a second request for the same numbers.
    if (inFlight !== null) {
        return inFlight;
    }

    lastFetchAt = Date.now();

    inFlight = fetch(url, { headers: { Accept: "application/json" } })
        .then((response) => (response.ok ? response.json() : null))
        .catch(() => null)
        .finally(() => {
            inFlight = null;
        });

    return inFlight;
}

export default class extends Controller {
    static targets = ["link", "badge", "scroller", "tree"];
    static values = { countsUrl: String };

    connect() {
        // Both before the first paint of this element, so nothing is seen in
        // the wrong place: the sidebar re-renders on every visit now, and a
        // scroll jump would be exactly the flicker turbo-permanent avoided.
        this._restoreTrees();
        this._restoreScroll();

        this._updateActive();
        this._onTurboLoad = () => this._updateActive();
        document.addEventListener("turbo:load", this._onTurboLoad);

        // The desktop sidebar is data-turbo-permanent, so a Turbo visit
        // carries the old element over and its badges would otherwise stay
        // stale. Patching them in place also keeps scroll position and any
        // collapsed label trees, which re-rendering the nav would reset.
        this._onSynced = () => this.refreshCounts();
        SYNC_EVENTS.forEach((name) =>
            document.addEventListener(name, this._onSynced),
        );
    }

    /**
     * An account's folder list arrives later, in its own frame, so the rows in
     * it miss the pass that ran on connect — they would sit unhighlighted
     * until the next navigation, which is exactly the click that just expanded
     * them.
     */
    linkTargetConnected() {
        this._updateActive();
    }

    disconnect() {
        document.removeEventListener("turbo:load", this._onTurboLoad);
        SYNC_EVENTS.forEach((name) =>
            document.removeEventListener(name, this._onSynced),
        );
    }

    /** A tree was opened or closed. Closed is what is stored, so a label added
     *  later shows up expanded rather than needing a backfill of everyone's
     *  preferences — the same call the admin panels make. */
    rememberTree(event) {
        const key = event.target.dataset.treeKey;

        if (!key) {
            return;
        }

        const closed = new Set(this._closedTrees());

        event.target.open ? closed.delete(key) : closed.add(key);

        localStorage.setItem(TREES_KEY, JSON.stringify([...closed]));
    }

    rememberScroll() {
        sessionStorage.setItem(SCROLL_KEY, String(this.scrollerTarget.scrollTop));
    }

    async refreshCounts({ immediate = false } = {}) {
        if (!this.hasCountsUrlValue) {
            return;
        }

        // Nobody is looking at the badges. The next sync after the tab comes
        // back redraws them, and a full page load renders them server-side.
        if (document.hidden && !immediate) {
            return;
        }

        // A burst of sync events is one refresh. Skipped rather than queued:
        // unlike the message list, a missed counts update is corrected by the
        // next one, and there is always a next one.
        if (!immediate && inFlight === null && Date.now() - lastFetchAt < MIN_COUNTS_MS) {
            return;
        }

        // Offline, or the sync raced a navigation — fetchCounts answers null and
        // the next sync retries.
        const counts = await fetchCounts(this.countsUrlValue);

        if (counts === null) {
            return;
        }

        this.badgeTargets.forEach((badge) => {
            const count = counts[badge.dataset.countKey];
            if (count === undefined) {
                return;
            }

            badge.textContent = count;
            badge.classList.toggle("hidden", count === 0);
        });

        this._updateTitle(counts);
    }

    /**
     * Keep the "(n)" in the tab in step with the badges.
     *
     * The title is server-rendered once and the badges are patched on every
     * sync, so the tab sat at (4) while the sidebar had moved on to 3. It now
     * comes from this same payload — the page names which key its title counts
     * (see app.html.twig) and nothing here needs to know what a mailbox is.
     *
     * The existing prefix is stripped rather than a stored base being kept,
     * because the rest of the title is localised and page-specific and there is
     * no reason to have a second copy of it that can go stale.
     */
    _updateTitle(counts) {
        const key = document
            .querySelector('meta[name="title-count-key"]')
            ?.content;

        if (!key) {
            return;
        }

        const count = counts[key];

        if (count === undefined) {
            return;
        }

        const base = document.title.replace(/^\(\d+\)\s*/, "");

        document.title = count > 0 ? `(${count}) ${base}` : base;
    }

    _updateActive() {
        const activeRows = [];

        this.linkTargets.forEach((link) => {
            // A system row is its own .nav-item; a custom label wraps the
            // anchor in one so the disclosure arrow and the edit button stay
            // outside the link. Tint the row either way, or a label would get
            // the colour on its text only while the pill stayed empty.
            const row = link.closest(".nav-item") ?? link;

            row.classList.remove(...ACTIVE_CLASSES, ANCESTOR_CLASS);
            row.classList.add(...INACTIVE_CLASSES);

            if (false === this._matches(link)) {
                return;
            }

            row.classList.add(...ACTIVE_CLASSES);
            row.classList.remove(...INACTIVE_CLASSES);
            activeRows.push(row);
        });

        // Marked after the loop, not inside it: an ancestor is itself a link
        // target, and the loop would have just finished calling it inactive.
        activeRows.forEach((row) => this._markAncestors(row));
    }

    /**
     * Whether a nav link points at what is currently open.
     *
     * Compared as a URL rather than as the raw attribute: an account's label
     * rows carry `?account=` and the location's pathname does not, so a string
     * compare matched nothing and those rows never highlighted at all. The
     * account has to agree too — the same label under another account is a
     * different view, and both rows lighting up says the wrong thing.
     */
    _matches(link) {
        const target = new URL(link.href, window.location.origin);
        const path = window.location.pathname;

        // Prefix, so a parent label counts as open while a child of it is:
        // full names are the path, "Work" is a prefix of "Work/Clients".
        if (path !== target.pathname && false === path.startsWith(target.pathname + "/")) {
            return false;
        }

        const wanted = target.searchParams.get("account");

        return null === wanted
            || wanted === new URLSearchParams(window.location.search).get("account");
    }

    /**
     * The trail down to an open row.
     *
     * Every nested label lives inside its parent's <details>, so the ancestry
     * is already in the DOM and needs no ids to walk — which matters for the
     * account lists, where the href is an id and carries no hint of the tree.
     */
    _closedTrees() {
        try {
            const stored = JSON.parse(localStorage.getItem(TREES_KEY) ?? "[]");

            return Array.isArray(stored) ? stored : [];
        } catch {
            return [];
        }
    }

    _restoreTrees() {
        const closed = new Set(this._closedTrees());

        this.treeTargets.forEach((tree) => {
            tree.open = false === closed.has(tree.dataset.treeKey);
        });
    }

    _restoreScroll() {
        if (false === this.hasScrollerTarget) {
            return;
        }

        const top = Number(sessionStorage.getItem(SCROLL_KEY) ?? 0);

        if (top > 0) {
            this.scrollerTarget.scrollTop = top;
        }
    }

    _markAncestors(row) {
        let details = row.closest("details")?.parentElement?.closest("details");

        while (details) {
            details.querySelector(":scope > summary.nav-item")?.classList.add(ANCESTOR_CLASS);
            details = details.parentElement?.closest("details");
        }
    }
}
