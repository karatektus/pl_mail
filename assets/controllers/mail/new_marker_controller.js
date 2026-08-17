import { Controller } from "@hotwired/stimulus";
import { jsonCsrfHeaders } from "../../csrf.js";

/**
 * Tells the server which "New" rows actually reached the screen.
 *
 * The server marks a thread listed when it renders one, which is right until
 * the render and the display come apart — and Turbo 8 pulls them apart on
 * purpose. It prefetches a link on hover, and then serves THAT RESPONSE for the
 * click. MailController declines to retire badges on a prefetch, correctly,
 * because a hover is not a visit; but when the click reuses the prefetched
 * response there is no second request, so nothing ever retired them. Every list
 * reached from the sidebar or the category tabs kept its badges through
 * navigation and reload, permanently. This controller closes that hole from the
 * only place that can actually see a screen.
 *
 * On the container, not on each row: a page of fifty is one POST.
 *
 * Rows report once. Reporting is idempotent server-side — the UPDATE only
 * touches rows still null — but the `#reported` set keeps an untouched list
 * from re-POSTing the same ids every time anything on the page mutates.
 */
export default class extends Controller {
    static values = {
        url: String,
        // Enough for a fragment swap to settle, short enough that a user who
        // navigates straight back out has still been counted as having looked.
        delay: { type: Number, default: 250 },
    };

    #reported = new Set();
    #timer = null;
    #observer = null;

    // A field rather than a private method, because the same reference has to
    // come back off removeEventListener on disconnect.
    #onVisibility = () => {
        if (!document.hidden) this.#schedule();
    };

    connect() {
        document.addEventListener("visibilitychange", this.#onVisibility);

        // The list frame is swapped in place by the pane controller's refresh
        // and by Turbo frame navigation, neither of which re-connects this
        // controller — so the rows are watched rather than merely read once.
        this.#observer = new MutationObserver(() => this.#schedule());
        this.#observer.observe(this.element, { childList: true, subtree: true });

        this.#schedule();
    }

    disconnect() {
        clearTimeout(this.#timer);
        this.#observer?.disconnect();
        document.removeEventListener("visibilitychange", this.#onVisibility);
    }

    #schedule() {
        clearTimeout(this.#timer);
        this.#timer = setTimeout(() => this.#report(), this.delayValue);
    }

    async #report() {
        // A list rendered into a background tab is in the document but has not
        // been put in front of anybody. Wait for the tab to be looked at — the
        // visibilitychange listener above brings us back.
        if (document.hidden) return;

        const ids = [];

        for (const row of this.element.querySelectorAll("[data-new-id]")) {
            const id = Number(row.dataset.newId);

            if (Number.isInteger(id) && id > 0 && !this.#reported.has(id)) {
                this.#reported.add(id);
                ids.push(id);
            }
        }

        if (ids.length === 0) return;

        try {
            await fetch(this.urlValue, {
                method: "POST",
                headers: jsonCsrfHeaders(),
                body: JSON.stringify({ ids }),
            });
        } catch {
            // A failed report costs a badge one extra appearance, which is the
            // harmless direction. Un-remember the ids so the next mutation or
            // the next visit tries again.
            for (const id of ids) this.#reported.delete(id);
        }
    }
}
