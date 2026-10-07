import { Controller } from "@hotwired/stimulus";

/**
 * The restarting page's way back to where it came from.
 *
 * Waits for the server to be there, then goes. It used to go after a fixed six
 * seconds, on the reasoning that being early only costs one failed navigation.
 * That holds for an administrator who pressed Restart and knows what a
 * connection error means. It does not hold for the install form, which ends on
 * this page too: there the first thing a new installation showed, on a machine
 * that takes longer than six seconds to bring a container back, was the
 * browser's own "this site can't be reached".
 *
 * So after the delay — which is still what lets the old process drain and
 * exit, and must not be shortened to nothing or the answer comes from the
 * server that is about to go away — it asks /healthz until something answers,
 * and navigates then. /healthz because it needs no session and says nothing a
 * stranger could not learn by trying. A fetch that fails is the expected case
 * here and is simply tried again.
 *
 * It gives up waiting after a while and navigates regardless: a page that
 * polls for ever explains nothing, and the browser's error at least does.
 *
 * A controller rather than an inline <script>: the enforced
 * Content-Security-Policy refused that one, and the page just sat there.
 *
 * Values:
 *   url   — where to go
 *   delay — how long to wait before the first look, in milliseconds
 */
const POLL_MS = 2000;
const GIVE_UP_MS = 180000;

export default class extends Controller {
    static values = {
        url: String,
        delay: { type: Number, default: 6000 },
    };

    connect() {
        this.startedAt = Date.now();
        this.timer = setTimeout(() => this.look(), this.delayValue);
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    async look() {
        if (true === await this.serverIsBack() || Date.now() - this.startedAt > GIVE_UP_MS) {
            window.location.href = this.urlValue;

            return;
        }

        this.timer = setTimeout(() => this.look(), POLL_MS);
    }

    async serverIsBack() {
        try {
            const response = await fetch("/healthz", { cache: "no-store" });

            return true === response.ok;
        } catch {
            return false;
        }
    }
}
