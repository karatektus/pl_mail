/*
 * motion.js — the only JavaScript in plMail's animation layer.
 *
 * Almost all of the motion in this app is CSS, in assets/styles/motion.css: an
 * element carries `data-enter="rise"`, the stylesheet gives it an entrance, and
 * the tier on <html> decides how much of one. That covers everything present
 * when a page paints, and it costs nothing.
 *
 * What CSS cannot do on its own is notice an element that ARRIVES. A row pushed
 * in by a Turbo Stream, a dropdown built by a Stimulus controller and a
 * suggestion list replaced on a keystroke are all inserted into a document that
 * has long since painted, and a CSS animation only plays when the element
 * enters the document with the rule already attached — which, for markup Turbo
 * writes, it does not.
 *
 * So: one MutationObserver, at the top of the document, that finds newly
 * inserted `[data-enter]` elements and starts their animation. That is the
 * whole file. Nothing else in the codebase imports it, no Stimulus controller
 * knows it exists, and deleting it degrades the app to "things appear without
 * animating" rather than breaking anything.
 *
 * ── Why not a Stimulus controller ───────────────────────────────────────────
 *
 * Because then every animated surface would need `data-controller` on it, and
 * a surface that forgot would silently not animate. An observer is opt-in by
 * attribute, which is the smaller thing to remember and the one that fails
 * visibly.
 */

/** Attribute a surface uses to ask for an entrance. Kept in step with motion.css. */
const ENTER = "data-enter";

/**
 * "Animate this, but only the first time you see it."
 *
 * A second attribute rather than a flag on the first one, because the
 * difference is not a preference — it is WHEN the browser is allowed to know
 * about the animation.
 *
 * A CSS animation starts by itself the moment an element carrying one is
 * inserted into the document. That is what makes the plain `data-enter` case
 * free: nothing has to notice the element, it simply plays. It also means an
 * observer cannot CANCEL one — by the time a MutationObserver callback runs,
 * the animation is already a frame in. Discovering that is what this attribute
 * is for: suppression cannot be a decision taken after insertion, so an element
 * whose entrance is conditional must arrive with no animation attached at all,
 * and be given one only if it turns out to deserve it.
 *
 * The stylesheet therefore knows nothing about this attribute. It names an
 * entrance for JavaScript to apply, and applying it — writing `data-enter` —
 * is what starts the animation.
 */
const ENTER_IF_NEW = "data-enter-new";

/**
 * Marks an element as having already played, so it cannot play twice.
 *
 * Turbo re-parents nodes rather than always rebuilding them — a frame swap can
 * move a subtree that has been on screen for minutes — and without this those
 * nodes would re-animate on every navigation, which reads as the page flinching.
 */
const PLAYED = "data-entered";

/**
 * Ids this page has already shown, so a re-render is not mistaken for news.
 *
 * Plenty of things rebuild a row that is not new. A star, an archive or a
 * snooze sends a turbo-stream that re-renders that row; a bulk action refreshes
 * the list; a template without the region markers falls back to replacing the
 * whole frame. Each of those hands the page a brand new node carrying mail that
 * has been on screen for an hour, and an entrance keyed on the NODE would fire
 * every time. A list that shimmers at rest is unreadable, and it is the single
 * fastest way to make somebody switch this feature off.
 *
 * The list's own refresh is no longer one of those cases — mail--mail-pane
 * MORPHS the rows region, so a row that survives a sync keeps the node it had
 * and never reaches this code. That is the better fix, and it is why the ones
 * above are now the whole job rather than the tail of it.
 *
 * What distinguishes the two cases is not the DOM node, which is new either
 * way, but the id on it: `thread_1234` is the same mail whoever rendered it. So
 * an element that carries an id animates the FIRST time that id is seen and
 * never again — which is exactly "new mail arriving" and exactly not "the list
 * redrew itself".
 *
 * Not cleared on navigation, deliberately. Going to another folder replaces the
 * list with rows this set has never seen, and animating fifty of them is the
 * slab of movement the list-level fade exists to avoid — the ancestor rule in
 * play() is what suppresses them, and it needs the set to survive.
 *
 * Bounded rather than unbounded: a long session paging through a large mailbox
 * would otherwise accumulate ids for ever. Twenty thousand is far more than any
 * list depth and still nothing next to the page it is on.
 */
const shown = new Set();
const SHOWN_CAP = 20_000;

function remember(element) {
    if ("" === element.id) {
        return true;
    }

    if (shown.has(element.id)) {
        return false;
    }

    if (shown.size >= SHOWN_CAP) {
        shown.clear();
    }

    shown.add(element.id);

    return true;
}

/**
 * Whether the tier or the operating system has ruled motion out entirely.
 *
 * Exported because a caller sometimes needs to know BEFORE calling leave(): at
 * zero duration leave() finishes synchronously, and a caller in the middle of
 * walking the DOM — mail--mail-pane morphing the list — cannot have a node
 * vanish underneath it mid-traversal.
 */
export function motionIsOff() {
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
        return true;
    }

    return "none" === document.documentElement.dataset.motion;
}

/**
 * Play the entrance for one element.
 *
 * Restarting the animation by hand rather than trusting insertion: the element
 * may have been in the DOM for a frame before we saw it, and a CSS animation
 * that has already run its course does not run again just because someone is
 * now looking at it. Clearing the name, forcing a reflow and putting it back is
 * the documented way to rewind one.
 */
function play(element, batch) {
    if (element.hasAttribute(PLAYED)) {
        return;
    }

    element.setAttribute(PLAYED, "");

    // Recorded BEFORE anything below can return, and the ordering is the whole
    // correctness of this function. Seeing a thing is what makes it not-new,
    // whether or not it ends up animating — an element suppressed by the rule
    // below has still been on screen, and leaving it unrecorded means the next
    // wholesale re-render finds an unseen id and treats a redraw as an arrival.
    // Which is exactly what happened: rows arriving inside their list's own
    // fade were skipped here and then animated, all of them, on the following
    // sync.
    const fresh = remember(element);

    // Covered by something above it. When a whole list arrives, the <ul> plays
    // one fade and its rows must not each play their own on top of it — one
    // gesture for one arrival, whatever it contains. This is also what stops a
    // folder change from staggering fifty rows: they are new ids, but they came
    // inside an ancestor that is announcing them.
    if (batch?.some((other) => other !== element && other.contains(element))) {
        return;
    }

    // Seen this exact thing before — a re-render, not an arrival.
    if (false === fresh) {
        return;
    }

    // The conditional kind arrives inert and is given its entrance here, which
    // is the act that starts it. Nothing further is needed — and nothing
    // further would work, since the restart below only rewinds an animation the
    // element already has.
    if (element.hasAttribute(ENTER_IF_NEW)) {
        element.setAttribute(ENTER, element.getAttribute(ENTER_IF_NEW));

        return;
    }

    const name = getComputedStyle(element).animationName;

    if ("none" === name || "" === name) {
        return;
    }

    element.style.animation = "none";
    void element.offsetWidth;
    element.style.animation = "";
}

/** Every element in a freshly inserted subtree that wants an entrance. */
function entrances(node) {
    const selector = `[${ENTER}], [${ENTER_IF_NEW}]`;
    const found = [];

    if (node.matches?.(selector)) {
        found.push(node);
    }

    if (node.querySelectorAll) {
        found.push(...node.querySelectorAll(selector));
    }

    return found;
}

/**
 * Take an element off the page with its exit animation, then remove it.
 *
 * Exported for the two surfaces that need it — a toast dismissing itself, a
 * modal closing — because an element cannot animate after it has been removed,
 * so the removal has to wait for the animation rather than the other way round.
 *
 * The timeout is a safety net, not the mechanism: `animationend` does not fire
 * when the duration is zero (the `none` tier, or reduced motion), and a node
 * that never leaves because nobody told it to is worse than one that leaves
 * abruptly.
 */
export function leave(element, done) {
    const finish = () => {
        element.removeEventListener("animationend", finish);
        (done ?? (() => element.remove()))();
    };

    if (motionIsOff()) {
        finish();

        return;
    }

    element.setAttribute("data-leaving", "");
    element.addEventListener("animationend", finish, { once: true });

    // Longer than --motion-fast can ever be, so it only fires when the event
    // did not.
    setTimeout(finish, 400);
}

/**
 * Milliseconds from a CSS duration, for the two places JS has to out-wait one.
 *
 * Both `ms` and `s` are legal in a custom property and the browser hands back
 * whichever was written, so neither can be assumed.
 */
function milliseconds(value) {
    const number = Number.parseFloat(value);

    if (Number.isNaN(number)) {
        return 0;
    }

    return value.trim().endsWith("ms") ? number : number * 1000;
}

/**
 * Make room in a list that was never inserted into.
 *
 * The mail list is not built up row by row. mail--mail-pane fetches the whole
 * list and morphs it, so by the time anything can be animated the new mail is
 * already in place and every row below it is already one row lower. There is no
 * moment at which a gap opens, and no gap to animate.
 *
 * So the gap is played backwards. Measure where every row is BEFORE the morph;
 * afterwards, put the survivors back where they were with a transform and then
 * release them. What the eye sees is rows travelling down to make space — which
 * is a true account of what happened, arrived at from the wrong end.
 *
 * Used like this, because the measuring has to bracket the morph:
 *
 *     const room = makeRoom(list);
 *     ...morph, calling room.holding(row) for each row being dropped...
 *     room.play();
 *
 * Transform only. Nothing here touches layout, so fifty rows travelling costs
 * no reflows and every one of them stays clickable the whole way — a row is
 * hit-tested where it is drawn, not where it started.
 */
export function makeRoom(container) {
    if (motionIsOff()) {
        return { holding: () => {}, play: () => {} };
    }

    // Anything still travelling from a previous refresh is finished now, so
    // that the positions below are read from one settled layout rather than
    // from a list in two states at once.
    settle(container);

    const was = new Map();

    for (const row of container.children) {
        if ("" !== row.id) {
            was.set(row.id, row.offsetTop);
        }
    }

    return {
        /**
         * A row that is leaving, caught before the morph takes it out.
         *
         * Pinned where it was standing and taken out of flow, so the list can
         * close over it while it fades. Its id goes with it — see
         * mail--mail-pane#_rowLeaving for why a departing row must stop being
         * addressable.
         */
        holding(row) {
            row.style.top = `${was.get(row.id) ?? row.offsetTop}px`;
        },

        play() {
            const styles = getComputedStyle(container);
            const duration = styles.getPropertyValue("--motion-room").trim();
            const ease = styles.getPropertyValue("--motion-row-ease").trim();
            const moved = [];

            for (const row of container.children) {
                const before = was.get(row.id);

                if (undefined === before) {
                    continue;
                }

                const travelled = before - row.offsetTop;

                if (0 !== travelled) {
                    row.style.transform = `translateY(${travelled}px)`;
                    moved.push(row);
                }
            }

            if (0 === moved.length) {
                return;
            }

            // Two frames, not one. A single frame lets the browser coalesce the
            // transform above with the one below and transition nothing at all,
            // which is the oldest bug in this technique.
            requestAnimationFrame(() => requestAnimationFrame(() => {
                moved.forEach((row) => {
                    row.style.transition = `transform ${duration} ${ease}`;
                    row.style.transform = "";
                });
            }));

            setTimeout(() => settle(container), milliseconds(duration) + 120);
        },
    };
}

/** Take the inline remains of a finished journey back off. */
function settle(container) {
    for (const row of container.children) {
        if ("" !== row.style.transform || "" !== row.style.transition) {
            row.style.transform = "";
            row.style.transition = "";
        }
    }
}

/**
 * Watch for arrivals.
 *
 * `subtree: true` from documentElement, which sounds expensive and is not: the
 * callback runs only on mutations, does no layout work of its own, and the
 * attribute lookup is a cheap `querySelectorAll` over the inserted subtree —
 * not over the document. The alternative, a list of container selectors to
 * observe, is a list that goes stale the first time somebody adds a surface.
 */
function watch() {
    // Elements present at first paint animate from CSS alone; marking them
    // stops the observer replaying them if Turbo later moves them about.
    document.querySelectorAll(`[${ENTER}], [${ENTER_IF_NEW}]`).forEach((element) => {
        element.setAttribute(PLAYED, "");
        remember(element);
    });

    new MutationObserver((records) => {
        if (motionIsOff()) {
            return;
        }

        for (const record of records) {
            for (const node of record.addedNodes) {
                if (Node.ELEMENT_NODE !== node.nodeType) {
                    continue;
                }

                const batch = entrances(node);

                batch.forEach((element) => play(element, batch));
            }
        }
    }).observe(document.documentElement, { childList: true, subtree: true });
}

if ("loading" === document.readyState) {
    document.addEventListener("DOMContentLoaded", watch, { once: true });
} else {
    watch();
}
