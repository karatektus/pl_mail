import { Controller } from "@hotwired/stimulus";

/**
 * The three things a repair button has to do that Turbo will not do for it.
 *
 * ── What Turbo already does, and why this file is small ──────────────────────
 * Every POST repair on the health page carries `data-turbo-submits-with`. Turbo
 * Drive intercepts the submission, disables the submitter and swaps its label
 * for the duration, then restores both on `turbo:submit-end` — including when
 * the request fails, which is the case a hand-written controller usually gets
 * wrong. That is the whole of requirement one for four of the five controls, in
 * an attribute, using the framework's own idiom, so none of it is reimplemented
 * here. What is left is the three cases the attribute cannot reach:
 *
 *   1. THE RECONNECT LINK. It is an `<a data-turbo="false">` because it leaves
 *      the app for the provider's consent screen — there is no form submission
 *      for Turbo to instrument, and the browser gives no feedback at all
 *      between the click and the provider's page painting, which on a cold
 *      OAuth redirect is seconds. So it gets its pending state here, and a
 *      guard so a second click during that gap cannot start a second round trip.
 *
 *   2. THE RESTORE ON RETURN. A link the browser is still navigating away from
 *      is frozen mid-state, and both routes back land on that frozen copy: the
 *      back button restores Turbo's cached snapshot, and the OAuth provider's
 *      own back button restores the bfcache. Either way the user is looking at
 *      a control that says "Taking you to Google…" and refuses to be clicked.
 *      `turbo:before-cache` and `pageshow` undo it.
 *
 *   3. THE ANSWER FROM THE WORKER. See _onSyncFinished.
 *
 * ── On strings ───────────────────────────────────────────────────────────────
 * Every word this controller writes arrives in `i18nValue`, translated in Twig.
 * The `?? fallback` is a guard against a missing key, not an English default
 * with a translation bolted beside it — nothing here reads as a sentence.
 */

/** How long to keep saying "started" before offering the button back. */
const AWAIT_TIMEOUT_MS = 90000;

export default class extends Controller {
    static targets = ["leaving", "awaiting", "awaitingLabel", "retry"];
    static values = {
        i18n: { type: Object, default: {} },
    };

    connect() {
        // Both restore paths, because they are genuinely different events and
        // only one of them fires per return. turbo:before-cache runs while this
        // page is still alive and is what the Turbo-cached snapshot is taken
        // from; pageshow with persisted=true is the browser's own bfcache
        // handing back a document that never ran connect() again.
        this._onBeforeCache = () => this._releaseAll();
        this._onPageShow = (event) => {
            if (event.persisted) {
                this._releaseAll();
            }
        };

        document.addEventListener("turbo:before-cache", this._onBeforeCache);
        window.addEventListener("pageshow", this._onPageShow);

        this._onSyncFinishedEvent = (event) => this._onSyncFinished(event.detail);
        document.addEventListener(
            "core--mercure:calendar-sync-finished",
            this._onSyncFinishedEvent,
        );

        this._armAwaitTimeouts();
    }

    disconnect() {
        document.removeEventListener("turbo:before-cache", this._onBeforeCache);
        window.removeEventListener("pageshow", this._onPageShow);
        document.removeEventListener(
            "core--mercure:calendar-sync-finished",
            this._onSyncFinishedEvent,
        );

        this._clearAwaitTimeouts();
    }

    /**
     * The reconnect link, pressed.
     *
     * Not preventDefault'd — the navigation is the point, and swallowing it to
     * re-trigger it by hand would break middle-click, ctrl-click and "open in
     * new tab", all of which reach this handler and none of which leave this
     * page. Those are exactly the cases where a pending state would be a lie,
     * so they are detected and left alone.
     */
    leave(event) {
        if (
            event.metaKey ||
            event.ctrlKey ||
            event.shiftKey ||
            event.altKey ||
            event.button === 1
        ) {
            return;
        }

        const link = event.currentTarget;

        if (link.dataset.leaving === "true") {
            // Already on its way. Swallowing this one is the guard: a second
            // navigation to the provider restarts the consent round trip and
            // can land the user back with a state parameter the first one has
            // already invalidated.
            event.preventDefault();

            return;
        }

        link.dataset.leaving = "true";
        link.dataset.originalLabel = link.innerHTML;
        // aria-disabled rather than the disabled attribute, which does not
        // exist on an anchor. Paired with pointer-events-none so the pointer
        // cannot reach it either — neither alone is enough, since the attribute
        // does not stop a click and the class does not stop the keyboard.
        link.setAttribute("aria-disabled", "true");
        link.classList.add("pointer-events-none", "opacity-60");

        // The link's own label first: it names the provider it is about to send
        // you to, which the section-wide fallback cannot, because a repair for
        // an account with no recognised provider has no brand to name.
        const label = link.dataset.healthPendingLabel || this._t("leaving", "…");

        // Only the text node is replaced, so the icon that says this leaves the
        // app survives — it is the part of the label that is still true.
        const text = link.querySelector("[data-health-leave-label]");

        if (text) {
            text.textContent = label;
        } else {
            link.textContent = label;
        }
    }

    /**
     * The worker reported back on a calendar this page is waiting for.
     *
     * This is the honest end of the "Try syncing now" story. The button only
     * ever DISPATCHED a message; the card has been saying "started" since the
     * redirect, and this is the first moment anything knows whether it worked.
     *
     * A repeat failure is treated as the more important of the two outcomes and
     * gets the louder treatment: the card says so in its own words and offers
     * the button back, rather than quietly reverting to the sentence it showed
     * before — which would be indistinguishable from the press never having
     * happened, and is precisely how somebody presses it four more times.
     */
    _onSyncFinished(detail) {
        const card = this._awaitingCardFor(detail?.calendarId);

        if (!card) {
            return;
        }

        this._clearTimeoutFor(card);

        if (detail?.ok === true) {
            this._settle(card, this._t("synced", ""), "ok");

            return;
        }

        this._settle(card, this._t("failedAgain", ""), "failed");
        this._offerRetry(card);

        // The provider's new words, where the old ones were. A repeat failure
        // is very often a DIFFERENT failure, and leaving the previous message
        // behind the disclosure would have the page explaining this failure
        // with the last one's reason.
        //
        // Reached from the enclosing CARD, not from the waiting line: the
        // disclosure is a sibling of the repairs block, so querying inside the
        // waiting element finds nothing and silently leaves the stale message
        // on screen. Scoping to `[data-health-issue]` also keeps a page with
        // several broken calendars from rewriting the wrong card's reason.
        const detailPre = card
            .closest("[data-health-issue]")
            ?.querySelector("[data-health-detail]");

        if (detailPre && typeof detail?.error === "string" && detail.error !== "") {
            detailPre.textContent = detail.error;
        }
    }

    /**
     * Nothing came back, and it has been long enough to say so.
     *
     * The requirement is that a control is never left stuck, and a card waiting
     * on a message that will never arrive is exactly that: a hub that is down,
     * a worker that is not running, or a tab opened after the event was
     * published all end here. Rather than guess at what happened, the card
     * stops claiming to be waiting and hands the button back — the truthful
     * answer is that the sync was started and nothing has been heard, and the
     * only useful thing to offer alongside it is another try.
     */
    _armAwaitTimeouts() {
        this._timeouts = new Map();

        this.awaitingTargets.forEach((card) => {
            this._timeouts.set(
                card,
                setTimeout(() => {
                    this._settle(card, this._t("stillWaiting", ""), "stalled");
                    this._offerRetry(card);
                }, AWAIT_TIMEOUT_MS),
            );
        });
    }

    _clearAwaitTimeouts() {
        this._timeouts?.forEach((id) => clearTimeout(id));
        this._timeouts?.clear();
    }

    _clearTimeoutFor(card) {
        const id = this._timeouts?.get(card);

        if (id !== undefined) {
            clearTimeout(id);
            this._timeouts.delete(card);
        }
    }

    /** The waiting card for this calendar id, if this page has one. */
    _awaitingCardFor(calendarId) {
        if (calendarId === undefined || calendarId === null) {
            return null;
        }

        return this.awaitingTargets.find(
            (card) => card.dataset.healthCalendarId === String(calendarId),
        );
    }

    /**
     * Replace the "started" line with what actually happened.
     *
     * `data-health-outcome` carries the outcome for styling and for the specs;
     * the spinner goes because the thing it represented has stopped.
     */
    _settle(card, text, outcome) {
        card.dataset.healthOutcome = outcome;
        card.querySelector("[data-health-spinner]")?.remove();

        const label = card.querySelector("[data-health-awaiting-label]");

        if (label && text !== "") {
            label.textContent = text;
        }
    }

    /**
     * Put the repair button back where the waiting line is.
     *
     * The markup for it is rendered server-side and hidden, rather than built
     * here: it carries a CSRF token and a translated label, and a controller
     * that composed either of those in JavaScript would be composing a token it
     * cannot mint and a string it must not hardcode.
     */
    _offerRetry(card) {
        const retry = card.querySelector("[data-health-retry]");

        retry?.classList.remove("hidden");
    }

    /** Undo every pending state this page is holding. */
    _releaseAll() {
        this.leavingTargets.forEach((link) => {
            if (link.dataset.leaving !== "true") {
                return;
            }

            delete link.dataset.leaving;
            link.removeAttribute("aria-disabled");
            link.classList.remove("pointer-events-none", "opacity-60");

            if (link.dataset.originalLabel !== undefined) {
                link.innerHTML = link.dataset.originalLabel;
                delete link.dataset.originalLabel;
            }
        });

        // Turbo restores the submitter's own label and enabled state on
        // submit-end, but a snapshot taken while a submission is still in
        // flight can catch it mid-state — a back button landing on a
        // permanently disabled repair. Cheap to undo, and undoing it on a
        // button Turbo has already restored is a no-op.
        this.element
            .querySelectorAll("button[type=submit][disabled]")
            .forEach((button) => button.removeAttribute("disabled"));
    }

    _t(key, fallback) {
        return this.i18nValue?.[key] ?? fallback;
    }
}
