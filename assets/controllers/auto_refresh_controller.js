import { Controller } from '@hotwired/stimulus';

/**
 * Periodically reloads the turbo-frame it is attached to. Pauses while the
 * tab is hidden so a backgrounded dashboard doesn't hammer the server.
 */
export default class extends Controller {
    static values = {
        interval: { type: Number, default: 10000 },
    };

    connect() {
        this.onVisibilityChange = this.onVisibilityChange.bind(this);
        document.addEventListener('visibilitychange', this.onVisibilityChange);
        this.start();
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
