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
 * Marks an element as having already played, so it cannot play twice.
 *
 * Turbo re-parents nodes rather than always rebuilding them — a frame swap can
 * move a subtree that has been on screen for minutes — and without this those
 * nodes would re-animate on every navigation, which reads as the page flinching.
 */
const PLAYED = "data-entered";

/** Whether the tier or the operating system has ruled motion out entirely. */
function motionIsOff() {
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
function play(element) {
    if (element.hasAttribute(PLAYED)) {
        return;
    }

    element.setAttribute(PLAYED, "");

    const name = getComputedStyle(element).animationName;

    if ("none" === name || "" === name) {
        return;
    }

    element.style.animation = "none";
    void element.offsetWidth;
    element.style.animation = "";
}

/** Every `[data-enter]` in a freshly inserted subtree, including its root. */
function entrances(node) {
    const found = [];

    if (node.hasAttribute?.(ENTER)) {
        found.push(node);
    }

    if (node.querySelectorAll) {
        found.push(...node.querySelectorAll(`[${ENTER}]`));
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
    document.querySelectorAll(`[${ENTER}]`).forEach((element) => {
        element.setAttribute(PLAYED, "");
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

                entrances(node).forEach(play);
            }
        }
    }).observe(document.documentElement, { childList: true, subtree: true });
}

if ("loading" === document.readyState) {
    document.addEventListener("DOMContentLoaded", watch, { once: true });
} else {
    watch();
}
