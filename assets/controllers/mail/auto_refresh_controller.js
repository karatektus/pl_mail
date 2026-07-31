import { Controller } from '@hotwired/stimulus';

/**
 * Periodically reloads the turbo-frame it is attached to. Pauses while the
 * tab is hidden so a backgrounded dashboard doesn't hammer the server.
 *
 * Also pauses while something inside the frame is writing to the server. A
 * refresh renders from server state, so one that was *issued* before a write
 * committed swaps the pre-write markup back in — the user toggles a panel and
 * watches it snap shut again a moment later. keepalive on the write stops it
 * being cancelled but does nothing about that ordering, which is why holding
 * the refresh is the actual fix.
 */
export default class extends Controller {
    static values = {
        interval: { type: Number, default: 10000 },
    };

    /**
     * Depth, not a boolean: two writes can overlap, and a boolean would let
     * the first one to finish resume refreshing while the second is still in
     * flight — reintroducing the race it exists to prevent.
     */
    #holds = 0;

    connect() {
        this.onVisibilityChange = this.onVisibilityChange.bind(this);
        document.addEventListener('visibilitychange', this.onVisibilityChange);
        this.start();
    }

    /** A write started inside this frame. */
    hold() {
        this.#holds++;
    }

    /**
     * A write finished. The timer restarts rather than merely unblocking, so
     * the next refresh is a full interval away instead of landing immediately
     * after the write it was waiting for.
     */
    release() {
        this.#holds = Math.max(0, this.#holds - 1);

        if (0 === this.#holds) {
            this.start();
        }
    }

    disconnect() {
        document.removeEventListener('visibilitychange', this.onVisibilityChange);
        this.stop();
    }

    start() {
        this.stop();
        this.timer = setInterval(() => {
            this.reload();
        }, this.intervalValue);
    }

    stop() {
        if (this.timer) {
            clearInterval(this.timer);
            this.timer = null;
        }
    }

    reload() {
        if (this.#holds > 0) {
            return;
        }

        if (typeof this.element.reload === 'function') {
            this.element.reload();
        }
    }

    onVisibilityChange() {
        if (document.hidden) {
            this.stop();
        } else {
            this.reload();
            this.start();
        }
    }
}
