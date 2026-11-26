/* stimulusFetch: 'lazy' */
import { Controller } from "@hotwired/stimulus";
import { flashCard, highlight, matchingKeyword, normalise, shortcutLabel } from "../../settings_search.js";

/**
 * The settings tree's filter, its show/hide on a phone, and the highlight on
 * the card a search result opened (#569).
 *
 * The tree itself is server-rendered and works without this: areas are
 * <details>, pages are links. See templates/settings/_nav.html.twig.
 *
 * ## The filter
 *
 * Every card is already in the tree, hidden under its page. Typing shows the
 * pages whose name matches and, under each page, the cards whose heading or
 * keywords match; areas with nothing left are hidden and the rest are opened.
 * Clearing the field puts every area back the way it was before the first
 * letter — open or folded, as the reader left it.
 *
 * Matching is assets/settings_search.js, the same rule the ⌘K palette uses.
 *
 * ## The highlight
 *
 * A result opens /settings/<page>#card-…. The browser scrolls to the card on a
 * full load, but Turbo navigates with pushState, which neither scrolls to an
 * anchor reliably inside this page's own scroll port nor updates `:target`, so
 * a CSS-only highlight would work on reload and never on the click that
 * matters. The card is scrolled to and ringed here instead, for long enough to
 * find it and then let go.
 *
 * Values:
 *   keywordLabel  — "Keyword", the caption for a card found by a keyword
 *
 * Targets:
 *   toggle    — the phone's "Area › Page" button
 *   panel     — the tree and filter, hidden behind toggle below md
 *   input     — the filter field
 *   shortcut  — the ⌘K / Ctrl K hint inside it
 *   banner    — the account-health banner, hidden while filtering
 *   area      — one <li> per area
 *   page      — one <li> per page, `data-terms` its name
 *   hit       — one hidden <li> per card, `data-terms` its heading and
 *               `data-keywords` its search words
 *   label     — the text to highlight, inside a page's link or a hit
 *   via       — under a hit, where the matching keyword is named
 *   empty     — "nothing found"
 */
export default class extends Controller {
    static values = {
        keywordLabel: { type: String, default: "Keyword" },
    };

    static targets = ["toggle", "panel", "input", "shortcut", "banner", "area", "page", "hit", "empty"];

    connect() {
        if (this.hasShortcutTarget) {
            this.shortcutTarget.textContent = shortcutLabel();
        }

        this.#flashAnchoredCard();
    }

    toggle() {
        const hidden = this.panelTarget.classList.toggle("hidden");

        this.toggleTarget.setAttribute("aria-expanded", hidden ? "false" : "true");

        if (false === hidden) {
            this.inputTarget.focus();
        }
    }

    filter() {
        const query = normalise(this.inputTarget.value.trim());

        if ("" === query) {
            this.#reset();

            return;
        }

        this.#rememberFolding();

        let anything = false;

        for (const area of this.areaTargets) {
            let areaShows = false;

            for (const page of area.querySelectorAll("[data-settings--nav-target='page']")) {
                const pageHit = normalise(page.dataset.terms).includes(query);
                let cardHit = false;

                for (const hit of page.querySelectorAll("[data-settings--nav-target='hit']")) {
                    const byHeading = normalise(hit.dataset.terms).includes(query);
                    const keyword = byHeading ? null : matchingKeyword(hit.dataset.keywords ?? "", query);
                    const shows = byHeading || null !== keyword;

                    hit.hidden = false === shows;
                    highlight(this.#label(hit), hit.dataset.terms, query);
                    this.#via(hit, keyword);
                    cardHit ||= shows;
                }

                page.hidden = false === (pageHit || cardHit);
                highlight(this.#label(page), page.dataset.terms, query);
                areaShows ||= false === page.hidden;
            }

            area.hidden = false === areaShows;

            const details = area.querySelector("details");

            if (null !== details && areaShows) {
                details.open = true;
            }

            anything ||= areaShows;
        }

        this.emptyTarget.hidden = anything;

        if (this.hasBannerTarget) {
            this.bannerTarget.hidden = true;
        }
    }

    clear(event) {
        if ("" === this.inputTarget.value) {
            return;
        }

        event.preventDefault();
        this.inputTarget.value = "";
        this.#reset();
    }

    // ── Private ─────────────────────────────────────────────────────────────

    #reset() {
        for (const area of this.areaTargets) {
            area.hidden = false;

            const details = area.querySelector("details");

            if (null !== details && undefined !== details.dataset.openWhileFiltering) {
                details.open = "true" === details.dataset.openWhileFiltering;
                delete details.dataset.openWhileFiltering;
            }
        }

        for (const page of this.pageTargets) {
            page.hidden = false;
            highlight(this.#label(page), page.dataset.terms, "");
        }

        for (const hit of this.hitTargets) {
            hit.hidden = true;
        }

        this.emptyTarget.hidden = true;

        if (this.hasBannerTarget) {
            this.bannerTarget.hidden = false;
        }
    }

    /**
     * Note which areas were open, once, at the first letter typed — so clearing
     * the field restores the reader's folding rather than the server's.
     */
    #rememberFolding() {
        for (const details of this.element.querySelectorAll("details")) {
            if (undefined === details.dataset.openWhileFiltering) {
                details.dataset.openWhileFiltering = details.open ? "true" : "false";
            }
        }
    }

    /** The label of a page or a card, not of the cards nested under a page. */
    #label(item) {
        return item.querySelector(":scope > a [data-settings--nav-target='label']");
    }

    #via(hit, keyword) {
        const via = hit.querySelector("[data-settings--nav-target='via']");

        if (null === via) {
            return;
        }

        via.hidden = null === keyword;
        via.textContent = null === keyword ? "" : `${this.keywordLabelValue}: ${keyword}`;
    }

    #flashAnchoredCard() {
        const id = window.location.hash.slice(1);

        if (id.startsWith("card-")) {
            flashCard(id);
        }
    }
}
