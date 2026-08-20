import { Controller } from "@hotwired/stimulus";
import { leave } from "../../motion.js";

/**
 * The radar's dismiss button — POSTs the wave-away, then takes the card off
 * the screen.
 *
 * The token rides in as a Stimulus VALUE from the template rather than through
 * csrf.js, because it is the per-action `insight-dismiss` token the endpoint
 * checks, not the shared `ajax` one — the same split csrf.js itself documents
 * for settings/account_order_controller. One controller scope wraps the whole
 * radar, so the token is minted once and every card's button reaches it; the
 * URL differs per card and travels as an action param on the button.
 *
 * The card is removed only AFTER the server says ok. An optimistic removal
 * would eat the card on an expired session and hand it back on the next open,
 * which reads as the dismissal "not sticking" — the one behaviour the
 * dismissedAt column exists to rule out (see MailInsight).
 *
 * ── Why this one earns an exit animation ────────────────────────────────────
 *
 * Exits in plMail are deliberately rare — motion.css says so, and gives the
 * reason: an exit is time between asking for something and getting it, every
 * time. That argument does not bite here, for two reasons that are specific to
 * this button. The card is already waiting on a round trip, so the 120ms fade
 * is hidden inside a delay that exists anyway rather than added to a delay that
 * did not. And the thing leaving is the thing under the cursor: without it, the
 * card the user is looking at is gone in the same frame the response lands and
 * the cards below jump up to fill the hole, with nothing on screen confirming
 * that the press was what did it. That is the "a toast dismissing itself" case
 * the leave keyframe was written for.
 *
 * The cleanup rides in the callback rather than after the call, because `leave`
 * removes the node when the animation ends — checking whether the list is empty
 * before that would always find the card still in it. At the `none` tier and
 * under reduced motion `leave` calls back synchronously, so this is the same
 * code path at every setting rather than a second one.
 */
export default class extends Controller {
    static values = { token: String };

    async dismiss(event) {
        const button = event.currentTarget;
        const card = button.closest("[data-insight-card]");

        // No double-fire from a slow network's second click. The endpoint is
        // idempotent anyway; this is about not looking broken, not safety.
        button.disabled = true;

        let response;

        try {
            response = await fetch(event.params.url, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-Token": this.tokenValue,
                },
            });
        } catch {
            return;
        } finally {
            button.disabled = false;
        }

        if (!response.ok || !card) {
            return;
        }

        const list = card.closest("[data-insight-list]");

        leave(card, () => {
            card.remove();

            // A subheading over nothing reads as a section that failed to load,
            // so the last card takes its section with it.
            if (list && !list.querySelector("[data-insight-card]")) {
                (list.closest("[data-insight-section]") ?? list).remove();
            }
        });
    }
}
