import { Controller } from "@hotwired/stimulus";
import { jsonCsrfHeaders } from "../../csrf.js";
import { announceWrite } from "../../mail_writes.js";

/**
 * Dragging conversations onto folders and onto category tabs.
 *
 * Mounted on <body> for every mail list page, because the two ends of the
 * gesture are in different parts of the document: the rows are inside the list
 * frame, the folders are in the sidebar — two of them, since the partial is
 * rendered again for the mobile drawer — and the tab strip is a third place
 * again. A controller on any one of those can only see its own subtree.
 *
 * Values:
 *   moveUrl      where a drop on a folder posts
 *   categoryUrl  where a drop on a category tab posts
 *   dragging     what the drag image says when several rows travel together;
 *                %count% is substituted
 *
 * Markup contract (attributes, not targets — the elements are rendered by four
 * different templates and Stimulus targets would tie each of them to this
 * controller's name):
 *
 *   [data-dnd-thread]           a draggable row. Value is the thread id.
 *   [data-dnd-account]          on the same row: which account it belongs to.
 *
 *   [data-dnd-folder]           a drop target. Value is the destination label id.
 *   [data-dnd-category]         a drop target. Value is a MessageCategory.
 *   [data-dnd-account]          on a target: only rows from this account may
 *                               land. Absent means "any".
 *
 * Routes used:
 *   POST {moveUrl}      { ids, labelId }
 *   POST {categoryUrl}  { ids, category }
 *
 * ── The gesture is HTML5 drag-and-drop, not pointer events ──────────────────
 *
 * The calendar's block-dragging is built on pointer events, and this is not,
 * which is a real difference worth stating. That one needs a live preview that
 * snaps to a grid while the pointer moves — the position IS the value, so it
 * has to be drawn every frame. This one has a discrete destination: the answer
 * is which element you let go over, and everything in between is travel. For
 * that, the platform's own drag gives auto-scrolling inside the sidebar, a drag
 * image, the not-allowed cursor over invalid targets and an Escape key that
 * cancels — all of it behaviour a pointer implementation would have to rebuild
 * and would rebuild slightly differently.
 *
 * The cost is touch. HTML5 drag does not fire from a finger, so this gesture is
 * mouse and trackpad only. That is a smaller gap than it sounds: on a phone the
 * sidebar is a drawer that is closed while the list is on screen, so there is
 * nothing to drag ONTO, and the same two operations are already reachable there
 * — folders through the label menu and the row's own archive/delete buttons.
 * The one thing with no non-drag route is the category, which is why the row's
 * overflow menu grew a "Move to category" section; see _thread_row.
 *
 * ── Nothing here decides what a move means ──────────────────────────────────
 *
 * The drop posts to the same bulk endpoint the toolbar's buttons post to, and
 * the server answers with the same stream: the rows are removed, the list frame
 * is re-read for the pager and the empty state. That is deliberate. A drag is
 * just another way to select a few conversations and name a destination, and
 * giving it a private endpoint would have meant a second implementation of
 * "what happens to the list afterwards" — which is the exact thing the toolbar
 * already got wrong once and fixed. See mail--list-toolbar#_bulkPost, whose
 * writing/written dance this mirrors for the same reason.
 */
export default class extends Controller {
    static values = {
        moveUrl: String,
        categoryUrl: String,
        dragging: { type: String, default: "%count% conversations" },
    };

    /** Marks the document while a drag is in flight, so app.css can light up targets. */
    static ACTIVE_ATTRIBUTE = "dndActive";

    /** Marks the one target the pointer is over. */
    static OVER_ATTRIBUTE = "dndOver";

    /** Marks a target THIS drag cannot land on, so it is not lit up. */
    static REFUSED_ATTRIBUTE = "dndRefused";

    /** Every drop target on the page, of either kind. */
    static TARGETS = "[data-dnd-folder], [data-dnd-category]";

    /** The conversations in flight, or null when nothing is being dragged. */
    #carrying = null;

    /** The target the pointer is currently over, so it can be un-marked. */
    #over = null;

    connect() {
        this.element.addEventListener("dragstart", this._onDragStart = this.#onDragStart.bind(this));
        this.element.addEventListener("dragend", this._onDragEnd = this.#onDragEnd.bind(this));
        this.element.addEventListener("dragover", this._onDragOver = this.#onDragOver.bind(this));
        this.element.addEventListener("drop", this._onDrop = this.#onDrop.bind(this));
    }

    disconnect() {
        this.element.removeEventListener("dragstart", this._onDragStart);
        this.element.removeEventListener("dragend", this._onDragEnd);
        this.element.removeEventListener("dragover", this._onDragOver);
        this.element.removeEventListener("drop", this._onDrop);

        this.#teardown();
    }

    // ── Private ───────────────────────────────────────────────────────────

    #onDragStart(event) {
        const row = event.target.closest?.("[data-dnd-thread]");

        if (null === row || undefined === row) {
            return;
        }

        const carried = this.#carriedBy(row);

        if (carried.ids.length === 0) {
            return;
        }

        this.#carrying = carried;

        // Set, even though nothing reads it back: a drag with an empty
        // dataTransfer is cancelled outright by Firefox, and the failure looks
        // like the row simply not being draggable. text/plain rather than a
        // custom type so a drag that escapes into another application drops
        // something legible rather than nothing.
        event.dataTransfer.setData("text/plain", carried.subject);
        event.dataTransfer.effectAllowed = "move";

        this.#setDragImage(event, carried);

        // Which targets this particular drag can land on, decided once and
        // written into the DOM so the stylesheet can light up those and only
        // those.
        //
        // Without it the affordance lies. Every folder row would go dashed the
        // moment a drag began, including the one you are looking at — which
        // refuses the drop, correctly, because moving a conversation into the
        // list it is already in is a no-op that would make the row vanish and
        // come back. An offer that is withdrawn on contact is worse than no
        // offer: it reads as the drop having failed.
        //
        // Recomputed per drag rather than per dragover: what a target accepts
        // depends on the conversations being carried, which do not change once
        // the gesture has started, and marking forty rows on every dragover is
        // work at 60Hz for an answer that cannot have changed.
        for (const target of document.querySelectorAll(this.constructor.TARGETS)) {
            if (false === this.#accepts(target)) {
                target.dataset[this.constructor.REFUSED_ATTRIBUTE] = "true";
            }
        }

        document.documentElement.dataset[this.constructor.ACTIVE_ATTRIBUTE] = "true";
    }

    #onDragEnd() {
        this.#teardown();
    }

    /**
     * dragover is the ONLY place the highlight moves — there is no dragleave
     * handler, deliberately.
     *
     * The obvious arrangement is enter/leave, and it does not survive contact
     * with a real target. A drop target here is a whole sidebar row containing
     * a link, a badge and sometimes an edit button, so crossing it fires a
     * leave for one child and an enter for the next; the usual defence is to
     * ignore a leave whose relatedTarget is still inside the row, and during a
     * native drag relatedTarget is routinely null. The result is a highlight
     * that blinks off as the pointer moves within the very row it is over.
     *
     * dragover has none of that. It fires continuously while the pointer is
     * over a target — several times a second even when it is still — so
     * "somewhere else now" is simply the next one arriving, and "nowhere" is a
     * dragover that resolves no target. The only case left is the pointer
     * leaving the window entirely, which stops the events; dragend clears up
     * after that, and it fires whatever the drag ended by.
     */
    #onDragOver(event) {
        const target = this.#targetFor(event.target);

        if (null === target) {
            this.#clearOver();

            return;
        }

        // Both calls are required and they are not the same thing.
        // preventDefault on dragover is what makes an element a drop target at
        // all — the default action is "refuse" — and dropEffect is what decides
        // which cursor the pointer wears while it is over one.
        event.preventDefault();
        event.dataTransfer.dropEffect = "move";

        if (target !== this.#over) {
            this.#clearOver();
            this.#over = target;
            target.dataset[this.constructor.OVER_ATTRIBUTE] = "true";
        }
    }

    async #onDrop(event) {
        const target = this.#targetFor(event.target);

        if (null === target || null === this.#carrying) {
            return;
        }

        // Stops the browser treating the payload as a navigation — dropping
        // anything with a text/plain body on a page otherwise tries to open it.
        event.preventDefault();

        const ids = this.#carrying.ids;
        const folder = target.dataset.dndFolder;

        this.#teardown();

        if (undefined !== folder) {
            await this.#post(this.moveUrlValue, "move", { ids, labelId: Number(folder) });

            return;
        }

        await this.#post(this.categoryUrlValue, "category", { ids, category: target.dataset.dndCategory });
    }

    /**
     * Which conversations this drag is carrying.
     *
     * A drag that starts on a SELECTED row takes the whole selection, which is
     * what every file manager does and what makes the checkboxes worth having
     * here at all. A drag that starts anywhere else takes that row alone and
     * leaves the selection untouched — deliberately: clearing it would mean a
     * dragged-then-abandoned row silently threw away a selection somebody had
     * spent a minute building.
     */
    #carriedBy(row) {
        const own = row.querySelector("[data-thread-select]");
        // The row's full-width overlay anchor is labelled with the subject —
        // see _thread_row. Only used as the text/plain payload, which nothing
        // in this application reads back; it is what a drag that escapes into
        // another window drops.
        const subject = row.querySelector("a[aria-label]")?.getAttribute("aria-label") ?? "";

        if (true !== own?.checked) {
            return {
                ids: [Number(row.dataset.dndThread)],
                accounts: new Set([row.dataset.dndAccount]),
                subject,
            };
        }

        // Scoped to the list the drag started in, not the document. Rows are
        // rendered by the same partial into places that are not a mail list —
        // the compose streams redraw one on its own — and a selection is a
        // property of ONE list. Falling back to the document keeps a row that
        // somehow has no list around it draggable on its own terms.
        const list = row.closest("[data-list-region='rows']") ?? document;

        const rows = [...list.querySelectorAll("[data-dnd-thread]")]
            .filter((candidate) => true === candidate.querySelector("[data-thread-select]")?.checked);

        return {
            ids: rows.map((candidate) => Number(candidate.dataset.dndThread)),
            accounts: new Set(rows.map((candidate) => candidate.dataset.dndAccount)),
            subject,
        };
    }

    /**
     * The drop target under a given node, or null when there is not one this
     * drag may land on.
     *
     * Two refusals live here rather than at the drop, because a target that
     * will refuse has to say so while the pointer is still over it — that is
     * the whole job of the not-allowed cursor. Returning null leaves dragover's
     * default in place, and the default is "this is not a drop target".
     */
    #targetFor(node) {
        const target = node.closest?.(this.constructor.TARGETS);

        if (null === target || undefined === target) {
            return null;
        }

        return true === this.#accepts(target) ? target : null;
    }

    /**
     * Whether this drag may land on a given target.
     *
     * Read twice and it has to give the same answer both times: once at
     * dragstart, to decide which targets light up, and again on every dragover,
     * to decide which one the pointer is actually over. Two copies of these
     * conditions would be an interface that offers a row and then refuses it.
     */
    #accepts(target) {
        if (null === this.#carrying) {
            return false;
        }

        // The folder you are already looking at takes no drops. Moving a
        // conversation into the list it is already in is a no-op the server
        // handles correctly and the LIST cannot: the answer removes the row,
        // and the frame refresh a moment later puts it back — a flicker with no
        // cause the reader can see, and one they would reasonably take for a
        // failed drag.
        //
        // `is-active` is the sidebar's own mark for "this is the row you are
        // on", maintained by ui--sidebar on every navigation and put on the
        // .nav-item — which is the same element the drop attributes ride. The
        // active category tab needs no equivalent: the server renders it
        // without data-dnd-category at all.
        if (true === target.classList.contains("is-active")) {
            return false;
        }

        // An account-scoped folder row takes mail from that account only.
        // Labels themselves span accounts, but the FOLDER behind one does not:
        // dropping a conversation from account A onto account B's Receipts
        // would attach a label whose binding for A may not exist, and the mail
        // would be filed by a fallback rather than where it was dropped.
        const account = target.dataset.dndAccount;

        return undefined === account || true === this.#carrying.accounts.has(account);
    }

    /**
     * What the pointer carries while it travels.
     *
     * A single row drags its own element, which the browser snapshots for free
     * and which is the most legible possible answer to "what am I holding". A
     * multi-row drag cannot do that — there is no one element — so it gets a
     * built pill saying how many.
     *
     * The pill has to be IN the document when setDragImage is called, or the
     * browser has nothing to rasterise and the drag goes out with the default
     * ghost. It is put off-screen rather than hidden, because a display:none
     * element rasterises to nothing at all, and removed on the next frame —
     * by which point the snapshot has been taken.
     */
    #setDragImage(event, carried) {
        if (carried.ids.length < 2) {
            return;
        }

        const pill = document.createElement("div");

        pill.className = "dnd-drag-image";
        pill.textContent = this.draggingValue.replace("%count%", String(carried.ids.length));
        document.body.append(pill);

        event.dataTransfer.setDragImage(pill, 12, 12);

        requestAnimationFrame(() => pill.remove());
    }

    async #post(url, action, body) {
        // Holds the mail pane's own refresh of the list frame until this is
        // done, exactly as the toolbar does — without it the frame can reload
        // mid-request and replace the rows being acted on underneath it. The
        // release is also what re-reads the frame afterwards, which is how the
        // category tabs get their counts back in step after a drop.
        //
        // Dispatched on <body>, which is this controller's own element and is
        // where the layout binds mail--list-toolbar:writing/written — see
        // app.html.twig. Borrowing the toolbar's event names from elsewhere is
        // the established shape here rather than a liberty: mail--mail-pane and
        // mail--message-row both raise :row-changed the same way.
        this.element.dispatchEvent(new CustomEvent("mail--list-toolbar:writing", { bubbles: true }));

        try {
            const response = await fetch(url, {
                method: "POST",
                headers: jsonCsrfHeaders(),
                body: JSON.stringify(body),
            });

            if (false === response.ok) {
                console.error(`[dnd] ${action} failed`, response.status);

                return;
            }

            Turbo.renderStreamMessage(await response.text());
        } finally {
            this.element.dispatchEvent(new CustomEvent("mail--list-toolbar:written", { bubbles: true }));
            announceWrite();

            // The selection is spent. It was drawn from the checkboxes, the
            // rows it named are gone from the list, and leaving the boxes
            // ticked would leave the toolbar offering actions on conversations
            // that are no longer here.
            for (const box of document.querySelectorAll("[data-thread-select]:checked")) {
                box.checked = false;
            }

            document
                .querySelector("[data-controller~='mail--list-toolbar']")
                ?.dispatchEvent(new CustomEvent("mail--list-toolbar:row-changed", { bubbles: false }));
        }
    }

    #clearOver() {
        if (null !== this.#over) {
            delete this.#over.dataset[this.constructor.OVER_ATTRIBUTE];
            this.#over = null;
        }
    }

    #teardown() {
        this.#clearOver();
        this.#carrying = null;

        for (const target of document.querySelectorAll(`[data-dnd-refused]`)) {
            delete target.dataset[this.constructor.REFUSED_ATTRIBUTE];
        }

        delete document.documentElement.dataset[this.constructor.ACTIVE_ATTRIBUTE];
    }
}
