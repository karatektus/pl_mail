import { Controller } from "@hotwired/stimulus";

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

        card.remove();

        // A subheading over nothing reads as a section that failed to load,
        // so the last card takes its section with it.
        if (list && !list.querySelector("[data-insight-card]")) {
            (list.closest("[data-insight-section]") ?? list).remove();
        }
    }
}
