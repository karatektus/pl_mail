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
 * The row whose avatar was last clicked WITHOUT Shift, by id: where every
 * shift-click's range starts until the next plain click (#667). An id rather
 * than the element, because a background refresh or a row's own action
 * redraws the row, and the same conversation on screen is still the same
 * start to the person who clicked it.
 *
 * @type {string|null}
 */
let anchor = null;

/**
 * What the rows the current range changed were before it changed them, by
 * id — so the next shift-click from the same start can put back the ones it
 * no longer covers. Emptied whenever the start moves.
 *
 * @type {Map<string, boolean>}
 */
const beforeRange = new Map();

/**
 * The row the last shift-click ended on, by id, while the start is the same.
 * Shift-clicking it again flips the whole range (see pick()).
 *
 * @type {string|null}
 */
let reach = null;

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
        reach = null;
        beforeRange.clear();
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
 * An avatar was clicked, and `box` (its checkbox) has just flipped (#667).
 *
 * A plain click ticks or unticks its own row, as it always did, and makes
 * that row the start. A shift-click selects from the start to this row, both
 * included and in either direction — the way a file manager does it:
 *
 *   click row 1, shift-click row 10   rows 1–10 ticked
 *   then shift-click row 8            rows 1–8 ticked, 9 and 10 back as they were
 *
 * So the start stays where it is across shift-clicks; only a plain click
 * moves it. Each shift-click redraws the range from that start: rows the last
 * range changed and this one does not cover go back to what they were before
 * (beforeRange), which is what makes a range too long simply shift-click
 * shorter instead of something to untick by hand.
 *
 * The range takes the START's state, not the flip the browser just gave the
 * shift-clicked box: shift-clicking row 8, already ticked, means "up to here",
 * not "untick".
 *
 * Except on the row the last range ended on. Shift-clicking that one again
 * says nothing new about where the range ends, so it flips the whole range,
 * start included: a run ticked by mistake is unticked by repeating the click
 * that ticked it, and a third time ticks it again. What the rows were before
 * is kept through the flips, so a shorter range afterwards still puts back
 * the rows it leaves out.
 * A run can also be unticked from an unticked start: click the first row to
 * untick it, shift-click the last.
 *
 * Only the rows on screen, in the order they are on screen. A start left on
 * another page, or on a row that has since left the list, is no range at
 * all: the shift-click then behaves as a plain click and becomes the start.
 * What was carried over from other pages is never touched here.
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

    if (false === extend || -1 === from || -1 === to || from === to) {
        anchor = box.value;
        reach = null;
        beforeRange.clear();

        return;
    }

    const range = boxes.slice(Math.min(from, to), Math.max(from, to) + 1);
    const repeat = box.value === reach;
    const state = true === repeat ? false === boxes[from].checked : boxes[from].checked;

    reach = box.value;

    // The shift-clicked box was flipped by the click itself; what it was
    // before is the opposite, and that is what it goes back to if a later
    // range leaves it out.
    const was = (candidate) => (candidate === box ? !candidate.checked : candidate.checked);

    for (const candidate of false === repeat ? boxes : []) {
        if (true === beforeRange.has(candidate.value) && false === range.includes(candidate)) {
            candidate.checked = beforeRange.get(candidate.value);
            beforeRange.delete(candidate.value);
        }
    }

    for (const candidate of range) {
        if (false === beforeRange.has(candidate.value) && state !== was(candidate)) {
            beforeRange.set(candidate.value, was(candidate));
        }

        candidate.checked = state;
    }
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
