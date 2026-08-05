import { Controller } from "@hotwired/stimulus";

/**
 * Moving and resizing events on the calendar's time-grid.
 *
 * Values:
 *   snap               minutes a change is rounded to (default 15)
 *   readOnlyMessage    what a block on a mirror that takes no writes says back
 *   pendingMessage     announced after every keyboard nudge; %start% and %end%
 *   revertedMessage    announced when a pending change is abandoned
 *
 * Targets:
 *   scroller        the element the hours scroll inside
 *   column          one per day, in view order — the index IS the day offset
 *   block           one positioned event; carries all of its own state in data-
 *   grip            the bottom edge of a block, for resizing
 *   status          role="status", where every announcement goes
 *   scopePrompt     <dialog>, the this-occurrence-or-the-series question
 *   form            the hidden POST to app_calendar_event_move
 *   eventIdField    \
 *   instanceField    |
 *   scopeField       > that form's inputs, filled just before it is submitted
 *   startsAtField    |
 *   endsAtField     /
 *
 * **All arithmetic here is on wall clocks, never on instants.** A block carries
 * its times as `YYYY-MM-DDTHH:MM:SS` with no zone, on the clock the server drew
 * the grid against, and every calculation below parses those into minutes via
 * Date.UTC and formats them back the same way. Using the browser's local Date
 * would convert twice — into the viewer's zone and out of it — and a viewer
 * reading a calendar pinned to another zone would drag an event to a time an
 * hour from where they dropped it, only sometimes, and only for half the year.
 *
 * **A change is proposed, never applied.** Nothing here writes: the drag ends
 * by filling a real <form> and submitting it, so the answer comes back through
 * Turbo as an ordinary navigation and the grid is redrawn by the server with
 * the placements it computed. The preview is a CSS transform that is thrown
 * away either way. That is why a failed request cannot leave a block sitting
 * somewhere the database disagrees with.
 *
 * **Dragging is not the only way.** Alt with the arrow keys moves a focused
 * block, Alt+Shift changes how long it lasts, Enter commits and Escape puts it
 * back. The keyboard path builds the same pending change the pointer path does
 * and commits it through the same method, so the two cannot drift — including
 * through the recurring-event question, which both must ask.
 */
export default class extends Controller {
    static targets = [
        "scroller",
        "column",
        "block",
        "grip",
        "status",
        "scopePrompt",
        "form",
        "eventIdField",
        "instanceField",
        "scopeField",
        "startsAtField",
        "endsAtField",
    ];

    static values = {
        snap: { type: Number, default: 15 },
        readOnlyMessage: String,
        pendingMessage: String,
        revertedMessage: String,
    };

    /** Minutes in a day. The columns are 24 rows tall, so this is the axis. */
    static DAY = 1440;

    /** Pointer travel, in px, below which a drag was really a click. */
    static SLOP = 4;

    connect() {
        this._onMove = this.#onMove.bind(this);
        this._onUp = this.#onUp.bind(this);
        this._onClickCapture = this.#onClickCapture.bind(this);

        // Capture, on the container: a chip is a <button> whose own click
        // action opens the editor, and a bubble-phase listener would run after
        // it. Without this, every drag ended by opening the dialog for the
        // event that had just been moved.
        this.element.addEventListener("click", this._onClickCapture, true);

        // One hint element for all of them (see the template) — pointed at from
        // here rather than written into the chip partial, which is shared with
        // three views that have no keyboard moving to describe.
        this.blockTargets.forEach((block) => {
            block.querySelector("button")?.setAttribute("aria-describedby", "calendar-grid-hint");
        });

        // Midnight is almost never what someone wants to look at. Opening on
        // the working day costs one scroll to reach the night, where opening at
        // the top costs one to reach everything.
        if (this.hasScrollerTarget) {
            this.scrollerTarget.scrollTop = (this.scrollerTarget.scrollHeight * 7) / 24;
        }
    }

    disconnect() {
        this.element.removeEventListener("click", this._onClickCapture, true);
        this.#stopListening();
    }

    // ── Pointer ───────────────────────────────────────────────────────────

    pointerdown(event) {
        // Left button only. A right-click is a context menu and a middle-click
        // is a paste on some platforms; neither is a drag.
        if (0 !== event.button) {
            return;
        }

        // Touch is deliberately left alone. A finger starting on a block is far
        // more often the beginning of a scroll than of a move, and the only way
        // to claim the gesture is `touch-action: none`, which would make the
        // blocks dead spots the grid cannot be scrolled from. Tapping still
        // opens the editor, which is where a touch user moves an event.
        if ("touch" === event.pointerType) {
            return;
        }

        const block = event.target.closest('[data-calendar--time-grid-target="block"]');

        if (null === block) {
            return;
        }

        if ("true" === block.dataset.readOnly) {
            // Said, not swallowed. A block that simply refused to move would be
            // indistinguishable from a broken drag, and the user would try
            // again. The lock badge says the same thing before they start.
            this.#announce(this.readOnlyMessageValue);

            return;
        }

        const resizing = null !== event.target.closest('[data-calendar--time-grid-target="grip"]');
        const geometry = this.#geometryOf(block);

        if (null === geometry) {
            return;
        }

        this._drag = {
            block,
            resizing,
            geometry,
            startX: event.clientX,
            startY: event.clientY,
            savedHeight: block.style.height,
            minuteDelta: 0,
            dayDelta: 0,
            moved: false,
        };

        // setPointerCapture is deliberately NOT called, and that is the one
        // non-obvious thing in this method. It would be redundant — the moves
        // are listened for on `window`, so the drag already survives the
        // pointer leaving the block — and it is actively harmful: a captured
        // pointer retargets the click the browser fires afterwards onto the
        // capture element, so a plain click landed on this wrapper instead of
        // on the chip inside it and stopped opening the editor. It also hid
        // #onClickCapture, which is what actually keeps a drag from opening one.
        window.addEventListener("pointermove", this._onMove);
        window.addEventListener("pointerup", this._onUp);
        window.addEventListener("pointercancel", this._onUp);
    }

    // ── Keyboard ──────────────────────────────────────────────────────────

    keydown(event) {
        const block = event.target.closest('[data-calendar--time-grid-target="block"]');

        if (null === block) {
            return;
        }

        if (undefined !== this._keyboard && this._keyboard.block === block) {
            if ("Escape" === event.key) {
                event.preventDefault();
                this.#revert();
                this.#announce(this.revertedMessageValue);

                return;
            }

            // Enter would otherwise reach the chip's own click action and open
            // the editor, discarding the change the user has just built up.
            if ("Enter" === event.key || " " === event.key) {
                event.preventDefault();
                this.#propose(block, this._keyboard.startsAt, this._keyboard.endsAt);

                return;
            }
        }

        if (true !== event.altKey) {
            return;
        }

        const step = { ArrowUp: -1, ArrowDown: 1, ArrowLeft: -1, ArrowRight: 1 }[event.key];

        if (undefined === step) {
            return;
        }

        event.preventDefault();

        if ("true" === block.dataset.readOnly) {
            this.#announce(this.readOnlyMessageValue);

            return;
        }

        const vertical = "ArrowUp" === event.key || "ArrowDown" === event.key;

        if (undefined === this._keyboard || this._keyboard.block !== block) {
            this.#revert();
            this._keyboard = {
                block,
                geometry: this.#geometryOf(block),
                savedHeight: block.style.height,
                minuteDelta: 0,
                dayDelta: 0,
                resizing: false,
            };
        }

        // Shift means "the end of it", not "faster". A modifier that only
        // changed the step size would leave the duration unreachable from the
        // keyboard, and the resize grip is deliberately mouse-only.
        if (true === event.shiftKey) {
            if (true === vertical) {
                this._keyboard.resizing = true;
                this._keyboard.minuteDelta += step * this.snapValue;
            }
        } else if (true === vertical) {
            this._keyboard.resizing = false;
            this._keyboard.minuteDelta += step * this.snapValue;
        } else {
            this._keyboard.resizing = false;
            this._keyboard.dayDelta += step;
        }

        const proposed = this.#proposedTimes(this._keyboard);

        this._keyboard.startsAt = proposed.startsAt;
        this._keyboard.endsAt = proposed.endsAt;

        this.#preview(this._keyboard);
        this.#announce(
            this.pendingMessageValue
                .replace("%start%", this.#readable(proposed.startsAt))
                .replace("%end%", this.#readable(proposed.endsAt)),
        );
    }

    // ── The question, and the answer ──────────────────────────────────────

    commitInstance() {
        this.#submit("instance");
    }

    commitSeries() {
        this.#submit("series");
    }

    /**
     * Absent, this abandons whatever is pending — the dialog's Cancel button
     * and its `cancel` event (Escape) both land here, and neither carries
     * anything worth reading off the event.
     */
    cancelPending() {
        if (true === this.hasScopePromptTarget && true === this.scopePromptTarget.open) {
            this.scopePromptTarget.close();
        }

        this.#revert();
        this.#announce(this.revertedMessageValue);
    }

    // ── Private ───────────────────────────────────────────────────────────

    #onMove(event) {
        if (undefined === this._drag) {
            return;
        }

        const travel = Math.hypot(event.clientX - this._drag.startX, event.clientY - this._drag.startY);

        if (false === this._drag.moved && travel < this.constructor.SLOP) {
            return;
        }

        if (false === this._drag.moved) {
            this._drag.moved = true;

            // Only once the drag is real, so a plain click on a chip does not
            // flicker the selection off and on. Without it, dragging across the
            // grid selects every title it passes over.
            document.body.classList.add("select-none");
        }

        const height = this._drag.geometry.height;

        this._drag.minuteDelta = this.#snap(
            ((event.clientY - this._drag.startY) / height) * this.constructor.DAY,
        );

        // A resize moves one edge, so it stays in the column it started in;
        // there is no such thing as an event whose end is on another day than
        // the one its block is drawn in.
        this._drag.dayDelta = true === this._drag.resizing
            ? 0
            : this.#columnAt(event.clientX) - this._drag.geometry.index;

        const proposed = this.#proposedTimes(this._drag);

        this._drag.startsAt = proposed.startsAt;
        this._drag.endsAt = proposed.endsAt;

        this.#preview(this._drag);
    }

    #onUp() {
        const drag = this._drag;

        this.#stopListening();
        document.body.classList.remove("select-none");

        if (undefined === drag) {
            return;
        }

        this._drag = undefined;

        if (false === drag.moved) {
            this.#reset(drag);

            return;
        }

        // The browser fires a click after the pointer sequence, and it is aimed
        // at the chip inside the block — the one whose action opens the editor.
        this._suppressClick = true;

        this._keyboard = { ...drag };
        this.#propose(drag.block, drag.startsAt, drag.endsAt);
    }

    #onClickCapture(event) {
        if (true !== this._suppressClick) {
            return;
        }

        this._suppressClick = false;

        event.preventDefault();
        event.stopPropagation();
    }

    #stopListening() {
        window.removeEventListener("pointermove", this._onMove);
        window.removeEventListener("pointerup", this._onUp);
        window.removeEventListener("pointercancel", this._onUp);
    }

    /**
     * Either commit, or ask first.
     *
     * The question is the editor's, in the editor's words, and it is asked for
     * the same reason: one occurrence of a series and the series itself are two
     * different changes, and guessing between them silently rewrites a weekly
     * meeting for everyone it repeats for. Only a recurring event that the grid
     * can name an occurrence of gets asked — anything else has one instance and
     * so no question to answer.
     */
    #propose(block, startsAt, endsAt) {
        if (undefined === startsAt || undefined === endsAt) {
            return;
        }

        this._pending = { block, startsAt, endsAt };

        const recurring = "true" === block.dataset.recurring && "" !== (block.dataset.instance ?? "");

        if (true === recurring && true === this.hasScopePromptTarget) {
            this.scopePromptTarget.showModal();

            return;
        }

        this.#submit("series");
    }

    #submit(scope) {
        const pending = this._pending;

        if (undefined === pending || false === this.hasFormTarget) {
            return;
        }

        if (true === this.hasScopePromptTarget && true === this.scopePromptTarget.open) {
            this.scopePromptTarget.close();
        }

        this.eventIdFieldTarget.value = pending.block.dataset.eventId ?? "";
        this.instanceFieldTarget.value = pending.block.dataset.instance ?? "";
        this.scopeFieldTarget.value = scope;
        this.startsAtFieldTarget.value = pending.startsAt;
        this.endsAtFieldTarget.value = pending.endsAt;

        this._pending = undefined;
        this._keyboard = undefined;

        this.formTarget.requestSubmit();
    }

    /** Put the block back where the server drew it, and forget the change. */
    #revert() {
        if (undefined !== this._keyboard) {
            this.#reset(this._keyboard);
            this._keyboard = undefined;
        }

        this._pending = undefined;
    }

    #reset(state) {
        state.block.style.transform = "";
        state.block.style.zIndex = "";
        state.block.style.height = state.savedHeight ?? "";
    }

    #preview(state) {
        const { width, height } = state.geometry;

        if (true === state.resizing) {
            const minutes = this.#minutesOf(state.block.dataset.endsAt)
                + state.minuteDelta
                - this.#minutesOf(state.block.dataset.startsAt);

            state.block.style.height = `${(Math.max(this.snapValue, minutes) / this.constructor.DAY) * height}px`;
        } else {
            const x = state.dayDelta * width;
            const y = (state.minuteDelta / this.constructor.DAY) * height;

            state.block.style.transform = `translate(${x}px, ${y}px)`;
        }

        // Over its neighbours while it is being held, or a block dragged onto a
        // busier lane disappears behind the ones it is being dropped between.
        state.block.style.zIndex = "30";
    }

    /**
     * What the block's times become, given the deltas built up so far.
     *
     * A move takes both ends; a resize takes only the end, and is floored at
     * one snap so an event cannot be dragged inside out.
     */
    #proposedTimes(state) {
        const startsAt = this.#minutesOf(state.block.dataset.startsAt);
        const endsAt = this.#minutesOf(state.block.dataset.endsAt);

        if (true === state.resizing) {
            return {
                startsAt: this.#stamp(startsAt),
                endsAt: this.#stamp(Math.max(startsAt + this.snapValue, endsAt + state.minuteDelta)),
            };
        }

        const shift = state.dayDelta * this.constructor.DAY + state.minuteDelta;

        return {
            startsAt: this.#stamp(startsAt + shift),
            endsAt: this.#stamp(endsAt + shift),
        };
    }

    /** Which day column a page X coordinate is over, clamped to the ends. */
    #columnAt(clientX) {
        const columns = this.columnTargets;

        for (let index = 0; index < columns.length; index++) {
            const box = columns[index].getBoundingClientRect();

            if (clientX >= box.left && clientX < box.right) {
                return index;
            }
        }

        return clientX < columns[0].getBoundingClientRect().left ? 0 : columns.length - 1;
    }

    #geometryOf(block) {
        const column = block.closest('[data-calendar--time-grid-target="column"]');

        if (null === column) {
            return null;
        }

        const box = column.getBoundingClientRect();

        return {
            index: this.columnTargets.indexOf(column),
            width: box.width,
            height: box.height,
        };
    }

    #snap(minutes) {
        return Math.round(minutes / this.snapValue) * this.snapValue;
    }

    /**
     * `YYYY-MM-DDTHH:MM:SS` into minutes, and back — through Date.UTC, which is
     * the only Date arithmetic in this file that is not a bug. Treating a wall
     * clock as UTC makes day and minute arithmetic exact and leaves the value
     * zone-free; the server reads the answer back on the grid's own clock.
     */
    #minutesOf(text) {
        const [date, time] = String(text ?? "").split("T");
        const [year, month, day] = date.split("-").map(Number);
        const [hour, minute] = (time ?? "00:00").split(":").map(Number);

        return Date.UTC(year, month - 1, day, hour, minute) / 60000;
    }

    #stamp(minutes) {
        const when = new Date(minutes * 60000);
        const pad = (part) => String(part).padStart(2, "0");

        return [
            `${when.getUTCFullYear()}-${pad(when.getUTCMonth() + 1)}-${pad(when.getUTCDate())}`,
            `${pad(when.getUTCHours())}:${pad(when.getUTCMinutes())}:00`,
        ].join("T");
    }

    /**
     * The same wall clock, said out loud. Not toLocaleString: that would render
     * the digits in the VIEWER's zone, and these digits are already on the
     * calendar's — announcing a converted time beside a block that did not move
     * there is worse than announcing a plain one.
     */
    #readable(stamp) {
        return stamp.replace("T", " ").slice(0, 16);
    }

    #announce(message) {
        if (true === this.hasStatusTarget) {
            this.statusTarget.textContent = message;
        }
    }
}
