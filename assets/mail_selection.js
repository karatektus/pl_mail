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

function factsOf(checkbox) {
    const labelled = checkbox.closest("[data-label-ids]");
    const dragged = checkbox.closest("[data-dnd-thread]");

    return {
        labelIds: (labelled?.dataset.labelIds ?? "").split(",").filter((id) => "" !== id),
        account: dragged?.dataset.dndAccount,
    };
}

/** The list now on screen. Forgets a selection made in any other. */
export function enterView(key) {
    if (key !== view) {
        carried.clear();
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
