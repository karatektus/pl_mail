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

    async refreshCounts() {
        if (!this.hasCountsUrlValue) {
            return;
        }

        let counts;
        try {
            const response = await fetch(this.countsUrlValue, {
                headers: { Accept: "application/json" },
            });
            if (!response.ok) {
                return;
            }
            counts = await response.json();
        } catch {
            // Offline or the sync raced a navigation — the next sync retries,
            // and a full page load renders the counts server-side anyway.
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
