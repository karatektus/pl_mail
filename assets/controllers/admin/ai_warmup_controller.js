import { Controller } from "@hotwired/stimulus";

/**
 * "Warm up now" — asks the host to load the writing model, and says what happened.
 *
 * Values:
 *   url          — POST endpoint, /admin/ai/warmup
 *   csrf         — token minted for `admin-ai-warmup`, NOT the shared `ajax` one
 *   idleLabel    — the button's resting text, restored after a result has been read
 *   busyLabel    — while the host is loading; see below, this is not a spinner
 *   failedLabel  — the fallback when the answer carries no message of its own
 *
 * Targets:
 *   button   — the control itself, disabled while a request is in flight
 *   label    — the span inside it whose text changes
 *   icon     — the resting icon's wrapper; hidden while busy
 *   spinner  — the spinner's wrapper; shown while busy
 *   result   — where the sentence from the server is written
 *
 * WHY THE STATES ARE WORDS AND NOT A SPINNER ALONE
 * ────────────────────────────────────────────────
 * This request can legitimately take forty seconds and produce nothing at all
 * while it does — the host is reading eighteen gigabytes off disk and says
 * nothing until it has finished. A spinner during that is indistinguishable
 * from a dead button, which is the exact report compose/ai_assist_controller
 * was built to answer, and the answer there was the same one: name the state.
 * The spinner is here as well, but it is the decoration and the words are the
 * information.
 *
 * WHY THE SENTENCE COMES FROM THE SERVER
 * ──────────────────────────────────────
 * Every outcome is a sentence in three languages, and one of them carries the
 * host's own refusal in it. Assembling those here would put the copy in a file
 * no translator opens — the rule admin/ai_status_controller states for the same
 * page. This file decides WHEN to ask and what the button looks like while it
 * is asking, and nothing about what it says.
 *
 * WHAT IT DOES NOT DO
 * ───────────────────
 * It never talks to the model host: different origin, an address usually only
 * the server can route to, and `connect-src 'self'` refuses it in production
 * regardless.
 */
export default class extends Controller {
    static targets = ["button", "label", "icon", "spinner", "result"];

    static values = {
        url: String,
        /**
         * Taken from the template rather than from the `csrf-token` meta tag,
         * because it is a different token and not a different way of reading
         * the same one — the distinction assets/csrf.js draws for
         * settings/account_order_controller. This endpoint makes another
         * machine reserve eighteen gigabytes; a token good for it should not
         * also be good for everything else on the page.
         */
        csrf: String,
        idleLabel: { type: String, default: "Warm up now" },
        busyLabel: { type: String, default: "Loading the model…" },
        failedLabel: { type: String, default: "No answer" },
    };

    /**
     * Whether a request is in flight.
     *
     * The button is disabled while it is, so this is belt and braces — but the
     * braces earn their place: a second preload of a model already loading does
     * not queue behind the first, it makes the host do the work twice, and the
     * host is the machine this button exists to be considerate of.
     */
    #busy = false;

    /**
     * Abandoned if the frame is swapped out from under it — this card lives
     * inside a Turbo Frame that re-renders on every save, and a fetch that
     * outlives its controller would write into elements no longer on the page.
     */
    #abort = null;

    disconnect() {
        if (null !== this.#abort) {
            this.#abort.abort();
            this.#abort = null;
        }
    }

    async warmUp() {
        if (true === this.#busy) {
            return;
        }

        this.#busy = true;
        this.#abort = new AbortController();
        this.#setBusy(true);

        try {
            const response = await fetch(this.urlValue, {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": this.csrfValue },
                signal: this.#abort.signal,
            });

            if (false === response.ok) {
                throw new Error(`Request failed (${response.status}).`);
            }

            const payload = await response.json();

            this.#show(payload?.message ?? this.failedLabelValue, true === payload?.ok);
        } catch (error) {
            // An aborted fetch is the frame being replaced, not a failure —
            // there is nothing left on screen to report it to.
            if ("AbortError" === error?.name) {
                return;
            }

            // Everything the server can answer already arrives as a translated
            // sentence, so reaching here means the endpoint itself did not
            // answer: a session that expired, or a container restarting. One
            // label covers both, because the fix for both is to try again.
            this.#show(this.failedLabelValue, false);
        } finally {
            this.#busy = false;
            this.#abort = null;
            this.#setBusy(false);
        }
    }

    // ── Private ───────────────────────────────────────────────────────────

    #setBusy(busy) {
        if (true === this.hasButtonTarget) {
            this.buttonTarget.disabled = busy;
        }

        if (true === this.hasLabelTarget) {
            this.labelTarget.textContent = busy ? this.busyLabelValue : this.idleLabelValue;
        }

        // The `hidden` attribute on the WRAPPERS, not on the icons — Font
        // Awesome's own display rule beats it on an <i>. See the template.
        if (true === this.hasIconTarget) {
            this.iconTarget.hidden = busy;
        }

        if (true === this.hasSpinnerTarget) {
            this.spinnerTarget.hidden = false === busy;
        }

        if (true === busy && true === this.hasResultTarget) {
            // Cleared on the way IN rather than on the way out, so the previous
            // answer stays readable until a new one is actually being fetched.
            this.resultTarget.textContent = "";
            this.resultTarget.hidden = true;
        }
    }

    #show(message, ok) {
        if (false === this.hasResultTarget) {
            return;
        }

        // textContent, never innerHTML. One of these messages carries the
        // host's own error string, which is text off another machine.
        this.resultTarget.textContent = message;
        this.resultTarget.hidden = false;
        this.resultTarget.classList.toggle("text-emerald-500", ok);
        this.resultTarget.classList.toggle("text-danger", false === ok);
    }
}
