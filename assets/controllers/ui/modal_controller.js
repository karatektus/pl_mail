import { Controller } from "@hotwired/stimulus"
import { leave } from "../../motion.js"

/**
 * Modal controller
 *
 * Usage on a trigger button:
 *   <button
 *     data-controller="ui--modal"                        ← attach here, or use a parent
 *     data-action="click->ui--modal#open"
 *     data-ui--modal-src-value="/some/form/url"
 *     data-ui--modal-title-value="Edit settings"
 *   >Open</button>
 *
 * Or fire open() directly from Turbo events / other controllers.
 */
/**
 * The frame's own markup — the spinner — as it is before anything is loaded
 * into it.
 *
 * Module-level rather than per-controller because every trigger button in the
 * page mounts one of these, and they all drive the single body-level frame.
 * Re-captured on any page render that still has a pristine frame, so a locale
 * change does not leave a spinner labelled in the old language behind.
 */
let pristineFrame = null

/**
 * Where the keyboard goes back to when the dialog closes, and the watcher that
 * puts it into the dialog when its content lands.
 *
 * Module-level, for the same reason pristineFrame is and for a sharper one:
 * open() and close() are frequently not the same instance. Every trigger button
 * in the page mounts one of these controllers and #modal-backdrop mounts
 * another, so a dialog opened from a chip is closed by the backdrop's copy —
 * the X button and the backdrop click both resolve there. Per-instance state
 * would mean the opener remembered the trigger and the closer, asked to restore
 * it, had never heard of it.
 *
 * There is exactly one dialog, so one slot each is the right cardinality.
 */
let returnFocusTo = null
let pendingFocus = null

/**
 * Which close the pending exit animation belongs to.
 *
 * The dialog is on screen for the length of its fade-out, and it can be
 * REOPENED during it — click a chip, press Escape, click the next chip is an
 * ordinary sequence at speed. Without a token the first close's callback would
 * land a moment later and hide the dialog somebody had just opened, and the
 * only trace would be a modal that occasionally refuses to appear.
 *
 * Module-level for the same reason returnFocusTo is: the instance that opens
 * the dialog is almost never the instance that closes it.
 */
let closeToken = 0

export default class extends Controller {
    static values = {
        src:   String,   // URL to load into the turbo-frame
        title: String,   // Optional title shown in the modal header
        size:  String,   // Optional width preset — see SIZES
    }

    connect() {
        this._onSubmitEnd = this._handleSubmitEnd.bind(this);
        this.element.addEventListener("turbo:submit-end", this._onSubmitEnd);
        this._onKeydown = this._handleKeydown.bind(this)

        // Whether a backdrop dismissal is in progress — see backdropMousedown.
        this.armed = false

        const frame = this._frame

        if (frame?.querySelector("[data-ui--modal-loading]")) {
            pristineFrame = frame.innerHTML
        }
    }

    disconnect() {
        this.element.removeEventListener("turbo:submit-end", this._onSubmitEnd);
        document.removeEventListener("keydown", this._onKeydown)
    }

    // ── Public API ──────────────────────────────────────────────────────────────

    open(event) {
        event?.preventDefault()

        const frame  = this._frame
        const dialog = this._dialog

        if (!frame || !dialog) {
            console.warn("[modal] #modal turbo-frame or [data-ui--modal-dialog] not found in DOM")
            return
        }

        // Allow overriding src/title from the triggering element's data attributes
        const triggerSrc   = event?.currentTarget?.dataset?.modalSrcValue   ?? this.srcValue
        const triggerTitle = event?.currentTarget?.dataset?.modalTitleValue ?? this.titleValue

        // Update the frame title if present
        const titleEl = dialog.querySelector("[data-ui--modal-title]")
        if (titleEl && triggerTitle) titleEl.textContent = triggerTitle

        this._applySize(
            dialog,
            event?.currentTarget?.dataset?.modalSizeValue ?? this.sizeValue,
        )

        // Point the turbo-frame at the form URL and let Turbo do the fetch
        frame.src = triggerSrc

        // A dismissal half-completed against the previous dialog must not carry
        // into this one.
        this.armed = false

        // Where to put the keyboard back when this closes. The trigger is the
        // right answer and `document.activeElement` is the fallback, because
        // open() is also called from other controllers with no event at all.
        returnFocusTo = event?.currentTarget ?? document.activeElement

        // Show the modal. Any exit still playing is abandoned here — both its
        // callback (via the token) and the attribute that would otherwise hold
        // the freshly opened panel at the end of a fade-out, since the leave
        // animation fills forwards.
        closeToken++
        dialog.removeAttribute("data-leaving")
        dialog.removeAttribute("inert")
        dialog.removeAttribute("hidden")
        document.body.classList.add("overflow-hidden")
        document.addEventListener("keydown", this._onKeydown)

        this._focusWhenLoaded(frame)
    }

    close(event) {
        event?.preventDefault()

        const frame  = this._frame
        const dialog = this._dialog

        if (!dialog) return

        // Nothing to close, or a close already under way. Without this a second
        // Escape during the fade-out would start a second exit — and, once the
        // dialog is hidden, an exit on a display:none element, which never
        // fires `animationend` and would sit on the 400ms safety net.
        if (dialog.hasAttribute("hidden") || dialog.hasAttribute("data-leaving")) {
            return
        }

        document.removeEventListener("keydown", this._onKeydown)

        // A dialog that opened while this one was still waiting for its content
        // must not have focus yanked out from under it by the old watcher.
        pendingFocus?.disconnect()
        pendingFocus = null

        // `inert` before anything else, because a dialog that is leaving must
        // not be able to take the keyboard back — and one of them tried.
        //
        // The exit animation is the reason this is needed at all. Closing used
        // to hide the dialog in the same tick, and `display: none` makes a
        // focus() call on anything inside it a silent no-op; now the panel is
        // still on screen for the length of a fade, and anything that focuses
        // into it during those milliseconds succeeds. Turbo does exactly that:
        // a frame render calls focusFirstAutofocusableElement(), the event
        // editor's title field carries `autofocus`, and a render landing just
        // after Escape pulled the keyboard off the trigger and into a dialog
        // the user had already dismissed — then onto <body> when the frame was
        // cleared a moment later. The calendar's "returns focus to the trigger
        // on close" test is what caught it.
        //
        // `inert` is the keyboard half of what [data-leaving] already does with
        // pointer-events: this thing is on its way out, and it is not a target
        // for anything any more.
        dialog.setAttribute("inert", "")

        // Back where it came from, NOW — never behind the animation. Without
        // this the keyboard is left on <body> and the next Tab starts at the
        // top of the page; delayed by a fade, it would be worse than either,
        // because the keystroke someone types in that gap goes to the wrong
        // place. The dialog is `pointer-events: none` while it leaves, so
        // focusing something behind it is safe.
        //
        // Skipped when the trigger has left the document, which is the ordinary
        // case after a successful save: the page behind has been replaced, and
        // focusing a detached node silently focuses nothing.
        if (returnFocusTo?.isConnected) {
            returnFocusTo.focus()
        }

        // Kept for the length of the exit, for the guard in finish() below.
        const restoreTo = returnFocusTo

        returnFocusTo = null

        // Announced on document, not on this.element: whoever cares about a
        // dialog closing is rarely inside the dialog. The onboarding wizard
        // treats a close as "finished", since it only ever opens by itself once.
        // Dispatched immediately too — a dialog that has been dismissed has
        // been dismissed, whatever its pixels are still doing.
        this.dispatch("closed", { target: document })

        const token = ++closeToken

        // The one exit animation in the shared chrome besides the toast. A
        // dialog is most of the screen and its backdrop is all of it, so
        // removing both between two frames is the jump cut at its largest —
        // this is exactly the case motion.css keeps `leave()` for.
        leave(dialog, () => {
            // Reopened while this one was fading. Everything below belongs to a
            // dialog that is now on screen again, and doing it would empty it.
            if (token !== closeToken) return

            dialog.removeAttribute("data-leaving")
            dialog.removeAttribute("inert")
            dialog.setAttribute("hidden", "")

            // Held until the end on purpose: releasing the scroll lock returns
            // the page's scrollbar, and doing that under a backdrop that is
            // still visible shifts the whole page sideways while it fades.
            document.body.classList.remove("overflow-hidden")

            // Clear the frame so the next open always fetches fresh — and put
            // the spinner back, because clearing only the src left the CLOSED
            // dialog's form sitting in the frame. The next open then showed the
            // previous dialog until its own fetch landed, and anything typed
            // into that window was thrown away when the real form replaced it:
            // an edit form opened straight after a create one is pre-filled and
            // looks live, so the typing goes somewhere convincing and then
            // silently does not save.
            //
            // Also the reason this waits for the animation rather than running
            // with the rest: swapping the form back to the spinner while the
            // dialog is still on screen would show a spinner flashing inside a
            // dialog that is closing.
            if (frame) {
                frame.src = ""

                if (pristineFrame !== null) frame.innerHTML = pristineFrame
            }

            // Belt and braces. `inert` stops the dialog taking focus, but the
            // line above DELETES whatever is in the frame, and if anything at
            // all still held the keyboard in there the browser drops it on
            // <body> — where the next Tab starts at the top of the document.
            // Only ever a correction, never the mechanism: the focus call that
            // matters happened before the animation, above.
            if (document.body === document.activeElement && true === restoreTo?.isConnected) {
                restoreTo.focus()
            }
        })
    }

    /**
     * Arms a backdrop dismissal, and only when the gesture STARTS on the
     * backdrop.
     *
     * A click fires on the nearest common ancestor of where the button went
     * down and where it came up, so selecting text in a field and releasing
     * past the edge of the dialog produces a click whose target is the
     * backdrop — indistinguishable, to a click handler, from a click on the
     * backdrop. That is how a modal disappeared mid-sentence and took what had
     * been typed with it: the user had done nothing but drag over their own
     * text.
     *
     * mousedown is the half of the gesture that says what was meant. Nothing
     * here closes anything; it only records that the press began on the
     * backdrop, and backdropClick() closes only if it did.
     */
    backdropMousedown(event) {
        this.armed = event.target === event.currentTarget
    }

    /**
     * The other end of the same gesture: a press that began on the backdrop but
     * was released over the panel is a drag INTO the dialog, not a dismissal.
     * The click that follows still reports the backdrop — it is the common
     * ancestor of the two — so without this the release point would go unread.
     */
    backdropMouseup(event) {
        if (event.target !== event.currentTarget) this.armed = false
    }

    backdropClick(event) {
        const armed = this.armed

        // Disarmed unconditionally, including on the closing path: a stale
        // `true` would let the *next* gesture close the dialog without ever
        // having touched the backdrop.
        this.armed = false

        // Both halves, not either: the press has to have landed on the
        // backdrop itself rather than on the panel, and so does the release.
        if (armed && event.target === event.currentTarget) this.close(event)
    }

    // ── Private ─────────────────────────────────────────────────────────────────

    get _frame()  { return document.getElementById("modal") }
    get _dialog() { return document.querySelector("[data-ui--modal-dialog]") }

    _handleKeydown(event) {
        if (event.key === "Escape") {
            this.close(event)

            return
        }

        if (event.key === "Tab") this._trapTab(event)
    }

    /**
     * Keep Tab inside the dialog.
     *
     * `role="dialog"` and `aria-modal="true"` are already on the backdrop — see
     * _partials/_modal.html.twig — and on their own they are a promise the page
     * was not keeping. aria-modal tells a screen reader to ignore everything
     * outside this element; it does nothing whatever to the tab ring, so the
     * keyboard walked straight out of the dialog and into a page the same
     * attribute had just declared invisible. A sighted keyboard user lost the
     * focus ring behind the backdrop; a screen-reader user tabbed into content
     * their software refused to read.
     *
     * Here rather than in any one dialog, because the gap was here: every modal
     * in the application loads into this one shell and every one of them had it.
     *
     * Both ends are wrapped, and so is the case where focus has escaped
     * already — a click on the backdrop leaves it on <body>, and Tab from there
     * would otherwise start at the top of the document.
     */
    _trapTab(event) {
        const dialog = this._dialog

        if (!dialog || dialog.hasAttribute("hidden")) return

        const focusable = this._focusable(dialog)

        if (focusable.length === 0) return

        const first = focusable[0]
        const last = focusable[focusable.length - 1]
        const active = document.activeElement

        if (!dialog.contains(active)) {
            event.preventDefault()
            ;(event.shiftKey ? last : first).focus()

            return
        }

        if (event.shiftKey && active === first) {
            event.preventDefault()
            last.focus()
        } else if (!event.shiftKey && active === last) {
            event.preventDefault()
            first.focus()
        }
    }

    /**
     * Everything in `container` a Tab can land on, in document order.
     *
     * Filtered by whether the element is actually rendered, because the shell
     * keeps markup around that is not currently reachable — a `hidden` form, a
     * collapsed section — and a trap that wraps onto an invisible element sends
     * the keyboard somewhere the user cannot see. getClientRects() is the cheap
     * form of that question and answers empty for anything with display:none,
     * including a Tailwind `.hidden`.
     */
    _focusable(container) {
        return Array.from(
            container.querySelectorAll(
                'button:not([disabled]), [href], input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
            )
        ).filter((el) => el.getClientRects().length > 0)
    }

    /**
     * Move focus into the dialog once there is something in it to focus.
     *
     * It cannot be done in open(): the frame at that moment still holds the
     * spinner, which contains nothing focusable, and the real content arrives a
     * network round trip later.
     *
     * A MutationObserver on the frame rather than the `turbo:frame-load` event
     * this used to listen for. That event was not firing in time here and the
     * dialog opened with focus still on the trigger button behind it — the
     * frame is `loading="lazy"`, so its fetch is kicked off by an
     * IntersectionObserver once the dialog stops being hidden, and the ordering
     * between that and a listener attached in the same task is not something
     * the keyboard should depend on. Watching for the content itself asks the
     * question that actually matters: is there anything in there yet?
     *
     * `[autofocus]` wins where the loaded form names one — the event editor
     * puts it on the title field — and the first focusable is the fallback.
     */
    _focusWhenLoaded(frame) {
        pendingFocus?.disconnect()

        const move = () => {
            const fallback = this._focusable(frame)[0]

            // Still the spinner. Nothing to focus, nothing to disconnect.
            if (!fallback) return

            pendingFocus?.disconnect()
            pendingFocus = null

            const preferred = frame.querySelector("[autofocus]:not([disabled])")

            ;(preferred ?? fallback).focus()
        }

        pendingFocus = new MutationObserver(move)
        pendingFocus.observe(frame, { childList: true, subtree: true })

        // Already loaded — a frame reopened without being cleared — in which
        // case no mutation is coming and the observer would wait forever.
        move()
    }

    /**
     * Swap the panel's width class.
     *
     * Presets come from the panel's own data-ui--modal-sizes so the class names live
     * in the template, where Tailwind is guaranteed to see them — a width
     * referenced only from here could be purged from the build.
     *
     * Every preset is removed before the chosen one is added: the shell is
     * reused for every dialog, so a wide picker would otherwise leave the next
     * label form stretched across the screen.
     */
    _applySize(dialog, size) {
        const panel = dialog.querySelector("[data-ui--modal-panel]")

        if (!panel) {
            return
        }

        let sizes

        try {
            sizes = JSON.parse(panel.getAttribute("data-ui--modal-sizes") ?? "{}")
        } catch (_) {
            return
        }

        const chosen = sizes[size] ?? sizes.form

        if (undefined === chosen) {
            return
        }

        panel.classList.remove(...Object.values(sizes))
        panel.classList.add(chosen)
    }

    _handleSubmitEnd(event) {
        // Some forms inside a modal are navigation, not completion — the
        // integration picker's search box re-renders the frame and must stay
        // open. Closing on every successful submit made searching look like a
        // cancel.
        if (event.target?.closest?.("[data-ui--modal-keep-open]")) {
            return;
        }

        if (event.detail.success) {
            this.close();
        }
    }
}
