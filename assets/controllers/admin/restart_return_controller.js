import { Controller } from "@hotwired/stimulus";

/**
 * The restarting page's way back to the dashboard.
 *
 * The delay is long enough for FrankenPHP to drain, exit and be recreated by
 * compose's restart policy. Too early only costs one failed navigation, which
 * is why this is a navigation and not a fetch.
 *
 * A controller rather than an inline <script>: the enforced
 * Content-Security-Policy refused that one, and the page just sat there.
 *
 * Values:
 *   url   — where to go
 *   delay — how long to wait first, in milliseconds
 */
export default class extends Controller {
    static values = {
        url: String,
        delay: { type: Number, default: 6000 },
    };

    connect() {
        this.timer = setTimeout(() => {
            window.location.href = this.urlValue;
        }, this.delayValue);
    }

    disconnect() {
        clearTimeout(this.timer);
    }
}
