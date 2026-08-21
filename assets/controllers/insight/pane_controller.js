import { Controller } from "@hotwired/stimulus";
import { leave } from "../../motion.js";

/**
 * The insight strip above the mail list: waving away one row, waving away the
 * whole band, and keeping up when the extractor finds something new.
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
 * Both tokens ride in as VALUES from the template rather than through csrf.js,
 * for the reason radar_controller documents: these are the per-action tokens
 * the two endpoints check, not the shared `ajax` one. One scope wraps the whole
 * band, so each is minted once however many rows there are, and the per-row URL
 * travels as an action param on the menu item.
 *
 * Nothing is removed before the server has said ok. An optimistic removal would
 * eat the card on an expired session and hand it back on the next load, which
 * reads as the dismissal "not sticking" — the one behaviour MailInsight's
 * dismissedAt column exists to rule out.
 *
 * ── When the last row goes, the band goes ───────────────────────────────────
 *
 * Both dismissals end at the same place: the FRAME is removed, not merely this
 * element. A heading over nothing reads as a section that failed to load, and
 * an empty bordered band above the inbox is exactly what _pane.html.twig
 * refuses to render in the first place — the client must not be able to produce
 * a state the server would never send.
 *
 * Removing the frame rather than its contents also removes this controller with
 * it, which is what makes the next section true.
 *
 * ── The dismiss/refresh race ────────────────────────────────────────────────
 *
 * A refresh and a dismissal both want the same DOM, and they arrive from
 * different directions: the user presses ✕ while an `insights.changed` is
 * already on the wire. Three things keep them apart.
 *
 * The dismissal wins by OUTLIVING the refresh. Once it succeeds the frame is
 * gone, and a frame that is not in the document cannot be reloaded — there is
 * no element left holding a `src`, and no controller left listening. So a
 * message arriving after a completed dismissal is not merely harmless, it is
 * unreachable.
 *
 * DURING the round trip the flag below holds refreshes off. A reload landing
 * mid-flight would swap in a freshly rendered band — correct markup, drawn from
 * a server that does not yet know about the press — and the dismissal would
 * then remove an element nobody is looking at any more while the new one stays
 * on screen. The strip would visibly come back a beat after being waved away.
 *
 * And a refresh that arrives during that window is not dropped, it is
 * REMEMBERED: if the dismissal fails, the flag clears and the remembered reload
 * runs, so a failed press costs the user a button that did nothing rather than
 * a strip that silently stopped updating.
 *
 * The reload itself is safe to run whenever it is allowed to, because the
 * server already holds the dismissal — `InsightPane::rowsFor` re-derives it on
 * every request, and answers with an empty band. This client-side removal is
 * about the FRAME between the press and the next render, nothing more.
 */

/**
 * Published by the mercure controller when the hub says `insights.changed` on
 * this user's topic. Listened for on `document` rather than through a
 * data-action, the way ui--sidebar and rules--rule-run do: the event is
 * dispatched on <body>, which is an ANCESTOR of this frame, so it never reaches
 * a listener nested inside — it only passes here on its way up to document.
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
        const band = this.#frame() ?? this.element;

        if (true === await this.#post(event.currentTarget, this.paneUrlValue, this.paneTokenValue)) {
            // The band leaves as one thing because that is what was pressed.
            // The same 120ms radar_controller argues for, hidden inside a round
            // trip that was happening anyway. `leave` removes the node itself
            // when no callback says otherwise.
            leave(band);
        }
    }

    /** A row's `⋮` → Dismiss: this insight, and the band with it if it was the last. */
    async dismiss(event) {
        const { url } = event.params;
        const card = event.currentTarget.closest("[data-insight-card]");

        if (false === await this.#post(event.currentTarget, url, this.tokenValue)) {
            return;
        }

        if (!card) {
            return;
        }

        const band = this.#frame() ?? this.element;

        // The cleanup rides in the callback because `leave` removes the node
        // when the animation ends — counting what is left before that would
        // always find the card still in it. Under `none` and reduced motion
        // `leave` calls back synchronously, so this is one code path, not two.
        leave(card, () => {
            card.remove();

            if (null === this.element.querySelector("[data-insight-card]")) {
                band.remove();
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
            this.#release();

            return false;
        } finally {
            button.disabled = false;
        }

        if (false === response.ok) {
            this.#release();

            return false;
        }

        // Deliberately still busy. The removal below is what ends this
        // dismissal, and until the element is gone a reload could still draw a
        // band over it.
        return true;
    }

    /** The dismissal did not stick — let refreshes through again, including a missed one. */
    #release() {
        this.#busy = false;

        if (true === this.#missed) {
            this.#missed = false;
            this.#refresh();
        }
    }

    #refresh() {
        if (true === this.#busy) {
            this.#missed = true;

            return;
        }

        this.#frame()?.reload();
    }

    /**
     * The frame this strip was rendered into. Looked up each time rather than
     * cached on connect: the controller lives INSIDE the frame, so a reload
     * replaces this element and any reference taken on a previous render would
     * point at markup that has already been thrown away.
     */
    #frame() {
        return this.element.closest("turbo-frame#insight_pane");
    }
}
