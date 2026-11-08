/**
 * Rows taken out of the mail list before the server has answered (#335).
 *
 * Archive, delete, move and the rest used to leave the row where it was until
 * the response arrived, and only the Turbo Stream in that response removed it.
 * On a slow connection the click looked like it had done nothing, and the
 * natural answer to that is a second click. So a row that the action is going
 * to take out of this list is now hidden the moment the action is chosen, and
 * the request runs behind it.
 *
 * HIDDEN, NOT REMOVED
 * ───────────────────
 * The row stays in the document with `hidden` on it, rather than being
 * detached and kept in a variable. That one choice is what makes the rest
 * simple:
 *
 *  - Putting it back after a failure is taking the attribute off. There is no
 *    position to remember and no neighbour that may have moved in the
 *    meantime — the row never left its place.
 *  - On success the server's stream still finds it by id. `remove` takes it
 *    out for real; `replace`, which is what the server sends for a row that
 *    turns out to stay in this list after all (see leavesList() below), swaps
 *    in a new element without the attribute, so a wrong guess here corrects
 *    itself rather than losing a row.
 *  - The list refresh that follows every write (mail--mail-pane#release)
 *    morphs the list into the server's markup, which never carries `hidden`.
 *    Whatever this file got wrong, that refresh puts right.
 *
 * NO EXIT ANIMATION
 * ─────────────────
 * The issue suggested the leave motion. motion.css explains why archiving does
 * not play one: an exit animation is time between asking and getting, every
 * time. This is about the row going sooner, so it goes at once.
 *
 * WHAT THE CALLER STILL OWNS
 * ──────────────────────────
 * Holding the list refresh for the length of the request
 * (`mail--list-toolbar:writing` / `:written`), so a refresh issued before the
 * write lands cannot draw the old list back over the hidden rows. And putting
 * the rows back BEFORE releasing that hold on failure, so the refresh finds the
 * list already as the server has it.
 */
import { listView } from "./list_view.js";

/** Set beside `hidden` so a test, or a person in the inspector, can tell why. */
const MARK = "data-optimistic-gone";

/** The scope All Mail names itself with — App\Service\Mail\AllMailView::SCOPE. */
const ALL_MAIL = "all_mail";

/**
 * Hide these rows now, and get back the way to show them again.
 *
 * Rows that are already hidden, or no longer in the document, are left out:
 * a second action on a row that is already on its way out must not be the one
 * that puts it back when it fails.
 *
 * @param {Iterable<Element|null>} rows
 * @returns {{ rows: Element[], putBack: () => void }}
 */
export function takeOut(rows) {
    const taken = [...new Set(rows)].filter(
        (row) => row instanceof Element && true === row.isConnected && false === row.hidden,
    );

    for (const row of taken) {
        row.hidden = true;
        row.setAttribute(MARK, "");
    }

    let settled = false;

    return {
        rows: taken,

        /**
         * The request failed: every row back where it stood, as it was.
         *
         * Only the rows still in the document. One that a stream or a refresh
         * replaced in the meantime has a successor drawn from the server, and
         * that is the better answer.
         */
        putBack() {
            if (true === settled) {
                return;
            }

            settled = true;

            for (const row of taken) {
                if (true === row.isConnected) {
                    row.hidden = false;
                    row.removeAttribute(MARK);
                }
            }
        },
    };
}

/**
 * Whether an action takes its conversations out of the list on screen.
 *
 * A guess made in the browser about what the server will decide, so it mirrors
 * the server's rules and errs towards false. A false that should have been true
 * costs what this change set out to fix — the row waits for the answer — and
 * nothing more. A true that should have been false hides a row the server then
 * draws again, which is a flicker; it never loses one (see takeOut()).
 *
 * The rules, and where the server keeps them:
 *
 *  - trash, purge, restore, a drop on a folder or a category tab: always.
 *    Nothing that starts in a list survives these in it.
 *  - archive, snooze: everywhere but All Mail, which still holds the
 *    conversation afterwards (ThreadStatusController::allMailRow(),
 *    BulkStatusController's `$fromAllMail`).
 *  - unsnooze (snooze with no wake time): in Snoozed, the one list it leaves.
 *  - move-to: see movesOut().
 *
 * @param {string} action  the status route's last segment, or a drop's kind
 * @param {object} body    what the request will carry (`until`, `role`, `labelId`)
 * @returns {boolean}
 */
export function leavesList(action, body = {}) {
    const { scope, value } = listView();

    switch (action) {
        case "trash":
        case "purge":
        case "restore":
        case "move":
        case "category":
            return true;
        case "archive":
            return ALL_MAIL !== scope;
        case "snooze":
            return null === (body.until ?? null) ? "snoozed" === scope : ALL_MAIL !== scope;
        case "move-to":
            return movesOut(body, scope, value);
        default:
            return false;
    }
}

/**
 * Whether "Move to" takes the conversation out of this list.
 *
 * MoveToService::plan() decides which label comes off: the one the list IS,
 * for the Inbox, the bin, Spam and a label of the user's own; the Inbox, for
 * a list that is not a label. staysInView() then asks whether the thread still
 * belongs. Only the first kind is answered here — a list that is a label loses
 * the conversation unless it was "moved" to that same label. Search, Starred,
 * Sent, Archive and the rest keep it unless it went to the bin or Spam, which
 * every list but their own leaves out; for those this says false and the row
 * waits for the server, as it always has.
 */
function movesOut(body, scope, value) {
    const role    = body.role ?? null;
    const labelId = null === (body.labelId ?? null) ? null : String(body.labelId);

    if (null !== role && role === scope) {
        return false;
    }

    if ("trash" === role || "spam" === role) {
        return true;
    }

    if ("inbox" === scope || "trash" === scope || "spam" === scope) {
        return true;
    }

    return "label" === scope && labelId !== value;
}

/** The list row a control sits in, or null for one outside the list. */
export function rowOf(element) {
    return element?.closest?.("#message-list li[data-controller~='mail--message-row']") ?? null;
}
