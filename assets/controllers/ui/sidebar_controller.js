import { Controller } from "@hotwired/stimulus";

// `is-active` carries no colour of its own — it is the hook the sidebar's
// Gmail-style pill shape hangs off in app.css.
const ACTIVE_CLASSES   = ["is-active", "bg-accent-soft", "text-accent", "font-medium"];
const INACTIVE_CLASSES = ["text-ink-muted", "hover:bg-hover"];
const SYNC_EVENTS      = ["core--mercure:mailbox-synced", "core--mercure:account-synced"];
export default class extends Controller {
    static targets = ["link", "badge"];
    static values = { countsUrl: String };

    connect() {
        this._updateActive();
        this._onTurboLoad = () => this._updateActive();
        document.addEventListener("turbo:load", this._onTurboLoad);

        // The desktop sidebar is data-turbo-permanent, so a Turbo visit
        // carries the old element over and its badges would otherwise stay
        // stale. Patching them in place also keeps scroll position and any
        // collapsed label trees, which re-rendering the nav would reset.
        this._onSynced = () => this.refreshCounts();
        SYNC_EVENTS.forEach((name) =>
            document.addEventListener(name, this._onSynced),
        );
    }

    disconnect() {
        document.removeEventListener("turbo:load", this._onTurboLoad);
        SYNC_EVENTS.forEach((name) =>
            document.removeEventListener(name, this._onSynced),
        );
    }

    async refreshCounts() {
        if (!this.hasCountsUrlValue) {
            return;
        }

        let counts;
        try {
            const response = await fetch(this.countsUrlValue, {
                headers: { Accept: "application/json" },
            });
            if (!response.ok) {
                return;
            }
            counts = await response.json();
        } catch {
            // Offline or the sync raced a navigation — the next sync retries,
            // and a full page load renders the counts server-side anyway.
            return;
        }

        this.badgeTargets.forEach((badge) => {
            const count = counts[badge.dataset.countKey];
            if (count === undefined) {
                return;
            }

            badge.textContent = count;
            badge.classList.toggle("hidden", count === 0);
        });
    }

    _updateActive() {
        const current = window.location.pathname;

        this.linkTargets.forEach((link) => {
            const href = link.getAttribute("href");
            const isActive = current === href || current.startsWith(href + "/");

            // A system row is its own .nav-item; a custom label wraps the
            // anchor in one so the disclosure arrow and the edit button stay
            // outside the link. Tint the row either way, or a label would get
            // the colour on its text only while the pill stayed empty.
            const row = link.closest(".nav-item") ?? link;

            if (isActive) {
                row.classList.add(...ACTIVE_CLASSES);
                row.classList.remove(...INACTIVE_CLASSES);
            } else {
                row.classList.remove(...ACTIVE_CLASSES);
                row.classList.add(...INACTIVE_CLASSES);
            }
        });
    }
}
