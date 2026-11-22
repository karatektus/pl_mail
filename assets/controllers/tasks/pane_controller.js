import { Controller } from "@hotwired/stimulus";
import * as Turbo from "@hotwired/turbo";
import { registerPane } from "../../panes.js";

/*
 * The tasks docked beside the mail: whether they are open, and what that asks of
 * the other two panes.
 *
 * On the shell, beside ui--split and contacts--pane, because the topbar's button
 * and the boundaries it has to ask are all there. The dock's own resizing is a
 * ui--side-split on the dock — see _tasks_pane.html.twig.
 *
 * ── Sharing the row ───────────────────────────────────────────────────────
 *
 * The same rule as the address book. From WIDE up the row holds the mail, the
 * calendar and one more pane; below it, one pane beside the mail, so opening
 * this asks the calendar to `yield`. The address book and the tasks take turns
 * at every width, because three panes beside a mail column leave none of them
 * usable. app.css enforces the calendar half as well, for a first paint and for
 * a window made narrower. Below lg there is no dock, and the button is the link
 * to /tasks it looks like.
 *
 * Values:
 *   open      Boolean  docked now
 *   page      Boolean  the /tasks page itself, where closing means going to the mail
 *   src       String   what the frame loads the first time it is shown
 *   stateUrl  String   POST open / width
 *   token     String   CSRF for stateUrl
 *   inboxUrl  String   where closing goes from the page
 *
 * Targets:
 *   dock      the wrapper from _tasks_pane.html.twig
 *   toggle    the topbar button
 */
export default class extends Controller {
    static targets = ["dock", "toggle"];
    static values = {
        open: Boolean,
        page: Boolean,
        src: String,
        stateUrl: String,
        token: String,
        inboxUrl: String,
    };

    /** Where the row starts to hold two panes. Matches app.css. */
    static WIDE = "(min-width: 1920px)";

    connect() {
        // The topbar's first-paint accent — see the button in _topbar.html.twig.
        this.toggleTargets.forEach((toggle) => toggle.classList.remove("lg:bg-accent-soft", "lg:text-accent"));

        // A list loaded into the mail column is somebody going to the mail, as
        // contacts--pane hears it. From the full page that is the pane drawing
        // back beside it, with what was in it.
        this._onFrameLoad = (event) => {
            if ("inbox-list-frame" === event.target.id && this._isFull()) {
                this.element.removeAttribute("data-tasks-full");
                this.pageValue = false;
                this._set(false === this._isNarrow());
            }
        };
        document.addEventListener("turbo:frame-load", this._onFrameLoad);

        // A task's tick or a save answers with the whole pane, whose list would
        // start from the top again, away from the row it was about.
        this._onBeforeFrameRender = (event) => {
            if ("tasks-pane-frame" !== event.target.id) {
                return;
            }

            const from = event.target.querySelector("[data-tasks-pane-scroll]");
            const to = event.detail.newFrame.querySelector("[data-tasks-pane-scroll]");

            this._keptScroll = from?.dataset.tasksPaneScroll === to?.dataset.tasksPaneScroll ? from?.scrollTop : null;
        };
        document.addEventListener("turbo:before-frame-render", this._onBeforeFrameRender);

        // A frame later, once tasks--row has folded its rows back out: the list
        // is too short for this position until then.
        this._onFrameRender = (event) => {
            if ("tasks-pane-frame" !== event.target.id || !this._keptScroll) {
                return;
            }

            const top = this._keptScroll;

            requestAnimationFrame(() => {
                const scroller = event.target.querySelector("[data-tasks-pane-scroll]");

                if (scroller) {
                    scroller.scrollTop = top;
                }
            });
        };
        document.addEventListener("turbo:frame-render", this._onFrameRender);

        this._unregister = registerPane("tasks", {
            available: () => this.hasDockTarget && (this._isFull() || false === this._isNarrow()),
            isOpen: () => this._isFull() || true === this.openValue,
            open: ({ src } = {}) => this.show(src),
            close: () => this._set(false),
        });

        // Remembered open from a wider screen, beside a calendar this one shows:
        // the calendar keeps the room, as app.css already drew it. Not written
        // back — a smaller window is not somebody closing the pane.
        if (true === this.openValue && false === this._isFull() && false === this._isWide() && true === this._calendarShowing()) {
            this._render(false);

            return;
        }

        this._render(this._isFull() || this.openValue);
    }

    disconnect() {
        document.removeEventListener("turbo:frame-load", this._onFrameLoad);
        document.removeEventListener("turbo:before-frame-render", this._onBeforeFrameRender);
        document.removeEventListener("turbo:frame-render", this._onFrameRender);
        this._unregister?.();
    }

    /**
     * On screen, showing src if given. Asked for the page it already shows, it
     * reloads: a change was just saved, which is why it is being shown.
     *
     * @param {string} [src]
     */
    show(src) {
        const frame = this.hasDockTarget ? this.dockTarget.querySelector("turbo-frame#tasks-pane-frame") : null;

        if (frame && src) {
            if (new URL(src, location.href).href === frame.src) {
                frame.reload();
            } else {
                frame.src = src;
            }
        }

        if (false === this._isFull() && true !== this.openValue) {
            this._set(true);
        }
    }

    /**
     * The topbar button. Below lg it is the link to /tasks it looks like, and so
     * is a middle click or a modified one anywhere.
     */
    toggle(event) {
        if (true === this._isNarrow() || event.metaKey || event.ctrlKey || event.shiftKey || 1 === event.button) {
            return;
        }

        event.preventDefault();

        if (this.pageValue) {
            Turbo.visit(this.inboxUrlValue);

            return;
        }

        this._set(true !== this.openValue);
    }

    /** The pane's own ×. On the page there is nothing behind it but the mail. */
    close(event) {
        event?.preventDefault();

        if (this.pageValue) {
            Turbo.visit(this.inboxUrlValue);

            return;
        }

        this._set(false);
    }

    /** The calendar moved — see ui--split's `mode` event. */
    calendarMoved(event) {
        if (true === this.openValue && false === this._isFull() && "mail" !== event.detail?.mode && false === this._isWide()) {
            this._set(false);
        }
    }

    /** The address book moved — see contacts--pane's `moved` event. */
    contactsMoved(event) {
        if (true === this.openValue && false === this._isFull() && "mail" !== event.detail?.mode) {
            this._set(false);
        }
    }

    // ── Private ───────────────────────────────────────────────────────────

    _set(open) {
        if (open) {
            // Below WIDE the calendar gives up its place; the address book at
            // every width, unless it has the row and there is no mail to share.
            if (false === this._isWide()) {
                this.dispatch("claim");
            }

            this.dispatch("opened");
        }

        this._render(open);
        this._persist(open);
        window.dispatchEvent(new CustomEvent("side-pane:changed"));
    }

    _render(open) {
        this.openValue = open;

        const docked = open && false === this._isNarrow();

        this.toggleTargets.forEach((toggle) => {
            toggle.classList.toggle("bg-accent-soft", docked);
            toggle.classList.toggle("text-accent", docked);
            toggle.classList.toggle("text-ink-muted", !docked);
            toggle.setAttribute("aria-pressed", docked ? "true" : "false");
        });

        if (false === this.hasDockTarget) {
            return;
        }

        // lg:flex, never flex: below lg there is no dock, open or not. The page
        // gives it the row at every width through app.css instead.
        this.dockTarget.classList.add("hidden");
        this.dockTarget.classList.toggle("lg:flex", open);
        this.dockTarget.classList.toggle("lg:w-auto", open);

        // Lazy until first shown, as the calendar's frame is; a pane rendered
        // open with the page already has its content and needs no src.
        const frame = this.dockTarget.querySelector("turbo-frame#tasks-pane-frame");

        if (true === open && frame && !frame.getAttribute("src") && null === frame.querySelector("[data-tasks-page]")) {
            frame.setAttribute("src", this.srcValue);
        }
    }

    _isFull() {
        return this.element.hasAttribute("data-tasks-full");
    }

    _calendarShowing() {
        return "mail" !== (this.element.dataset.calendarMode ?? "mail");
    }

    _isWide() {
        return window.matchMedia(this.constructor.WIDE).matches;
    }

    _isNarrow() {
        return window.matchMedia("(max-width: 1023px)").matches;
    }

    /** Best-effort, as ui--split's is: a lost write costs the memory, not the layout. */
    _persist(open) {
        if (!this.hasStateUrlValue) {
            return;
        }

        const body = new FormData();
        body.append("_token", this.tokenValue);
        body.append("open", open ? "1" : "0");

        fetch(this.stateUrlValue, {
            method: "POST",
            body,
            headers: { "X-Requested-With": "fetch" },
            keepalive: true,
        }).catch(() => {});
    }
}
