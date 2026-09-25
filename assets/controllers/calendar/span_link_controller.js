import { Controller } from "@hotwired/stimulus";

/**
 * One entry, one hover: pointing at any piece of an entry that is drawn in
 * several places lights all of them.
 *
 * The pieces are an entry of a day or more — its bar in the all-day band and
 * the hours it shades in each day's column — and a meeting cut at midnight,
 * whose two halves sit in two columns. Each carries the same `data-span-key`
 * (DayGridLayout hands it out), and a piece that is lit gets `is-lit`, which the
 * templates style through the `lit:` variant exactly as they style `:hover`.
 *
 * It was one block per day before, each lifting on its own under the pointer,
 * which made a weekend away look like three events. CSS cannot do this itself:
 * `:hover` belongs to one element, and the pieces are in different columns and
 * different rows of the grid.
 *
 * Mouse only. A tap on a touch screen leaves `:hover` stuck until the next tap,
 * which is why `chip-open` is gated on `(hover: hover)`; a pen or a finger
 * lighting a week's worth of pieces and leaving them lit would be the same bug
 * spread over several columns.
 *
 * Delegated from the scroller, which holds the band and the hours both, so the
 * pieces need no controller of their own and a re-render needs no rewiring.
 * `pointerover` and `pointerout` rather than enter and leave because those do
 * not bubble; the cost is that moving between two parts of one piece fires them
 * too, which is what the checks below absorb.
 */
export default class extends Controller {
    enter(event) {
        if ("mouse" !== event.pointerType) {
            return;
        }

        const piece = event.target.closest("[data-span-key]");

        if (null === piece || piece.classList.contains("is-lit")) {
            return;
        }

        this.#light(piece.dataset.spanKey);
    }

    leave(event) {
        if ("mouse" !== event.pointerType) {
            return;
        }

        const piece = event.target.closest("[data-span-key]");

        if (null === piece) {
            return;
        }

        // Still on the same entry: into a child of this piece, or across from
        // one day's shading into the next day's. Unlighting there would flicker
        // the whole entry off and straight back on.
        const next = event.relatedTarget instanceof Element
            ? event.relatedTarget.closest("[data-span-key]")
            : null;

        if (null !== next && next.dataset.spanKey === piece.dataset.spanKey) {
            return;
        }

        this.#light(null);
    }

    #light(key) {
        for (const lit of this.element.querySelectorAll(".is-lit")) {
            if (lit.dataset.spanKey !== key) {
                lit.classList.remove("is-lit");
            }
        }

        if (null === key) {
            return;
        }

        for (const piece of this.element.querySelectorAll(`[data-span-key="${CSS.escape(key)}"]`)) {
            piece.classList.add("is-lit");
        }
    }
}
