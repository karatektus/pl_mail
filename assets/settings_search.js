/**
 * How Settings search matches, shared by the filter above the settings tree
 * and the ⌘K palette (#569), so the two cannot disagree about what "dunkel"
 * finds.
 *
 * Case and accents are ignored: "zeitzone" finds "Zeitzone", "resume" finds
 * "Résumé", "strasse" finds "Straße". People type settings names from memory
 * and on phones, and an exact-spelling search answers neither.
 *
 * A plain substring, not fuzzy matching. The index is a few hundred words,
 * every result is shown with where it lives, and a fuzzy rule that finds
 * "Labels" for "lbl" also finds four other things nobody meant. Keywords in
 * the catalogue (settings.search.keywords.*) are the answer to "I don't know
 * what it is called", not a looser comparison.
 */

/**
 * Lower case, accents dropped, ß as ss.
 *
 * NFD splits "ä" into "a" and a combining mark, and the marks are removed. The
 * string keeps its length otherwise, which is what lets highlight() map a
 * position found in the normalised text back onto the original — except for ß,
 * which becomes two letters, so highlight() works around it.
 */
export function normalise(text) {
    return String(text)
        .normalize("NFD")
        .replace(/[̀-ͯ]/g, "")
        .toLowerCase()
        .replace(/ß/g, "ss");
}

/**
 * The word in `keywords` that matched `query`, or null.
 *
 * Shown under a result as "Keyword: …", so somebody who typed "dark" and got
 * "Theme" can see why rather than wondering whether search is broken.
 */
export function matchingKeyword(keywords, query) {
    if ("" === query) {
        return null;
    }

    return String(keywords).split(/\s+/).find((word) => "" !== word && normalise(word).includes(query)) ?? null;
}

/**
 * Write `text` into `element`, with the part that matches `query` in a <mark>.
 *
 * Built from text nodes rather than an HTML string: the labels are
 * translations, and a translation with a "<" in it would otherwise become
 * markup. When the match cannot be mapped back onto the original text — a ß in
 * front of it changes the length — the text goes in unmarked rather than with
 * the wrong letters highlighted.
 */
export function highlight(element, text, query) {
    element.textContent = "";

    const at = "" === query ? -1 : normalise(text).indexOf(query);

    if (-1 === at || normalise(text).length !== text.length) {
        element.textContent = text;

        return;
    }

    const mark = document.createElement("mark");

    mark.className = "bg-accent-soft text-ink rounded-sm";
    mark.textContent = text.slice(at, at + query.length);
    element.append(text.slice(0, at), mark, text.slice(at + query.length));
}

/** "⌘K" on a Mac, "Ctrl K" elsewhere — what the keyboard in front of the reader says. */
export function shortcutLabel() {
    const platform = navigator.userAgentData?.platform ?? navigator.platform ?? "";

    return /mac|iphone|ipad/i.test(platform) ? "⌘K" : "Ctrl K";
}

/** How long the ring stays on a card that search opened. */
const FLASH_MS = 2200;

/**
 * Scroll to a card and ring it briefly — where a search result lands.
 *
 * Shared because a result can land two ways: on another page, where the tree's
 * controller does it once the page has connected, or on the page already open,
 * where Turbo only moves the fragment and nothing reconnects, so the palette
 * does it itself.
 */
export function flashCard(id) {
    const card = document.getElementById(id);

    if (null === card) {
        return;
    }

    scrollToTop(card);
    card.classList.add("settings-card-flash");
    window.setTimeout(() => card.classList.remove("settings-card-flash"), FLASH_MS);
}

/**
 * Bring `card` to the top of the one box that scrolls it, and nothing else.
 *
 * Not scrollIntoView(): that scrolls every ancestor that has overflow,
 * `overflow: hidden` ones included. The settings page sits in a shell that is
 * clipped, not scrolled, and the moment anything overflows it — as the sr-only
 * labels of Compose defaults did, see settings/index.html.twig — a search
 * result slid the whole page up, top bar and all, with no scrollbar to put it
 * back. The template fixes that overflow; this makes sure the next one cannot
 * move the shell either.
 *
 * Setting scrollTop on the nearest auto/scroll ancestor clamps at the end of
 * the content, which is what a reader expects. A page with no such ancestor
 * (none today) falls back to the document.
 */
function scrollToTop(card) {
    let port = card.parentElement;

    while (null !== port && false === /(auto|scroll)/.test(getComputedStyle(port).overflowY)) {
        port = port.parentElement;
    }

    port ??= document.scrollingElement;

    if (null === port) {
        return;
    }

    const top = port === document.scrollingElement ? 0 : port.getBoundingClientRect().top;

    port.scrollTop += card.getBoundingClientRect().top - top;
}
