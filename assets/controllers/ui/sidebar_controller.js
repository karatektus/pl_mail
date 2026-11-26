import { Controller } from "@hotwired/stimulus";
import { jsonCsrfHeaders } from "../../csrf.js";
import { WRITE_EVENT } from "../../mail_writes.js";

// `is-active` carries no colour of its own — it is the hook the sidebar's
// Gmail-style pill shape hangs off in app.css.
//
// The server renders these same two sets on every row it draws (OPEN and
// CLOSED in App\Twig\SidebarRowStateExtension), so on those rows the pass in
// connect() changes nothing. Keep the lists identical: a row the server drew
// one way and this pass redraws the other is a row that fades on every load,
// from whatever it was painted as. SidebarRowStateTest compares the two files.
const ACTIVE_CLASSES   = ["is-active", "bg-accent-soft", "text-accent", "font-medium"];
const INACTIVE_CLASSES = ["text-ink-muted", "hover:bg-hover"];
const SYNC_EVENTS      = ["core--mercure:mailbox-synced", "core--mercure:account-synced"];
/**
 * The server saying this user's mail changed — see MailChangeNotifier.
 *
 * Its own event and its own, shorter floor (CHANGED_FLOOR_MS). A sync event
 * says a mailbox was looked at, usually finds nothing, and comes one per
 * mailbox per account; this one is only published when something was actually
 * recorded, once per request or handled message, and it is what tells a tab
 * that the mail sent ten seconds ago has now left Drafts. Fifteen seconds of
 * patience is right for the first and is the bug for the second.
 */
const CHANGED_EVENT    = "core--mercure:mail-changed";
/** The key the counts payload carries its change sequence under. */
const SEQUENCE_KEY     = "state:sequence";
/** The frame the mail list lives in — see templates/_layout/_mailbox.html.twig. */
const LIST_FRAME_ID    = "inbox-list-frame";
/**
 * The inert marker a folder row ships instead of naming the list frame itself.
 *
 * THE SIDEBAR IS ON FOUR PAGES AND ONLY ONE OF THEM HAS THE LIST FRAME —
 * _layout/_mailbox.html.twig has it, admin/, calendar/ and settings/index.html
 * .twig include the same partial and do not. A row that named the frame in its
 * own markup therefore named it on all four, and v0.2.34 shipped exactly that.
 * See templates/_partials/_sidebar.html.twig#list_frame for the measurement;
 * the short version is that Turbo's hover PREFETCH sends `Turbo-Frame:
 * inbox-list-frame` without checking that the frame exists, the server answers
 * with the chrome-less fragment, and the click then renders that fragment as
 * the whole document because it finds the prefetched response waiting in a
 * URL-keyed cache. A bare message list on a page with no sidebar.
 *
 * No template can decide this. Two of the three that render these rows are not
 * even told which page they are on: mail/_account_folder_rows.html.twig is
 * fetched into its own frame by its own controller action, and
 * label/_lists.stream.html.twig is a stream that re-renders the label rows into
 * whatever page is open when someone renames a label. The live DOM is the only
 * thing that knows, and this controller is standing in it.
 */
const LIST_FRAME_MARKER = "[data-list-frame]";
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

/** The floor after a `mail.changed` nudge. See CHANGED_EVENT. */
const CHANGED_FLOOR_MS = 2000;

/**
 * How long a nudge waits before it is compared with what has been counted.
 * Longer than the announcement window in mail_writes.js plus a request leaving,
 * so this tab's own post-write request is on the wire by then.
 */
const CHANGED_SETTLE_MS = 400;

/**
 * The change sequence the newest counts answer was read at, or null before
 * the first one.
 *
 * A `mail.changed` nudge carries the sequence of the change it is about. One
 * that is not newer than this is a change the badges already show — nearly
 * always this tab's own, which re-read its counts on the response to the
 * request that made it, a moment before the hub delivered the nudge for the
 * same write. Without the comparison every write would be counted twice.
 */
let countsSequence = null;

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
        .then((counts) => {
            const sequence = counts?.[SEQUENCE_KEY];

            // Never backwards: two answers can land out of order, and the
            // older one must not make a change already counted look new.
            if ("number" === typeof sequence && (null === countsSequence || sequence > countsSequence)) {
                countsSequence = sequence;
            }

            return counts;
        })
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
 * The warning on Drafts while any draft's send has finally failed (#125):
 * shown above zero, hidden at it. A number in the payload rather than a flag,
 * because every value there is one, under its own "unsent:" key so no other
 * marker can be fed it. Document-wide for the reason patchNewDots is.
 */
function patchUnsentMarks(counts) {
    document.querySelectorAll("[data-unsent-mark]").forEach((mark) => {
        const count = counts[mark.dataset.countKey];

        if (count === undefined) {
            return;
        }

        mark.classList.toggle("hidden", count === 0);
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
 * The "category:" family is an unread count. Here it decides a colour: tinted
 * means there is unread mail behind that tab, untinted means there is not —
 * patchTabUnread() below prints the same number. See the tabIconTones comment
 * in mail/inbox.html.twig for why the tint exists at all and why only the
 * unread-only view draws it.
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

/**
 * The unread number on each category tab, from the same payload as the tint.
 *
 * The "category:" family is the number of conversations holding unread mail in
 * that tab — the figure the sidebar's Inbox badge adds up, split by tab. The
 * element is rendered whatever the number is and hidden at zero, so this only
 * writes text, the accessible name and the visibility.
 *
 * Not patchNewDots(), and not given data-new-dot: that family is the "3 new"
 * pill, whose template would write "N new" over an unread count. The label
 * template travels on the element for the same reason it does there — which
 * sentence a number belongs to is the template's business, rendered in the
 * viewer's language.
 *
 * Document-wide for the same reason as the patchers above, and idempotent: a
 * pass with nothing changed rewrites the same text.
 */
function patchTabUnread(counts) {
    document.querySelectorAll("[data-tab-unread]").forEach((badge) => {
        const count = counts[badge.dataset.countKey];

        if (count === undefined) {
            return;
        }

        badge.textContent = String(count);
        badge.classList.toggle("hidden", count === 0);

        if (badge.dataset.labelTemplate !== undefined) {
            badge.setAttribute("aria-label", badge.dataset.labelTemplate.replace("%count%", String(count)));
        }
    });
}

export default class extends Controller {
    static targets = ["link", "badge", "scroller", "collapseSummary"];
    static values = {
        countsUrl: String,
        collapseUrl: String,
        sequence: Number,
        i18n: Object,
        // How far the peek may lean out: the drag's own maximum, so the sidebar
        // has one widest width however it got there. User::SIDEBAR_MAX_WIDTH.
        peekMax: { type: Number, default: 480 },
    };

    connect() {
        // Before the first paint of this element, so nothing is seen in the
        // wrong place: the sidebar re-renders on every visit now, and a scroll
        // jump would be exactly the flicker turbo-permanent avoided.
        //
        // There is no _restoreTrees() beside it any more, and its absence is
        // the feature. Which sections are collapsed arrives already applied, in
        // the HTML, so there is nothing to put back after the fact.
        this._restoreScroll();

        // Before anything a mouse could reach, because a HOVER is what starts
        // the prefetch this guards.
        this._bindListFrame();

        this._updateActive();

        // `turbo:load` fires on the first page load as well as on every Drive
        // visit, and _bindListFrame is here for the first of those: this
        // sidebar is earlier in the document than the list frame is, so
        // Stimulus can connect it while the frame is still being parsed. The
        // pass above would then find no frame, leave the markers inert, and
        // never be asked again — the rows would fetch whole pages for the rest
        // of the session, quietly, with nothing on screen to see. That is the
        // exact failure mode the list_frame macro was written to avoid.
        this._onTurboLoad = () => {
            this._bindListFrame();
            this._updateActive();
        };
        document.addEventListener("turbo:load", this._onTurboLoad);

        // A folder click is a FRAME navigation — see the list_frame macro in
        // _sidebar.html.twig — and a frame navigation fires no turbo:load,
        // because no page was loaded. The sidebar is not re-rendered by one
        // either, so without this the highlighted row would simply stay on the
        // folder you left: the click worked, the list changed, and the nav went
        // on pointing at the wrong place until the next full visit.
        //
        // turbo:frame-load bubbles to the document, and Turbo has already
        // written the new URL into history by the time it fires (it calls
        // changeHistory() before rendering the frame), so _matches() below is
        // reading the location it is about to be asked about.
        this._onFrameLoad = (event) => {
            if (LIST_FRAME_ID !== event.target.id) {
                return;
            }

            this._updateActive();
        };
        document.addEventListener("turbo:frame-load", this._onFrameLoad);

        // Counts go stale WITHOUT a navigation, which is the case this covers:
        // mail arrives, or a conversation is read in the pane beside the nav,
        // and the badge beside Inbox is wrong until something fetches it. A
        // sync event is that something.
        //
        // The comment here used to say the desktop sidebar is
        // data-turbo-permanent and that this existed to unfreeze it. It is not
        // — there is no such attribute on it, and `_sidebar.html.twig` says at
        // length why not: carried across visits, the nav kept deleted labels
        // linking to 404s and never showed a rename. It re-renders per visit,
        // so a navigation already brings fresh numbers and this is only ever
        // about the time between navigations.
        this._onSynced = () => this.refreshCounts();
        SYNC_EVENTS.forEach((name) =>
            document.addEventListener(name, this._onSynced),
        );

        // The badges this element was rendered with were counted at this
        // sequence. Without it a freshly loaded page knows nothing, and the
        // first nudge to reach it — the hub replays the last one to a tab that
        // reconnects — would re-read numbers the server had just sent.
        if (this.hasSequenceValue && (null === countsSequence || this.sequenceValue > countsSequence)) {
            countsSequence = this.sequenceValue;
        }

        this._onWritten = () => this.refreshCounts({ immediate: true });
        document.addEventListener(WRITTEN_EVENT, this._onWritten);

        this._onChanged = async (event) => {
            const sequence = event.detail?.sequence;

            // Not yet. For this tab's own write the hub is quicker than the
            // tab: the nudge was measured arriving before the counts request
            // the write itself sets off had even left, so comparing sequences
            // straight away always found the nudge "newer" and every write was
            // counted twice. Give that request time to go out, then wait for
            // whatever is on the wire, and only then ask whether the change is
            // one the badges already show.
            await new Promise((resolve) => setTimeout(resolve, CHANGED_SETTLE_MS));
            await inFlight?.catch(() => null);

            if (false === this.element.isConnected) {
                return;
            }

            // Already counted — see countsSequence. A nudge with no sequence
            // is from a server that sends none, and is taken at its word.
            if ("number" === typeof sequence && null !== countsSequence && sequence <= countsSequence) {
                return;
            }

            this.refreshCounts({ floor: CHANGED_FLOOR_MS });
        };
        document.addEventListener(CHANGED_EVENT, this._onChanged);

        this._trailing = null;

        this._watchTruncation();
    }

    /*
     * ── The peek (#699) ───────────────────────────────────────────────────
     *
     * A label too long for the sidebar ends in an ellipsis. The boundary
     * answers "my sidebar is too narrow"; this answers "it is too narrow for
     * THIS row" — a sidebar wide enough for the longest nested label wastes
     * that width on every other row. So, when the user has switched it on, the
     * sidebar leans out over the mail on hover, far enough for the longest row
     * and no further. Over, not beside: the list never reflows.
     *
     * The CSS needs two attributes on this element (see "The peek" in
     * app.css): `data-sidebar-peek`, the setting, rendered by the server, and
     * `data-sidebar-truncated`, set here only while something IS clipped.
     * `data-sidebar-truncated-own` is the same for the rows that peek only
     * when hovered themselves (`data-sidebar-peek-own`, see _applyPeek).
     */

    /**
     * Watch the two things that change whether a row fits: the sidebar's
     * width (one ResizeObserver covers the drag, the window and the rail) and
     * what is in it (the content hooks below call _measureTruncation).
     *
     * Nothing is observed unless the setting is on — the measurement is a
     * reflow — and the drawer copy never carries the setting.
     */
    _watchTruncation() {
        if (undefined === this.element.dataset.sidebarPeek) {
            return;
        }

        // Before the first measure: the coalescing handle is tested against
        // null, and the observer is what _measureTruncation takes as
        // permission to run at all.
        this._truncationFrame = null;

        // The aside rather than the box carrying the width: while the peek is
        // OPEN the aside is wider than that box, and an observer on the box
        // would never see it close again.
        this._truncationObserver = new ResizeObserver(() => this._measureTruncation());
        this._truncationObserver.observe(this.element);

        // Any disclosure opening — a label tree, an account, More — is how
        // long names reach the screen, and closing one may take the longest
        // away. `toggle` does not bubble, so it is caught on the way down.
        this._onDisclosure = () => this._measureTruncation();
        this.element.addEventListener("toggle", this._onDisclosure, true);

        // Rows going away: a label deleted or renamed shorter, the next event
        // changing, a stream replacing a list. Arrivals are covered here too,
        // on top of linkTargetConnected. childList only — the measurement
        // writes attributes and a style on this element, and observing those
        // would have it trigger itself.
        this._truncationMutations = new MutationObserver(() => this._measureTruncation());
        this._truncationMutations.observe(this.element, { childList: true, subtree: true });

        // While the width is animating, a measurement would have to stop the
        // animation to take it (see _measureTruncation). Skipped then, and
        // taken once it has finished.
        this._peekMoving = false;
        this._onPeekMove = (event) => {
            if (event.target !== this.element || "width" !== event.propertyName) {
                return;
            }

            this._peekMoving = "transitionrun" === event.type;

            if (false === this._peekMoving) {
                this._measureTruncation();
            }
        };
        ["transitionrun", "transitionend", "transitioncancel"].forEach((name) =>
            this.element.addEventListener(name, this._onPeekMove),
        );

        this._measureTruncation();
    }

    /**
     * How much wider the sidebar would have to be for every row to fit.
     *
     * Off the clipped elements themselves: `scrollWidth - clientWidth` is
     * exactly the pixels a row is missing. Nested labels carry their depth as
     * padding on the row around the clipped span, so the span's box already
     * has the indent taken out and a deep label asks for more than a shallow
     * one of the same name — no separate sum of indents to keep in step with
     * the CSS.
     *
     * Only rows on screen count. Rows inside a closed <details> — a collapsed
     * section, label tree or account, the rows behind More — are NOT measured
     * as 0: browsers now hide closed disclosure content with
     * `content-visibility: hidden`, and reading scrollWidth lays the skipped
     * subtree out on demand, at full width. A collapsed ACCOUNTS section with
     * one long folder name in it kept the sidebar leaning out with nothing
     * clipped in sight. checkVisibility() answers false for anything under a
     * closed disclosure, display:none or [hidden]; getClientRects() is the
     * fallback for browsers without it, and is right for everything but the
     * content-visibility case those browsers do not have.
     *
     * Coalesced into one frame: a tree of forty labels opening is forty hook
     * calls for one answer.
     */
    _measureTruncation() {
        if (undefined === this._truncationObserver || null !== this._truncationFrame) {
            return;
        }

        this._truncationFrame = window.requestAnimationFrame(() => {
            this._truncationFrame = null;

            if (false === this.element.isConnected) {
                return;
            }

            const box = this.element.parentElement?.clientWidth ?? 0;
            const open = this.element.offsetWidth > box + 1;

            // Mid-animation: the transitionend handler measures again.
            if (true === open && true === this._peekMoving) {
                return;
            }

            // While the peek is open the rows it was opened for fit, so a
            // measurement of the open sidebar is a measurement of the answer,
            // not of the question: it cannot tell a row that fits from one
            // that only fits because the peek is out. So the question is
            // always asked of the CLOSED sidebar. While open, the aside is
            // pinned to the box's width for the reads and let go again, all
            // in this one task — nothing is painted in between, so nothing is
            // seen. The transition is held off throughout, or pinning it would
            // start an animation towards the box and letting go another back.
            // That is what lets the peek follow what is in it while the
            // pointer stays: grow when an account opens a longer name, and
            // shrink, or let go entirely, when a section closes or the longest
            // name goes away.
            const style = this.element.style;

            if (true === open) {
                style.transition = "none";
                style.width = `${box}px`;
            }

            let missing = 0;
            let missingOwn = 0;

            this.element.querySelectorAll(".truncate").forEach((clipped) => {
                const shown = "function" === typeof clipped.checkVisibility
                    ? clipped.checkVisibility()
                    : clipped.getClientRects().length > 0;

                if (false === shown) {
                    return;
                }

                const short = clipped.scrollWidth - clipped.clientWidth;

                if (null === clipped.closest("[data-sidebar-peek-own]")) {
                    missing = Math.max(missing, short);
                } else {
                    missingOwn = Math.max(missingOwn, short);
                }
            });

            const closedWidth = this.element.clientWidth;

            if (true === open) {
                style.removeProperty("width");
                // Flushed while the transition is still off, so the way back
                // to the peek's width is not animated either.
                void this.element.offsetWidth;
                style.removeProperty("transition");
            }

            this._applyPeek("sidebarTruncated", "--sidebar-peek-width", missing, closedWidth);
            this._applyPeek("sidebarTruncatedOwn", "--sidebar-peek-own-width", missingOwn, closedWidth);
        });
    }

    /**
     * One kind of peek, from how many pixels its longest row is missing.
     *
     * Two kinds: the nav rows, which lean the sidebar out whenever the pointer
     * is anywhere over it, and the rows marked `data-sidebar-peek-own` (the
     * next event under the clock), which do so only while that row itself is
     * hovered or focused. A clipped event title is worth reading on purpose,
     * not worth a sidebar lurching out every time the pointer crosses it on
     * the way to Inbox. See "The peek" in app.css for the two rules.
     */
    _applyPeek(flag, property, missing, closedWidth) {
        // The slack is the ellipsis's own width, which scrollWidth does not
        // count; without it the longest row can open to exactly its text and
        // still end in "…".
        const wanted = Math.min(this.peekMaxValue, closedWidth + missing + 6);

        // Sub-pixel text rounds to a hair over on rows that do fit, and a
        // sidebar already as wide as it may get would lean out to show nothing.
        if (1 > missing || wanted <= closedWidth) {
            delete this.element.dataset[flag];
            this.element.style.removeProperty(property);

            return;
        }

        this.element.style.setProperty(property, `${Math.round(wanted)}px`);
        this.element.dataset[flag] = "";
    }

    /**
     * An account's folder list arrives later, in its own frame, so the rows in
     * it miss the pass that ran on connect — they would sit unhighlighted
     * until the next navigation, which is exactly the click that just expanded
     * them.
     */
    linkTargetConnected() {
        // Rows that arrive after connect need the same pass, and they are
        // precisely the ones no template could have got right: an account's
        // folders fetched into their own frame, and the label rows a
        // label/_lists.stream.html.twig replaces wholesale after a rename.
        this._bindListFrame();

        this._updateActive();

        // Rows that have only just arrived are rows nothing has measured, and
        // an account's folders are exactly the deep names the peek is for.
        this._measureTruncation();
    }

    /**
     * Let a folder row open the list in place — but only on a page that has a
     * list to open it in.
     *
     * The rows ship `data-list-frame`, which means nothing to Turbo, and this
     * turns it into the `data-turbo-frame` that does. The guard is the whole
     * point and it is deliberately a lookup in the live document rather than a
     * flag passed down from the layout: a flag is something the next person
     * adding a row to this sidebar has to know to thread through, and the
     * regression this replaces happened because that knowledge lived in one
     * person's head while the markup was shared by four pages.
     *
     * Off a mail page nothing is promoted, the row stays an ordinary link, and
     * the click gets a whole page — which is what it should have got all along.
     *
     * Scoped to `this.element`, so the desktop sidebar and the mobile drawer
     * each do their own and neither reaches into the other's rows.
     *
     * Cheap enough to be unconditional: a dozen rows, an attribute each, and
     * only on connect or when new rows land. Setting an attribute to the value
     * it already holds is a no-op in the DOM, so the repeat passes cost nothing.
     *
     * NOTE the one row that starts out naming a frame rather than naming
     * nothing: mail/_account_folder_rows.html.twig ships `_top`, because those
     * rows sit INSIDE the account's own frame and a link naming nothing there
     * targets that frame instead of the page. Overwriting `_top` is correct and
     * intended — see the comment on that template.
     */
    _bindListFrame() {
        if (null === document.getElementById(LIST_FRAME_ID)) {
            return;
        }

        this.element
            .querySelectorAll(LIST_FRAME_MARKER)
            .forEach((link) => link.setAttribute("data-turbo-frame", LIST_FRAME_ID));
    }

    disconnect() {
        document.removeEventListener("turbo:load", this._onTurboLoad);
        document.removeEventListener("turbo:frame-load", this._onFrameLoad);
        SYNC_EVENTS.forEach((name) =>
            document.removeEventListener(name, this._onSynced),
        );
        document.removeEventListener(WRITTEN_EVENT, this._onWritten);
        document.removeEventListener(CHANGED_EVENT, this._onChanged);

        // A refresh owed to a sidebar that has gone is owed to nobody.
        clearTimeout(this._trailing);

        this._truncationObserver?.disconnect();
        this._truncationObserver = undefined;
        this._truncationMutations?.disconnect();

        if (this._onPeekMove) {
            ["transitionrun", "transitionend", "transitioncancel"].forEach((name) =>
                this.element.removeEventListener(name, this._onPeekMove),
            );
        }

        if (this._onDisclosure) {
            this.element.removeEventListener("toggle", this._onDisclosure, true);
        }

        if (null != this._truncationFrame) {
            window.cancelAnimationFrame(this._truncationFrame);
            this._truncationFrame = null;
        }
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

    async refreshCounts({ immediate = false, floor = MIN_COUNTS_MS } = {}) {
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
        if (!immediate && inFlight === null && Date.now() - lastFetchAt < floor) {
            clearTimeout(this._trailing);
            this._trailing = setTimeout(
                () => this.refreshCounts({ immediate: true }),
                floor - (Date.now() - lastFetchAt),
            );

            return;
        }

        clearTimeout(this._trailing);

        // Offline, or the sync raced a navigation — fetchCounts answers null and
        // the next sync retries.
        const counts = await fetchCounts(this._countsUrl(), immediate);

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
        patchUnsentMarks(counts);
        patchTabIcons(counts);
        patchTabUnread(counts);

        this._updateTitle(counts);

        // A badge going from 9 to 10 takes a character off the name beside it.
        this._measureTruncation();
    }

    /**
     * The counts endpoint, naming the unified inbox whose list is open (#265).
     *
     * The tab strip's marks come back in the same payload as the badges, and
     * each inbox has a strip of its own: without the inbox the server would
     * count every account, and "Work"'s Promotions tab would light up for mail
     * that arrived in "Personal". Read off the live frame at the moment of the
     * request, like list_view.js, because the frame is swapped by navigation.
     */
    _countsUrl() {
        const inbox = document.getElementById(LIST_FRAME_ID)?.dataset.unifiedInbox;

        if (!inbox) {
            return this.countsUrlValue;
        }

        const url = new URL(this.countsUrlValue, window.location.origin);
        url.searchParams.set("inbox", inbox);

        return url.pathname + url.search;
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
        this.linkTargets.forEach((link) => {
            // A system row is its own .nav-item; a custom label wraps the
            // anchor in one so the disclosure arrow and the edit button stay
            // outside the link. Tint the row either way, or a label would get
            // the colour on its text only while the pill stayed empty.
            const row = link.closest(".nav-item") ?? link;

            row.classList.remove(...ACTIVE_CLASSES);
            row.classList.add(...INACTIVE_CLASSES);

            if (false === this._matches(link)) {
                return;
            }

            row.classList.add(...ACTIVE_CLASSES);
            row.classList.remove(...INACTIVE_CLASSES);
        });
    }

    /**
     * Whether a nav link points at what is currently open.
     *
     * Compared as a URL rather than as the raw attribute: an account's label
     * rows carry `?account=` and the location's pathname does not, so a string
     * compare matched nothing and those rows never highlighted at all. The
     * account has to agree too — the same label under another account is a
     * different view, and both rows lighting up says the wrong thing.
     *
     * Restated in PHP as SidebarRowStateExtension::isOpen(), which decides the
     * rows the server renders. A change here that is not made there is a row
     * the two disagree about, and it fades on every load.
     */
    _matches(link) {
        const target = new URL(link.href, window.location.origin);
        const path = window.location.pathname;

        // Exact, not a prefix. A prefix made a parent label open while a child
        // of it was ("Work" is a prefix of "Work/Clients"), and an account's
        // row open on its own All Mail — two rows lit for one place, which the
        // maintainer asked to stop (#699): only the row you are on is marked.
        // The first unified inbox is also what the bare /mail/inbox shows
        // (#265), and its row says so: that address opens it too.
        const alsoAt = link.dataset.alsoOpenAt;

        if (alsoAt && path === alsoAt) {
            return true;
        }

        if (path !== target.pathname) {
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
}
