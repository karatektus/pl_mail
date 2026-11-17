/**
 * Which conversations are selected — including the ones on another page.
 *
 * A selection used to be nothing but the ticked checkboxes, so it was exactly
 * as long-lived as the rows carrying them: pressing "next page" replaced the
 * rows and the selection with them (#37). Ticking three conversations on page
 * one and two on page two is one selection to the person making it, and the
 * toolbar then acted on two.
 *
 * So the rows that are NOT on screen are remembered here, and the rows that
 * are on screen are never remembered at all — the checkbox is the truth for
 * anything visible, which is what lets every existing writer of a checkbox
 * (the master box, the select menu, a background refresh putting ticks back,
 * a drop clearing them) go on doing only that. selection() reconciles the two
 * every time it is asked.
 *
 * ## One list at a time
 *
 * A selection belongs to the list it was made in. enterView() is told which
 * list is on screen by the toolbar, each time one connects, and anything
 * carried from a different list is dropped there: "archive" pressed in Sent
 * must not reach five conversations ticked in the Inbox a minute ago. The page
 * number is not part of the key, which is the whole point; everything else
 * about the view is.
 *
 * ## Why the labels and the account travel with the id
 *
 * The label menu ticks a label when every selected conversation has it, the
 * move menu hides destinations they all share, and a drag refuses a folder of
 * an account it does not belong to. All three read those facts off the row,
 * and a row on another page is not there to be read.
 *
 * Module state rather than a Stimulus value: the toolbar lives inside the
 * list frame and is replaced with it on every page change, which is the very
 * moment this has to survive.
 */

const ROW_SELECT = "[data-thread-select]";

/** @type {string|null} */
let view = null;

/** @type {Map<string, {labelIds: string[], account: string|undefined}>} */
const carried = new Map();

/**
 * The row whose avatar was clicked last, by id: where a shift-click's range
 * starts (#667). An id rather than the element, because a background refresh
 * or a row's own action redraws the row, and the same conversation on screen
 * is still the same anchor to the person who clicked it.
 *
 * @type {string|null}
 */
let anchor = null;

function factsOf(checkbox) {
    const labelled = checkbox.closest("[data-label-ids]");
    const dragged = checkbox.closest("[data-dnd-thread]");

    return {
        labelIds: (labelled?.dataset.labelIds ?? "").split(",").filter((id) => "" !== id),
        account: dragged?.dataset.dndAccount,
    };
}

/**
 * Whether the rows on screen are conversations or single emails (#615): the
 * list says so on every row (`data-row-type`), and they are all one kind.
 * "thread" when there are none, which is what every list was before.
 *
 * Part of the view key below, and sent with every bulk request, because the
 * ids mean different things: email 42 is not conversation 42, and a
 * selection carried across the switch would otherwise be posted as the other
 * kind and act on other mail.
 */
export function rowType() {
    return document.querySelector("li[data-row-type]")?.dataset.rowType ?? "thread";
}

/** The list now on screen. Forgets a selection made in any other. */
export function enterView(key) {
    key = `${key}|${rowType()}`;

    if (key !== view) {
        carried.clear();
        anchor = null;
        view = key;
    }
}

/** Tick the rows on this page that were selected before it was left. */
export function restoreSelection() {
    for (const checkbox of document.querySelectorAll(ROW_SELECT)) {
        if (true === carried.has(checkbox.value)) {
            checkbox.checked = true;
        }
    }
}

/**
 * Everything selected: the ticked rows on screen, then the ones on other pages.
 *
 * @returns {{id: number, labelIds: string[], account: string|undefined}[]}
 */
export function selection() {
    for (const checkbox of document.querySelectorAll(ROW_SELECT)) {
        if (true === checkbox.checked) {
            carried.set(checkbox.value, factsOf(checkbox));
        } else {
            carried.delete(checkbox.value);
        }
    }

    return [...carried].map(([id, facts]) => ({ id: Number(id), ...facts }));
}

/**
 * An avatar was clicked, and `box` (its checkbox) has just changed (#667).
 *
 * A plain click only marks the row as where the next range starts. A
 * shift-click gives every row from that mark to this one, both included and
 * in either direction, the state this one now has — so the same gesture ticks
 * a run and, repeated, unticks it again, which is what makes a wrong range
 * cheap enough not to need a preview while Shift is held.
 *
 * That is also why a shift-click leaves the mark where it was. Moving it to
 * the row just shift-clicked (as some lists do) would make the repeat a range
 * of one row, and the "undo" would untick that row and leave the rest ticked.
 * Another shift-click, on any row, counts from the same start again.
 *
 * Only the rows on screen, in the order they are on screen. A mark left on
 * another page, or on a row that has since left the list, is no range at
 * all: the click then ticks its own row, as it always did, and becomes the
 * new mark. What was carried over from other pages is never touched here.
 *
 * Rows held `hidden` (taken out ahead of a request, see optimistic_rows.js)
 * are skipped, as the keyboard skips them: they are not on screen to be part
 * of what the eye took in between the two clicks.
 *
 * The checkboxes are set without firing `change` each: the caller's own change
 * event follows this click and tells the toolbar once, for all of them.
 */
export function pick(box, extend) {
    const boxes = [...document.querySelectorAll(ROW_SELECT)]
        .filter((candidate) => null === candidate.closest("[hidden]"));
    const from = boxes.findIndex((candidate) => candidate.value === anchor);
    const to = boxes.indexOf(box);

    if (true === extend && -1 !== from && -1 !== to) {
        for (const between of boxes.slice(Math.min(from, to), Math.max(from, to) + 1)) {
            between.checked = box.checked;
        }

        return;
    }

    anchor = box.value;
}

/**
 * Forget the rows that are not on screen. The caller unticks the ones that
 * are — it is the one that knows which list it means.
 */
export function clearSelection() {
    carried.clear();
}

/**
 * A label went onto, or came off, the whole selection.
 *
 * The rows on screen are redrawn by the response and say so themselves; the
 * ones on other pages have only what was written down when they were left,
 * and the label menu decides attach-or-detach from it.
 */
export function relabelSelection(labelId, attached) {
    const id = String(labelId);

    for (const facts of carried.values()) {
        facts.labelIds = facts.labelIds.filter((existing) => existing !== id);

        if (true === attached) {
            facts.labelIds.push(id);
        }
    }
}
