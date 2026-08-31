import { Controller } from "@hotwired/stimulus";
import { jsonCsrfHeaders } from "../../csrf.js";
import { WRITE_EVENT } from "../../mail_writes.js";

// `is-active` carries no colour of its own — it is the hook the sidebar's
// Gmail-style pill shape hangs off in app.css.
const ACTIVE_CLASSES   = ["is-active", "bg-accent-soft", "text-accent", "font-medium"];
// A parent of the open row. Its own class rather than the active set: the
// trail should read as "you are in here", not as a second selection.
const ANCESTOR_CLASS   = "is-active-ancestor";
const INACTIVE_CLASSES = ["text-ink-muted", "hover:bg-hover"];
const SYNC_EVENTS      = ["core--mercure:mailbox-synced", "core--mercure:account-synced"];
/**
 * A write to this user's mail finished — from anywhere.
 *
 * Kept apart from SYNC_EVENTS rather than added to them, because the two want
 * opposite treatment. A sync is the server volunteering that something changed
 * somewhere, arrives in bursts, and is rate-limited below on purpose. This is a
 * person having just pressed a button and watching the number they changed, so
 * it skips the limit — see refreshCounts({ immediate }).
 *
 * This used to be `mail--list-toolbar:written`, listened for on the reasoning
 * that the toolbar was already announcing itself and nothing was listening on
 * behalf of the badges. True, and too narrow: the toolbar was the ONLY writer
 * making the announcement, so the badges kept up with bulk actions and with
 * nothing else — including the ordinary act of opening a mail and reading it.
 * The announcement is now everyone's, and lives in assets/mail_writes.js.
 */
const WRITTEN_EVENT    = WRITE_EVENT;
/**
 * Where the nav was scrolled to.
 *
 * Session storage, because it belongs to this run of the app and restoring
 * yesterday's scroll would be a surprise. Which sections and label trees are
 * collapsed used to live beside it in localStorage and does not any more: that
 * is a preference rather than a position, so it is stored per user on the
 * server and rendered into `open` before the first paint. See rememberCollapse.
 */
const SCROLL_KEY = "sidebar_scroll";

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

/**
 * The post-write request every listener of one write shares.
 *
 * Separate from `inFlight` because the two answer different questions.
 * `inFlight` is "a request is on the wire"; this is "a request has been asked
 * for on behalf of this write and has not gone out yet". Only the second can
 * dedupe callers who all heard the same announcement, and that is what there
 * always are: the sidebar partial is on the page twice, so every write is
 * announced to both instances in one tick.
 *
 * The previous attempt at this deduped on `inFlight` and could not work. The
 * second caller waited for the first one's request, and the thing it was
 * waiting for cleared `inFlight` in its own `finally` on the way out — so the
 * re-check that followed the await was looking for a request that, by the only
 * route that reaches that line, is guaranteed to be gone. Every write made two
 * requests for the same numbers, which is what opening a mail was measured
 * doing.
 *
 * Released the moment the request is DISPATCHED rather than when it settles.
 * That is the whole distinction between coalescing and staleness: everyone who
 * announced a write before it went out is served by it and is correct to be,
 * while a write announced afterwards finds this null and gets a request of its
 * own — which is the entire point of the `fresh` flag.
 */
let freshPending = null;

/** The floor between two counts requests. */
const MIN_COUNTS_MS = 15000;

/**
 * @param {string}  url
 * @param {boolean} fresh  A caller that has just written and cannot be served
 *        an answer the server composed before that write landed.
 */
async function fetchCounts(url, fresh = false) {
    // Already asking: the second caller waits for the first one's answer rather
    // than starting a second request for the same numbers.
    //
    // Except after a write. That in-flight request was sent before the write
    // committed, so its numbers are the old ones — sharing it would patch the
    // badges back to exactly the values the user just changed. Such a caller
    // waits for it to settle (so the two do not race to write the same badges)
    // and then asks again for itself.
    if (true === fresh) {
        // Somebody else already asked for a post-write request on behalf of
        // this same write. One is enough, and it is exactly as fresh as the one
        // this call was about to make.
        if (freshPending !== null) {
            return freshPending;
        }

        freshPending = (async () => {
            // Yields even when there is nothing on the wire, and that is
            // load-bearing rather than incidental: an async function body runs
            // SYNCHRONOUSLY up to its first await, so without one the line
            // below would clear `freshPending` before the assignment this
            // promise is being given to has happened — and the stale promise
            // left behind would then be handed to every later write, which
            // would never ask for anything again. `await undefined` costs a
            // microtask and buys the guarantee. It cost two red tests to
            // learn, both of them a badge that went down and never came back.
            //
            // When there IS a request on the wire, waiting it out also stops
            // the two racing. Its numbers are the pre-write ones — it was sent
            // before this write committed — so letting them land in either
            // order would decide the badges by which response was quicker.
            await inFlight?.catch(() => null);

            // Released here, not in a `finally`: the request below is about to
            // go out, so it answers for every write announced up to this
            // instant and none announced after it. Releasing on settle instead
            // would fold a write that arrived mid-flight into an answer the
            // server composed before it existed.
            freshPending = null;

            return request(url);
        })();

        return freshPending;
    }

    // Already asking, and this caller has not written anything: it waits for
    // that answer rather than starting a second request for the same numbers.
    if (inFlight !== null) {
        return inFlight;
    }

    return request(url);
}

/** The fetch itself, and the bookkeeping that says one is on the wire. */
function request(url) {
    lastFetchAt = Date.now();

    inFlight = fetch(url, { headers: { Accept: "application/json" } })
        .then((response) => (response.ok ? response.json() : null))
        .catch(() => null)
        .finally(() => {
            inFlight = null;
        });

    return inFlight;
}

/**
 * Show or hide the "something arrived here" marks from the same payload.
 *
 * Document-wide rather than over this controller's targets, and that is the
 * point: the marks are not all in the sidebar. The inbox's category tabs carry
 * them too, they live inside the list frame — outside every sidebar element —
 * and they are the primary surface for this marker. Scoping to targets would
 * have left the tabs waiting for the next list refresh to learn that mail had
 * arrived, which is up to MIN_REFRESH_MS later than the sidebar beside them.
 *
 * Two shapes under one attribute. The sidebar's mark is a bare 6px dot and is
 * only toggled — a count written into it would render as a smear. The tabs'
 * mark is a Gmail-style pill that says "3 new", and it announces itself by
 * carrying its templates: translated server-side with the %count% placeholder
 * left in, so this patcher fills numbers into sentences it cannot read. The
 * label template is patched alongside the text for the same reason _nameBadge
 * exists — a count and the name it is read out with are one statement. Keys
 * are namespaced "new:" server-side so neither shape can be fed the unread
 * badges' numbers.
 *
 * Idempotent, which matters because the sidebar partial is on the page twice
 * (drawer and column) and both instances run this over the same elements.
 */
function patchNewDots(counts) {
    document.querySelectorAll("[data-new-dot]").forEach((dot) => {
        const count = counts[dot.dataset.countKey];

        if (count === undefined) {
            return;
        }

        dot.classList.toggle("hidden", count === 0);

        // Nothing is written at zero: the pill is hidden then, and "0 new" is
        // not a sentence it ever says — the next non-zero pass rewrites both
        // strings anyway.
        if (dot.dataset.countTemplate !== undefined && count > 0) {
            dot.textContent = dot.dataset.countTemplate.replace("%count%", String(count));
            dot.setAttribute("aria-label", dot.dataset.labelTemplate.replace("%count%", String(count)));
        }
    });
}

/**
 * The sender hints on the category tabs, from the same payload.
 *
 * The "senders:" family holds the one value in the payload that is a STRING —
 * sender names, joined and localised server-side; it hides when it has
 * nothing to say, so a hintless tab centres its label — the tab's own
 * min-height is what keeps the strip from changing size around it. Outside
 * every sidebar element, hence document-wide, same as patchNewDots.
 */
function patchTabMarks(counts) {
    document.querySelectorAll("[data-tab-senders]").forEach((hint) => {
        const senders = counts[hint.dataset.countKey];

        if (senders === undefined) {
            return;
        }

        hint.textContent = senders;
        hint.classList.toggle("hidden", senders === "");
    });
}

/**
 * The category-tab icon tint, from the same payload.
 *
 * The "category:" family is an unread count, and the only one on this page that
 * is never printed. It decides a colour instead: tinted means there is unread
 * mail behind that tab, untinted means there is not. See the tabIconTones
 * comment in mail/inbox.html.twig for why the tint exists at all and why only
 * the unread-only view draws it.
 *
 * The tone class travels on the element rather than living here, because which
 * of the five a tab wears is the template's business — this only knows whether
 * to put it on. Split on whitespace: the tone is a pair (a light value and its
 * dark: variant) and classList takes them one at a time.
 *
 * Only the unread-only view renders these attributes at all, so on every other
 * list this selector matches nothing and the function costs one query. Same
 * document-wide reach and the same idempotence as the two patchers above.
 */
function patchTabIcons(counts) {
    document.querySelectorAll("[data-tab-icon]").forEach((icon) => {
        const count = counts[icon.dataset.countKey];

        if (count === undefined) {
            return;
        }

        const tone = (icon.dataset.toneClass || "").split(" ").filter(Boolean);

        if (tone.length === 0) {
            return;
        }

        tone.forEach((cls) => icon.classList.toggle(cls, count > 0));
    });
}

export default class extends Controller {
    static targets = ["link", "badge", "scroller", "collapseSummary"];
    static values = { countsUrl: String, collapseUrl: String, i18n: Object };

    connect() {
        // Before the first paint of this element, so nothing is seen in the
        // wrong place: the sidebar re-renders on every visit now, and a scroll
        // jump would be exactly the flicker turbo-permanent avoided.
        //
        // There is no _restoreTrees() beside it any more, and its absence is
        // the feature. Which sections are collapsed arrives already applied, in
        // the HTML, so there is nothing to put back after the fact.
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

        this._onWritten = () => this.refreshCounts({ immediate: true });
        document.addEventListener(WRITTEN_EVENT, this._onWritten);

        this._trailing = null;
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
        document.removeEventListener(WRITTEN_EVENT, this._onWritten);

        // A refresh owed to a sidebar that has gone is owed to nobody.
        clearTimeout(this._trailing);
    }

    /**
     * A section heading or a label tree was opened or closed.
     *
     * One handler for both, because they are one <details> idiom with one key
     * space — `section:labels`, `section:accounts`, `label:<full name>`. COLLAPSED
     * is what is sent, so a label created later shows up expanded rather than
     * needing a backfill of everyone's preferences: the same rule the admin
     * panels follow.
     *
     * The sidebar is on the page twice (drawer and desktop column) and both
     * copies carry the same keys, so toggling one leaves the other showing the
     * opposite until it next re-renders. Mirrored here rather than left to the
     * next navigation — opening the drawer to find the section you just
     * collapsed still expanded reads as the preference not having saved.
     */
    rememberCollapse(event) {
        const details = event.target;
        const key = details.dataset.collapseKey;

        if (!key) {
            return;
        }

        this._announce(details);
        this._mirror(key, details.open);
        this._persistCollapse(key, false === details.open);
    }

    /**
     * Keep the summary's aria-expanded in step with the disclosure.
     *
     * <details>/<summary> already exposes its state natively, so this is belt
     * and braces — but the attribute is rendered server-side with the stored
     * state, and an aria-expanded frozen at its initial value is worse than one
     * that was never there.
     */
    _announce(details) {
        details
            .querySelector(":scope > summary[aria-expanded]")
            ?.setAttribute("aria-expanded", details.open ? "true" : "false");
    }

    /** The other copy of this sidebar, if it is on the page. */
    _mirror(key, open) {
        document
            .querySelectorAll(`details[data-collapse-key="${CSS.escape(key)}"]`)
            .forEach((other) => {
                if (other.open === open) {
                    return;
                }

                // Assigning fires `toggle` on the other one too, which lands
                // back here — the guard above makes that second pass a no-op
                // rather than a loop, since by then the two already agree.
                other.open = open;
            });
    }

    /**
     * Fire and forget, with keepalive, exactly as the account expander does:
     * the click that collapses a section is often the click that navigates, and
     * a preference that fails to save is not worth interrupting anyone over —
     * the section is shut either way, it just will not be next time.
     */
    _persistCollapse(key, collapsed) {
        if (false === this.hasCollapseUrlValue) {
            return;
        }

        fetch(this.collapseUrlValue, {
            method: "POST",
            headers: jsonCsrfHeaders(),
            body: JSON.stringify({ key, collapsed }),
            keepalive: true,
        }).catch(() => {});
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

        // A burst of sync events is one refresh — but the LAST one still lands.
        //
        // This used to return here and drop the event, on the reasoning that "a
        // missed counts update is corrected by the next one, and there is
        // always a next one". That holds for a mailbox syncing on a timer and
        // is false for the case a person actually watches: the demo's Receive
        // button publishes exactly one event, and if it fell inside the window
        // the badge simply never moved. Nothing came afterwards to correct it.
        //
        // Trailing edge instead. The window still collapses a burst of five
        // accounts into one request, and the final state is always fetched.
        //
        // The timer is PER INSTANCE, and that is the other half of the fix.
        // There are two sidebars on the page — the desktop rail and the drawer
        // — so there are two controllers, each holding its own badges. A single
        // shared slot meant the second one to schedule cancelled the first, so
        // only one sidebar was ever brought up to date and the other kept its
        // number indefinitely. The rate limit stays shared, which is what it is
        // for: both wake at the end of the same window and `inFlight`
        // deduplicates them into one round trip.
        if (!immediate && inFlight === null && Date.now() - lastFetchAt < MIN_COUNTS_MS) {
            clearTimeout(this._trailing);
            this._trailing = setTimeout(
                () => this.refreshCounts({ immediate: true }),
                MIN_COUNTS_MS - (Date.now() - lastFetchAt),
            );

            return;
        }

        clearTimeout(this._trailing);

        // Offline, or the sync raced a navigation — fetchCounts answers null and
        // the next sync retries.
        const counts = await fetchCounts(this.countsUrlValue, immediate);

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

            // The number and the name it is read out with are one statement.
            // Patching only the text left a screen reader saying "3 unread"
            // over a badge that had moved on to 7 — and on Trash and Drafts it
            // would have said "unread" over a total, which is the very thing
            // the two badge shapes exist to keep apart.
            this._nameBadge(badge, count);
        });

        patchNewDots(counts);
        patchTabMarks(counts);
        patchTabIcons(counts);

        this._updateTitle(counts);
    }

    /**
     * Re-label one badge for the count it now shows.
     *
     * A badge with no `data-badge-kind`, or a page that shipped no strings, is
     * left exactly as the server rendered it: a wrong name is worse than the
     * one that is already correct.
     */
    _nameBadge(badge, count) {
        const template = this.hasI18nValue ? this.i18nValue[badge.dataset.badgeKind] : null;

        if (!template) {
            return;
        }

        badge.setAttribute("aria-label", template.replace("%count%", String(count)));
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
     * Where the nav was left scrolled to. Applied on connect, before paint.
     */
    _restoreScroll() {
        if (false === this.hasScrollerTarget) {
            return;
        }

        const top = Number(sessionStorage.getItem(SCROLL_KEY) ?? 0);

        if (top > 0) {
            this.scrollerTarget.scrollTop = top;
        }
    }

    /**
     * The trail down to an open row.
     *
     * Every nested label lives inside its parent's <details>, so the ancestry
     * is already in the DOM and needs no ids to walk — which matters for the
     * account lists, where the href is an id and carries no hint of the tree.
     *
     * `summary.nav-item` is what makes this stop at the label trees rather than
     * running on up into the LABELS and ACCOUNTS section headings, which are
     * <details> too now. Those are furniture, not a row you can be "inside", and
     * tinting them would read as a second selection.
     */
    _markAncestors(row) {
        let details = row.closest("details")?.parentElement?.closest("details");

        while (details) {
            details.querySelector(":scope > summary.nav-item")?.classList.add(ANCESTOR_CLASS);
            details = details.parentElement?.closest("details");
        }
    }
}
