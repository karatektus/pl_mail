import { Controller } from "@hotwired/stimulus";
import * as Turbo from "@hotwired/turbo";
import { selection } from "../../mail_selection.js";

/**
 * Gmail's keyboard shortcuts (#36).
 *
 * ## It presses the buttons that are already there
 *
 * Nothing here archives, deletes or labels anything. Every key resolves to a
 * control the page already has — the toolbar's Archive, the row's own star, the
 * Reply link under a conversation — and clicks it. That is the whole design,
 * and it is what keeps a shortcut from drifting away from the button it is a
 * shortcut FOR: the request, the Undo toast, the row leaving the list, the
 * refusal where an action is not offered (no Archive in the bin) are all the
 * button's, and a key that found no button does nothing, which is the right
 * answer in exactly the places the button is absent.
 *
 * ## What a key acts on
 *
 * In this order, the first that exists:
 *
 *   1. the selection, through the list toolbar — whatever is ticked, on any
 *      page of the list;
 *   2. the conversation that is open, through its own toolbar;
 *   3. the row under the cursor (`j` / `k`), through the row's own buttons.
 *
 * Label, move and snooze have no button on a row. There the row is ticked
 * first and the toolbar's menu opened on a selection of one, which is what a
 * person would do with the mouse.
 *
 * ## The arrows are the list's, not the page's (#469)
 *
 * ↓ and ↑ move through the list the way they move through any list a browser
 * draws: the row gets the real focus, so a screen reader says which one it is,
 * and the cursor goes where the focus is. They are NOT aliases for `j` and
 * `k`. Those open the next conversation when one is open, and an arrow there
 * is how a long mail is read — taken for "next", ↓ jumped out of the mail
 * being read. So the arrows only answer where the list is what the keyboard
 * is on: a row has the focus, or nothing does and the list is all there is.
 * Anywhere else — the sidebar, an open mail, a key from inside the message —
 * they are the browser's, and so is the key past either end of the list, so
 * the page can still scroll down to what is under the last row.
 *
 * ## When it keeps quiet
 *
 * While anything is being typed into, while a dialog or a menu is open, and
 * whenever Ctrl, Alt or the Command key is held — those are the browser's and
 * the operating system's. Mounted only for somebody who has not switched the
 * shortcuts off: see templates/_layout/app.html.twig.
 */

// Not a row hidden while the action that takes it out of the list is under
// way (#335, assets/optimistic_rows.js): to the reader it has already gone, so
// the arrows step over it and the Tab stop is not left on it.
const ROW = "#message-list li[data-mail--message-row-id-value]:not([hidden])";
const ROW_ID = "data-mail--message-row-id-value";
const ROW_SELECT = "[data-thread-select]";
const CURSOR = "data-kbd-cursor";
const TOOLBAR = "[data-mail--list-toolbar-target='actions']";
const READING = "[data-surface='reading']";
const LIST = "[data-surface='list']";

/** How long a first key (`g`, `*`) waits for its second. */
const SEQUENCE_MS = 1500;

/**
 * The toolbar above a selection.
 *
 * `~=`, a whole word of the attribute: most of these are rows of the toolbar's
 * "More" menu, which carry a second action that closes the menu. Snooze and
 * Label are the triggers of their panels, laid unseen over that menu's
 * button — a key opens the panel itself, not the menu that leads to it.
 */
const BULK = {
    archive: "[data-action~='click->mail--list-toolbar#archiveSelected']",
    trash:   "[data-action~='click->mail--list-toolbar#deleteSelected']",
    spam:    "[data-action~='click->mail--list-toolbar#spamSelected']",
    star:    "[data-action~='click->mail--list-toolbar#starSelected']",
    read:    "[data-action~='click->mail--list-toolbar#markReadSelected']",
    unread:  "[data-action~='click->mail--list-toolbar#markUnreadSelected']",
    label:   "[data-toolbar-flyout='label']",
    move:    "[data-action~='click->mail--move-menu#toggle']",
    snooze:  "[data-toolbar-flyout='snooze']",
};

/** The toolbar of the open conversation, and the links under it. */
const OPEN = {
    archive:  "[data-action='click->mail--message-actions#archive']",
    trash:    "[data-action='click->mail--message-actions#trash']",
    spam:     "[data-action='click->mail--spam-menu#toggle']",
    unread:   "[data-action='click->mail--message-actions#markUnread']",
    star:     "[data-action*='mail--message-actions#star']",
    label:    "[data-action='click->mail--label-menu#toggle']",
    move:     "[data-action='click->mail--move-menu#toggle']",
    snooze:   "[data-shortcut='snooze']",
    back:     "[data-action='click->mail--mail-pane#close']",
    reply:    "[data-shortcut='reply']",
    replyAll: "[data-shortcut='reply-all']",
    forward:  "[data-shortcut='forward']",
};

/** A row's own buttons. Read and unread are one button that says which. */
const ON_ROW = {
    archive: "[data-action='click->mail--message-row#archive']",
    trash:   "[data-action='click->mail--message-row#trash']",
    spam:    "[data-action='click->mail--spam-menu#toggle']",
    star:    "[data-action='click->mail--message-row#toggleStar']",
    read:    "[data-action='click->mail--message-row#markRead'][data-mail--message-row-read-param='true']",
    unread:  "[data-action='click->mail--message-row#markRead'][data-mail--message-row-read-param='false']",
};

/** Actions after which the conversation is no longer in the list. */
const LEAVES = new Set(["archive", "trash"]);

/**
 * Which row the cursor is on, by conversation id.
 *
 * Module state, for the reason the selection is: the rows are replaced on
 * every page change and every background refresh, and an attribute written on
 * one of them goes with it. The id is what is remembered and the attribute is
 * repainted from it.
 */
let cursorId = null;

export default class extends Controller {
    static values = {
        // The lists `g` goes to: { i: "/mail/inbox", … }. Paths from the
        // router, because a route is the router's to spell.
        go: Object,
    };

    #prefix = null;
    #prefixTimer = null;
    #observer = null;
    #repaintQueued = false;

    /**
     * The row that had the focus, while it has it.
     *
     * Kept so the focus can be handed on when the row is REPLACED — a refresh,
     * a page of the list, the row archived from under it. The browser drops
     * the focus to <body> when its element leaves the document, and the next
     * ↓ would then have started from nowhere. This is why focus was once ruled
     * out for the cursor (see [data-kbd-cursor] in app.css); remembering the
     * row and putting the focus back on its successor is the answer to that.
     */
    #focusedRow = null;

    connect() {
        this._onKeydown = (event) => this.#handle(event);
        this._onFrameKey = (event) => this.#handle({
            ...event.detail,
            target: document.body,
            // A key from inside the message is never the list's: see #arrow().
            fromFrame: true,
            defaultPrevented: false,
            isComposing: false,
            preventDefault() {},
        });
        this._onFocusIn = (event) => this.#focusIn(event.target);
        this._onFocusOut = (event) => this.#focusOut(event.target);

        document.addEventListener("keydown", this._onKeydown);
        // Keys pressed inside a message. The body is a sandboxed frame with a
        // document of its own, so a key pressed after clicking into the mail
        // never reaches this one — it is relayed. See mail--message-frame.
        document.addEventListener("mail:frame-key", this._onFrameKey);
        document.addEventListener("focusin", this._onFocusIn);
        document.addEventListener("focusout", this._onFocusOut);

        // The cursor is an attribute on a row, and rows are replaced by four
        // different mechanisms (frame navigation, Turbo streams, the pane's
        // own morph, a full visit). Watching the document for rows changing is
        // one answer to all of them.
        this.#observer = new MutationObserver(() => this.#queueRepaint());
        // `hidden` as well, which is how a row leaves the list before the server
        // has answered (#335) — still in the document, but no longer a row.
        this.#observer.observe(document.body, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ["hidden"],
        });

        this.#paint();
    }

    disconnect() {
        document.removeEventListener("keydown", this._onKeydown);
        document.removeEventListener("mail:frame-key", this._onFrameKey);
        document.removeEventListener("focusin", this._onFocusIn);
        document.removeEventListener("focusout", this._onFocusOut);
        this.#observer?.disconnect();
        clearTimeout(this.#prefixTimer);
    }

    // ── Keys ──────────────────────────────────────────────────────────────

    #handle(event) {
        if (true === event.defaultPrevented || true === event.isComposing) {
            return;
        }

        if (true === event.ctrlKey || true === event.metaKey || true === event.altKey) {
            return;
        }

        if (true === this.#busyElsewhere(event.target)) {
            return;
        }

        const key = event.key;
        const prefix = this.#prefix;

        this.#clearPrefix();

        if (null !== prefix) {
            if (true === this.#second(prefix, key)) {
                event.preventDefault();
            }

            return;
        }

        if ("g" === key || "*" === key) {
            this.#prefix = key;
            this.#prefixTimer = setTimeout(() => this.#clearPrefix(), SEQUENCE_MS);
            event.preventDefault();

            return;
        }

        // Enter on a focused link or button is that control's own, and it is
        // how the whole application is used from the keyboard without this.
        if ("Enter" === key && null !== event.target?.closest?.("a, button, summary, [role='button']")) {
            return;
        }

        if ("ArrowDown" === key || "ArrowUp" === key) {
            if (true === this.#arrow(event, "ArrowDown" === key ? 1 : -1)) {
                event.preventDefault();
            }

            return;
        }

        if (true === this.#first(key)) {
            event.preventDefault();
        }
    }

    /**
     * ↓ or ↑. Answers whether it was the list's — see "The arrows are the
     * list's" above for when that is.
     *
     * Shift is left alone too: Shift with an arrow widens a text selection,
     * and somebody doing that is reading, not moving.
     */
    #arrow(event, delta) {
        if (true === event.fromFrame || true === event.shiftKey) {
            return false;
        }

        const row = event.target?.closest?.(ROW) ?? null;

        // Nothing has the focus. The list's only when it is the whole of what
        // is open: with a conversation beside it, the arrows read that.
        if (null === row && (document.body !== event.target || null !== this.#reading() || false === this.#listShowing())) {
            return false;
        }

        const rows = this.#rows();
        const from = row ?? this.#cursorRow();
        const at = null === from ? -1 : rows.indexOf(from);
        const next = -1 === at ? 0 : at + delta;

        if (next < 0 || next >= rows.length) {
            return false;
        }

        this.#focusRow(rows[next]);

        return true;
    }

    /** A single key. Answers whether it did anything. */
    #first(key) {
        switch (key) {
            case "c": return this.#press(document.querySelector("[data-shortcut='compose']"));
            case "/": return this.#focusSearch();
            case "?": return this.#press(document.querySelector("[data-shortcut='help']"));
            case "z": return this.#press(document.querySelector("#toast-region button[data-action$='#abort']"));

            case "j": return this.#step(1);
            case "k": return this.#step(-1);
            case "o":
            case "Enter": return this.#openCursor();
            case "x": return this.#tickCursor();
            case "u": return this.#press(this.#reading()?.querySelector(OPEN.back));

            case "e": return this.#act("archive");
            case "#": return this.#act("trash");
            case "!": return this.#act("spam");
            case "s": return this.#act("star");
            case "I": return this.#act("read");
            case "U": return this.#act("unread");
            case "l": return this.#act("label");
            case "v": return this.#act("move");
            case "b": return this.#act("snooze");

            case "r": return this.#press(this.#reading()?.querySelector(OPEN.reply));
            // Reply to all, and a plain reply where there is nobody else to
            // include. The Reply-all link is only drawn for a message with more
            // than one recipient, so on every other message `a` found no button
            // and did nothing — which reads as a broken key, not as "there is
            // only one person here". Gmail answers `a` with a reply there too.
            case "a": return this.#press(
                this.#reading()?.querySelector(OPEN.replyAll) ?? this.#reading()?.querySelector(OPEN.reply),
            );
            case "f": return this.#press(this.#reading()?.querySelector(OPEN.forward));

            default: return false;
        }
    }

    /** The key after `g` or `*`. */
    #second(prefix, key) {
        if ("g" === prefix) {
            const url = (this.goValue ?? {})[key];

            if (undefined === url) {
                return false;
            }

            Turbo.visit(url);

            return true;
        }

        const menu = { a: "selectAll", n: "selectNone", r: "selectRead", u: "selectUnread", s: "selectStarred" }[key];

        if (undefined === menu || false === this.#listShowing()) {
            return false;
        }

        return this.#press(document.querySelector(`[data-action='click->mail--list-toolbar#${menu}']`));
    }

    // ── What a key acts on ────────────────────────────────────────────────

    #act(name) {
        // 1. The selection — wherever the list it was made in is on screen,
        // which with the reading pane beside the list includes "while a
        // conversation is open". Asking only whether one was open sent `#`
        // to the open conversation with three other rows ticked beside it.
        if (true === this.#listShowing() && selection().length > 0) {
            return this.#press(document.querySelector(TOOLBAR)?.querySelector(BULK[name]));
        }

        // 2. The open conversation.
        const reading = this.#reading();

        if (null !== reading) {
            if (false === (name in OPEN)) {
                return false;
            }

            // Where the cursor goes when this one has left the list, decided
            // before it leaves: afterwards there is no row to stand beside.
            if (true === LEAVES.has(name)) {
                this.#followOpen();
                this.#moveOffLeavingRow();
            }

            return this.#press(reading.querySelector(OPEN[name]));
        }

        // 3. The row under the cursor.
        const row = this.#cursorRow();

        if (null === row) {
            return false;
        }

        if (false === (name in ON_ROW)) {
            // No button of its own on a row: tick it, and use the toolbar's.
            this.#tick(row, true);

            return this.#press(document.querySelector(TOOLBAR)?.querySelector(BULK[name]));
        }

        const button = row.querySelector(ON_ROW[name]);

        if (null !== button && true === LEAVES.has(name)) {
            this.#moveOffLeavingRow();
        }

        return this.#press(button);
    }

    // ── The cursor ────────────────────────────────────────────────────────

    #rows() {
        return [...document.querySelectorAll(ROW)];
    }

    #cursorRow() {
        if (null === cursorId) {
            return null;
        }

        return this.#rows().find((row) => row.getAttribute(ROW_ID) === cursorId) ?? null;
    }

    /**
     * Down or up one row. With a conversation open, the next one is opened as
     * well: there is no list on screen to move a cursor in, and "next" there
     * can only mean the next thing to read.
     */
    #step(delta) {
        const rows = this.#rows();

        if (0 === rows.length) {
            return false;
        }

        const reading = null !== this.#reading();

        if (true === reading) {
            this.#followOpen();
        }

        const at = rows.findIndex((row) => row.getAttribute(ROW_ID) === cursorId);
        const next = -1 === at ? 0 : Math.max(0, Math.min(rows.length - 1, at + delta));

        if (next === at) {
            return true;
        }

        // A row with the focus takes it along: left behind, the focus would
        // be on one row and the cursor on another, and the next ↓ would start
        // from the wrong one.
        if (false === reading && null !== document.activeElement?.closest?.(ROW)) {
            this.#focusRow(rows[next]);

            return true;
        }

        cursorId = rows[next].getAttribute(ROW_ID);
        this.#paint();

        if (true === reading) {
            return this.#openCursor();
        }

        rows[next].scrollIntoView({ block: "nearest" });

        return true;
    }

    /**
     * The focus onto a row, and the cursor with it.
     *
     * `preventScroll`, then `nearest`: focus() on its own scrolls the row to
     * wherever the browser likes, which on a long list is the middle — the list
     * lurched on every key. Nearest moves it only as far as it takes.
     */
    #focusRow(row) {
        cursorId = row.getAttribute(ROW_ID);
        this.#paint();
        row.focus({ preventScroll: true });
        row.scrollIntoView({ block: "nearest" });
    }

    /**
     * The cursor follows the focus — a row clicked, or reached with Tab, is
     * where ↓ goes on from. Only the row itself: a star or a checkbox inside
     * one having the focus is the focus being on that control.
     */
    #focusIn(target) {
        const row = target?.matches?.(ROW) ? target : null;

        this.#focusedRow = row;

        if (null !== row) {
            cursorId = row.getAttribute(ROW_ID);
            this.#paint();
        }
    }

    /**
     * A row that loses the focus and is still in the document gave it up —
     * a click on the page's background, the window left. One that is no longer
     * in it was replaced, and #paint() hands the focus on. Asked a task later,
     * because a row being removed can report the focus leaving before it is
     * gone, and only after it is does isConnected say so.
     */
    #focusOut(target) {
        if (target !== this.#focusedRow) {
            return;
        }

        queueMicrotask(() => {
            // A row that was hidden (#335) lost the focus by leaving, not by
            // the reader moving it, and is handed on like a removed one.
            if (target === this.#focusedRow && true === target.isConnected && false === target.hidden) {
                this.#focusedRow = null;
            }
        });
    }

    #openCursor() {
        return this.#press(this.#cursorRow()?.querySelector(
            "a[data-action*='mail--mail-pane#open'], a[data-turbo-frame='compose_dock']",
        ));
    }

    #tickCursor() {
        const row = this.#cursorRow();

        if (null === row || false === this.#listShowing()) {
            return false;
        }

        const box = row.querySelector(ROW_SELECT);

        return null !== box && this.#tick(row, false === box.checked);
    }

    /** The checkbox and the event the toolbar listens for — see mail--message-row. */
    #tick(row, checked) {
        const box = row.querySelector(ROW_SELECT);

        if (null === box) {
            return false;
        }

        if (box.checked !== checked) {
            box.checked = checked;
            box.dispatchEvent(new Event("change", { bubbles: true }));
        }

        return true;
    }

    /** A conversation opened with the mouse is where the cursor is. */
    #followOpen() {
        const open = this.#reading()
            ?.querySelector("[data-mail--message-actions-entity-type-value='thread']")
            ?.getAttribute("data-mail--message-actions-entity-id-value");

        if (open) {
            cursorId = open;
        }
    }

    /** Onto the row after the one about to leave, or the one before the last. */
    #moveOffLeavingRow() {
        const rows = this.#rows();
        const at = rows.findIndex((row) => row.getAttribute(ROW_ID) === cursorId);

        if (-1 === at) {
            return;
        }

        cursorId = (rows[at + 1] ?? rows[at - 1])?.getAttribute(ROW_ID) ?? null;
        this.#paint();
    }

    #queueRepaint() {
        if (true === this.#repaintQueued) {
            return;
        }

        this.#repaintQueued = true;

        requestAnimationFrame(() => {
            this.#repaintQueued = false;
            this.#paint();
        });
    }

    #paint() {
        for (const row of document.querySelectorAll(`[${CURSOR}]`)) {
            if (row.getAttribute(ROW_ID) !== cursorId) {
                row.removeAttribute(CURSOR);
            }
        }

        const row = this.#cursorRow();

        if (null !== row && false === row.hasAttribute(CURSOR)) {
            row.setAttribute(CURSOR, "");
        }

        // One row in the Tab order, the rest reached by the arrows — the way
        // a list widget is walked, rather than fifty Tabs to get past it. The
        // cursor's row, or the first while there is none. Every row is
        // tabindex="-1" in the markup so it can take the focus at all.
        const rows = this.#rows();
        const stop = row ?? rows[0] ?? null;

        for (const each of rows) {
            const tabindex = each === stop ? "0" : "-1";

            if (each.getAttribute("tabindex") !== tabindex) {
                each.setAttribute("tabindex", tabindex);
            }
        }

        // The focused row was replaced and the focus fell to <body>: onto its
        // successor, which is the cursor's row — same id, or the row
        // #moveOffLeavingRow() chose for one that left the list. Hidden counts
        // as left: the browser drops the focus from a hidden element the same
        // way it does from a removed one.
        if (null !== this.#focusedRow && (false === this.#focusedRow.isConnected || true === this.#focusedRow.hidden)) {
            this.#focusedRow = null;

            if (null !== row && (null === document.activeElement || document.body === document.activeElement)) {
                row.focus({ preventScroll: true });
            }
        }
    }

    // ── Small things ──────────────────────────────────────────────────────

    /** The open conversation's pane, or null while the list is showing. */
    #reading() {
        const pane = document.querySelector(READING);

        if (null === pane || true === pane.classList.contains("hidden") || 0 === pane.childElementCount) {
            return null;
        }

        return pane;
    }

    /**
     * Whether the list is on screen.
     *
     * Not the opposite of #reading(): with the reading pane beside the list
     * both are. Asked of the computed style rather than of the `hidden` class,
     * because beside the list mail--mail-pane still sets that class and a
     * container query in app.css overrules it — the class says what one pane
     * at a time would show, the style says what is drawn.
     */
    #listShowing() {
        const list = document.querySelector(LIST);

        return null !== list && "none" !== getComputedStyle(list).display;
    }

    #focusSearch() {
        const field = document.querySelector("#search-shell input[name='q']");

        if (null === field) {
            return false;
        }

        field.focus();
        field.select?.();

        return true;
    }

    #press(element) {
        if (null === element || undefined === element || true === element.disabled) {
            return false;
        }

        element.click();

        return true;
    }

    #clearPrefix() {
        clearTimeout(this.#prefixTimer);
        this.#prefix = null;
    }

    /**
     * Whether the keyboard is somebody else's right now.
     *
     * Typing is the obvious case. The other two matter as much: a dialog owns
     * every key while it is open, and a menu that is open has its own arrows
     * and its own Escape — `e` there must not archive the mail behind it.
     *
     * A checkbox is an input nobody types into. Counting it as one meant that
     * ticking a row with the mouse left the focus on its box and every key
     * after it dead — tick three rows, press `e`, nothing — while the same
     * rows ticked with `x` worked, because `x` never moves the focus.
     */
    #busyElsewhere(target) {
        if (target?.closest?.("input:not([type='checkbox']), textarea, select, [contenteditable]:not([contenteditable='false']), [role='textbox']")) {
            return true;
        }

        if (null !== document.querySelector("#modal-backdrop:not([hidden]), dialog[open]")) {
            return true;
        }

        try {
            return null !== document.querySelector("[popover]:popover-open");
        } catch {
            // A browser without :popover-open has no open popover to find.
            return false;
        }
    }
}
