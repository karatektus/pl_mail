import {Controller} from "@hotwired/stimulus";

/**
 * One EventSource per topic URL, shared across controller instances. Turbo
 * replaces <body> on every visit — and renders a cached preview first — so a
 * stream owned by a single instance would open two connections per navigation
 * and drop any update published while the body is being swapped.
 *
 * Keying by URL is safe because mercure() renders a stable URL: it is the hub's
 * public_url plus ?topic=…, with no JWT and no cookie side effect unless
 * subscribe/publish options are passed, and the call site passes none.
 *
 * @type {Map<string, {es: EventSource, listeners: Set<Function>, closeTimer: ?number}>}
 */
const streams = new Map();

function subscribe(url, listener) {
    let stream = streams.get(url);

    if (stream) {
        // A navigation is in flight, not a teardown — keep the connection.
        if (stream.closeTimer !== null) {
            clearTimeout(stream.closeTimer);
            stream.closeTimer = null;
        }
    } else {
        stream = {
            es: new EventSource(url, {withCredentials: true}),
            listeners: new Set(),
            closeTimer: null,
        };

        stream.es.onmessage = (event) => {
            const data = JSON.parse(event.data);

            // Copy first: a listener may unsubscribe during the fan-out.
            [...stream.listeners].forEach((fn) => {
                try {
                    fn(data);
                } catch (error) {
                    // One broken listener must not starve the others.
                    console.error("[mercure] listener failed:", error);
                }
            });
        };

        streams.set(url, stream);
    }

    stream.listeners.add(listener);
}

function unsubscribe(url, listener) {
    const stream = streams.get(url);

    if (!stream) {
        return;
    }

    stream.listeners.delete(listener);

    if (stream.listeners.size > 0 || stream.closeTimer !== null) {
        return;
    }

    // Stimulus fires the outgoing disconnect() before the incoming connect(),
    // so closing synchronously would tear the stream down and reopen it on
    // every visit — exactly the churn this indirection exists to avoid.
    stream.closeTimer = setTimeout(() => {
        if (stream.listeners.size > 0) {
            stream.closeTimer = null;
            return;
        }

        stream.es.close();
        streams.delete(url);
    }, 0);
}

export default class extends Controller {
    static values = {
        url: String,
    };

    connect() {
        // Pin the URL used to subscribe so a value change can never release
        // some other stream's listener.
        this._subscribedUrl = this.urlValue;
        this._onUpdate = (data) => this._handleUpdate(data);

        subscribe(this._subscribedUrl, this._onUpdate);
    }

    disconnect() {
        unsubscribe(this._subscribedUrl, this._onUpdate);
    }

    // Update types come from App\Service\Mail\SyncNotifier. Both are dispatched
    // on <body>, so listeners nested deeper (the sidebar) catch them bubbling
    // up to document rather than via a data-action.
    _handleUpdate(data) {
        if (data.type === "mailbox.synced") {
            this.dispatch("mailbox-synced", {detail: data});
        } else if (data.type === "account.synced") {
            this.dispatch("account-synced", {detail: data});
        }
    }
}
