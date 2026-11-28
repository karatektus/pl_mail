import { Controller } from "@hotwired/stimulus";
import { leave } from "../../motion.js";
import { requestFailed } from "../../request_errors.js";

/**
 * The insight strip above the mail list: waving away one row, waving away the
 * whole band, and keeping up when the extractor finds something new.
 *
 * Mounted on the `<turbo-frame id="insight_pane">` the mailbox renders
 * (_layout/_mailbox.html.twig), not on the band inside it. The frame is the
 * one element that survives every load of the strip, because Turbo swaps a
 * frame's children and keeps the frame. The band is only drawn when there is
 * something to show, and while this controller lived on the band, a strip that
 * loaded empty had no controller and nothing listening for `insights.changed`.
 * A parcel found after the page loaded then stayed off screen until a reload.
 * The buttons inside the band still reach this controller, because a Stimulus
 * action resolves to the nearest ancestor carrying its identifier.
 *
 * Values:
 *   token      — CSRF for `insight-dismiss`, the per-row endpoint
 *   paneToken  — CSRF for `insight-pane-dismiss`, the whole-band endpoint
 *   paneUrl    — where the whole-band dismissal POSTs
 *
 * Action params:
 *   url        — the per-row dismiss endpoint, on the row's own menu item
 *
 * Routes used:
 *   row    → POST /insights/{id}/dismiss
 *   band   → POST /insights/pane/dismiss
 *   reload → the frame's own `src`, GET /insights/pane
 *
 * Both tokens are values rather than going through csrf.js, for the reason
 * radar_controller documents: these are the per-action tokens the two
 * endpoints check, not the shared `ajax` one.
 *
 * Nothing is removed before the server has said ok. An optimistic removal would
 * eat the card on an expired session and hand it back on the next load, which
 * reads as the dismissal "not sticking". That is the one behaviour
 * MailInsight's dismissedAt column exists to rule out.
 *
 * ── When the last row goes, the band goes ───────────────────────────────────
 *
 * Both dismissals end with the BAND removed, so the frame is left empty, which
 * is exactly what _pane.html.twig renders when there is nothing to say: no
 * height, no heading over nothing. The frame itself stays, with its `src` and
 * this controller. It used to be removed too, and with it went any way back:
 * an insight that arrived after "Not now" (newer than the dismissal, so the
 * server would show it, see InsightPaneController::dismiss) could not appear
 * until the next full page load. A workspace switch is not one, so that could
 * last all day.
 *
 * ── The dismiss/refresh race ────────────────────────────────────────────────
 *
 * A refresh and a dismissal both want the same DOM, and they arrive from
 * different directions: the user presses ✕ while an `insights.changed` is
 * already on the wire.
 *
 * DURING the round trip `#busy` holds refreshes off. A reload landing
 * mid-flight would swap in a band drawn by a server that does not yet know
 * about the press, and the strip would visibly come back a beat after being
 * waved away. A refresh that arrives during that window is not dropped, it is
 * REMEMBERED in `#missed`.
 *
 * AFTER the band is gone, the server holds the dismissal, so any reload from
 * then on is correct: InsightPane::rowsFor re-derives it on every request. So
 * the remembered reload runs then, whether the press succeeded or failed. One
 * case is left: a reload that was already in flight before the press. Its
 * answer was rendered before the dismissal and would draw the old band back.
 * Turbo marks a frame that is loading `busy`, and a new load cancels the one
 * in flight, so a busy frame is simply reloaded once more.
 */

/**
 * Published by the mercure controller when the hub says `insights.changed` on
 * this user's topic. Listened for on `document` rather than through a
 * data-action, the way ui--sidebar and rules--rule-run do: the event is
 * dispatched on <body>, which is an ANCESTOR of this frame, so it never reaches
 * a listener nested inside. It only passes here on its way up to document.
 *
 * No EventSource of its own: `core--mercure` is already mounted on <body> for
 * every mailbox page and its stream is shared at module scope. A second
 * subscription would be a second socket for a message the first one already has.
 */
const CHANGED_EVENT = "core--mercure:insights-changed";

export default class extends Controller {
    static values = {
        token:     String,
        paneToken: String,
        paneUrl:   String,
    };

    connect() {
        this.#busy = false;
        this.#missed = false;

        this.#onChanged = () => this.#refresh();
        document.addEventListener(CHANGED_EVENT, this.#onChanged);
    }

    disconnect() {
        document.removeEventListener(CHANGED_EVENT, this.#onChanged);
    }

    /** The ✕ on the heading: the whole band, until something new turns up. */
    async dismissPane(event) {
        const band = this.#band();

        if (true === await this.#post(event.currentTarget, this.paneUrlValue, this.paneTokenValue)) {
            // The band leaves as one thing because that is what was pressed.
            // The same 120ms radar_controller argues for, hidden inside a round
            // trip that was happening anyway.
            this.#leave(band, () => band?.remove());
        }
    }

    /** A row's `⋮` → Dismiss: this insight, and the band with it if it was the last. */
    async dismiss(event) {
        const { url } = event.params;
        const card = event.currentTarget.closest("[data-insight-card]");

        if (false === await this.#post(event.currentTarget, url, this.tokenValue)) {
            return;
        }

        // The count rides in the callback because `leave` removes the node
        // when the animation ends: counting before that would always find the
        // card still there.
        this.#leave(card, () => {
            card?.remove();

            if (null === this.element.querySelector("[data-insight-card]")) {
                this.#band()?.remove();
            }
        });
    }

    // ── Private ───────────────────────────────────────────────────────────

    #busy;
    #missed;
    #onChanged;

    /**
     * POSTs a dismissal and says whether it stuck.
     *
     * The button is disabled for the round trip so a slow network cannot take a
     * second click. The endpoints are idempotent; this is about not looking
     * broken, not about safety.
     */
    async #post(button, url, token) {
        this.#busy = true;
        button.disabled = true;

        let response;

        try {
            response = await fetch(url, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-Token": token,
                },
            });
        } catch {
            requestFailed(null);
            this.#release();

            return false;
        } finally {
            button.disabled = false;
        }

        // The card stays; without the toast the click would look ignored.
        if (requestFailed(response)) {
            this.#release();

            return false;
        }

        // Deliberately still busy. The removal is what ends this dismissal
        // (#leave releases), and until it is done a reload could still draw a
        // band over it.
        return true;
    }

    /**
     * Animates `element` out, runs `removed`, then lets refreshes through.
     *
     * Once only: `leave` can call back twice (on animationend, and again from
     * its safety timeout), and a second release would reload a second time.
     * With no element to animate there is nothing to wait for.
     */
    #leave(element, removed) {
        let done = false;
        const finish = () => {
            if (done) {
                return;
            }

            done = true;
            removed();
            this.#release({ stale: this.element.hasAttribute("busy") });
        };

        if (element) {
            leave(element, finish);
        } else {
            finish();
        }
    }

    /**
     * The dismissal is over, stuck or not: let refreshes through again, and
     * run the one that was missed meanwhile. `stale` is a load that was
     * already in flight before the press, whose answer predates the dismissal.
     */
    #release({ stale = false } = {}) {
        this.#busy = false;

        if (true === this.#missed || true === stale) {
            this.#missed = false;
            this.#refresh();
        }
    }

    #refresh() {
        if (true === this.#busy) {
            this.#missed = true;

            return;
        }

        // A lazy frame that has not come on screen yet (the list parked in a
        // workspace without it) is not fetched by this: Turbo loads a lazy
        // frame's new src only once it has been loaded or is visible, and it
        // will load fresh when it is.
        this.element.reload();
    }

    /** The band, looked up each time: every reload replaces it. */
    #band() {
        return this.element.querySelector("[data-insight-pane]");
    }
}
