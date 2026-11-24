/* stimulusFetch: 'lazy' */
import { Controller } from "@hotwired/stimulus";

/**
 * Reloads the Recent runs card on Admin → Backfills while a run in it is
 * queued or going, and stops when none is.
 *
 * Not mail--auto-refresh, which reloads on a fixed interval for as long as
 * the frame exists. That would close an output somebody had opened to read
 * every few seconds, long after there was anything new in it. Here the card
 * says whether to go on: a row marked `data-active` means yes, and each
 * reload is a fresh answer.
 *
 * The frame is drawn inline the first time, inside the panel's own frame, so
 * it has no `src` of its own until the first reload sets one.
 *
 * Values:
 *   url       — the card on its own (app_admin_backfills_runs)
 *   interval  — milliseconds between reloads, 4000 by default
 *
 * Events:
 *   turbo:frame-load on the element — decides whether to reload again
 *
 * The detail beside the list says "Queued" or "Running", with no Run button,
 * while its task has a run going (`data-backfill-active`, carrying the
 * detail's own URL). When this card shows nothing going and the detail still
 * says otherwise, the detail is reloaded. Compared on every load rather than
 * remembered from the one before, so a run that finished before this card
 * ever saw it going still releases the detail.
 *
 * Actions:
 *   refresh — turbo:frame-load on the document; reloads at once when the
 *             detail frame (admin-backfill-detail) has loaded, which is how a
 *             run just queued there appears here without the list beside it
 *             being re-rendered
 */
export default class extends Controller {
    static values = {
        url:      String,
        interval: { type: Number, default: 4000 },
    };

    connect() {
        this.onLoad = this.onLoad.bind(this);
        this.element.addEventListener("turbo:frame-load", this.onLoad);
        this.#schedule();
    }

    disconnect() {
        this.element.removeEventListener("turbo:frame-load", this.onLoad);
        this.#cancel();
    }

    onLoad() {
        this.#releaseDetail();
        this.#schedule();
    }

    refresh(event) {
        if ("admin-backfill-detail" !== event.target.id) {
            return;
        }

        this.#cancel();
        this.#reload();
    }

    // ── Private ───────────────────────────────────────────────────────────

    #schedule() {
        this.#cancel();

        if (!this.#isActive()) {
            return;
        }

        this.timer = setTimeout(() => this.#reload(), this.intervalValue);
    }

    #releaseDetail() {
        const marker = document.querySelector("#admin-backfill-detail [data-backfill-active]");

        if (null === marker || this.#isActive()) {
            return;
        }

        const frame = document.getElementById("admin-backfill-detail");

        if (frame.getAttribute("src") === marker.dataset.url) {
            frame.reload();
        } else {
            frame.src = marker.dataset.url;
        }
    }

    #isActive() {
        return null !== this.element.querySelector("[data-active]");
    }

    #cancel() {
        if (this.timer) {
            clearTimeout(this.timer);
            this.timer = null;
        }
    }

    #reload() {
        // A hidden tab asks again when it is looked at, not in the background.
        if (document.hidden) {
            document.addEventListener("visibilitychange", () => this.#reload(), { once: true });

            return;
        }

        if (this.element.getAttribute("src") === this.urlValue) {
            this.element.reload();
        } else {
            this.element.src = this.urlValue;
        }
    }
}
