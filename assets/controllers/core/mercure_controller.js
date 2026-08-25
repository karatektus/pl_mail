import {Controller} from "@hotwired/stimulus";

/**
 * One EventSource per topic URL, shared across controller instances. Turbo
 * replaces <body> on every visit — and renders a cached preview first — so a
 * stream owned by a single instance would open two connections per navigation
 * and drop any update published while the body is being swapped.
 *
 * Keying by URL is safe because mercure() renders a stable URL: it is the hub's
 * public_url plus ?topic=…. The JWT authorizing the subscription travels in the
 * mercureAuthorization cookie, not in this URL, so the URL stays stable even
 * when the credential behind it is refreshed.
 *
 * @type {Map<string, {
 *   es: EventSource,
 *   listeners: Set<Function>,
 *   closeTimer: ?number,
 *   retryTimer: ?number,
 *   attempt: number,
 *   state: string,
 *   authUrl: ?string,
 * }>}
 */
const streams = new Map();

/**
 * Connection state, broadcast on document so anything can show it — the topbar
 * indicator is the first consumer.
 *
 * "connecting" covers both the first attempt and every retry: to a user those
 * are the same situation (updates are not arriving yet), and splitting them
 * would only make the indicator flicker between two states that call for the
 * same reaction.
 */
export const MERCURE_STATE_EVENT = "core--mercure:state";
const STATE_CONNECTING = "connecting";
const STATE_CONNECTED = "connected";
const STATE_OFFLINE = "offline";

function setState(stream, state) {
    if (stream.state === state) {
        return;
    }

    stream.state = state;
    document.dispatchEvent(new CustomEvent(MERCURE_STATE_EVENT, {detail: {state}}));
}

/**
 * The state right now, for anything that mounts after the last transition.
 *
 * The events above are a broadcast, not a store: a listener that appears later
 * hears nothing until the state next changes, which on a healthy instance may
 * be never. Turbo replaces <body> on every visit, so the indicator in the
 * topbar is rebuilt constantly while this module — and the connection it owns —
 * survives untouched. It reads the answer from here instead of waiting for an
 * announcement it already missed.
 */
export function currentStreamState() {
    for (const stream of streams.values()) {
        if (stream.state !== null) {
            return stream.state;
        }
    }

    return null;
}

/**
 * Backoff for reconnects. EventSource retries on its own ONLY when the
 * connection drops cleanly; an HTTP error response (our Caddy answers 502 while
 * the hub is restarting, and the hub answers 401 once the subscriber JWT has
 * expired) is fatal per spec — readyState goes to CLOSED and the browser never
 * tries again. That is the whole reason this file has a reconnect loop: without
 * it, one hub restart silently ends live updates in every open tab until
 * someone reloads the page.
 *
 * Capped at 30s so a long outage settles into a slow poll rather than an
 * ever-growing wait, and jittered so a hub coming back does not take a
 * thundering herd from every tab at once.
 */
const RETRY_BASE_MS = 1000;
const RETRY_CAP_MS = 30000;

/** How often to fall back to polling while the stream is unavailable. */
const POLL_INTERVAL_MS = 60000;

function backoffDelay(attempt) {
    const capped = Math.min(RETRY_CAP_MS, RETRY_BASE_MS * 2 ** attempt);

    return capped / 2 + Math.random() * (capped / 2);
}

/**
 * Opens the EventSource and wires it up. Called for the first connection and
 * for every reconnect — a dead EventSource cannot be revived, so recovering
 * means constructing a new one.
 */
function open(url, stream) {
    const es = new EventSource(url, {withCredentials: true});
    stream.es = es;

    // Say so while it is happening. Until this, state was only ever set from
    // onopen or onerror — so between constructing the EventSource and the first
    // of those there was no state at all, and the topbar dot renders nothing
    // until it is given one: no colour, no tooltip, on a fresh page load.
    //
    // The gap is normally a few milliseconds and invisible. It is not always:
    // an SSE request can HANG rather than fail — a hub that is still coming up
    // accepts the connection and holds it — and neither handler fires, so the
    // dot sits blank and untitled for as long as that lasts, over a stream that
    // is genuinely still trying. "Reconnecting to live updates…" is the honest
    // thing to show there, and it is a label the dot already carries.
    setState(stream, STATE_CONNECTING);

    es.onopen = () => {
        stream.attempt = 0;
        setState(stream, STATE_CONNECTED);
    };

    es.onmessage = (event) => {
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

    es.onerror = () => {
        // readyState CONNECTING means the browser is retrying by itself, which
        // it does well; leaving it alone avoids racing it with a second socket.
        // CLOSED is the case it will never recover from on its own.
        if (es.readyState !== EventSource.CLOSED) {
            setState(stream, STATE_CONNECTING);

            return;
        }

        setState(stream, STATE_OFFLINE);
        scheduleReconnect(url, stream);
    };
}

function scheduleReconnect(url, stream) {
    if (stream.retryTimer !== null || stream.listeners.size === 0) {
        return;
    }

    const delay = backoffDelay(stream.attempt);
    stream.attempt++;

    stream.retryTimer = setTimeout(() => {
        stream.retryTimer = null;

        if (stream.listeners.size === 0) {
            return;
        }

        reconnect(url, stream);
    }, delay);
}

/**
 * Refresh the subscriber cookie, then rebuild the connection.
 *
 * The re-auth is what makes this recover from an expired JWT rather than
 * looping on 401 forever: the cookie the layout minted is short-lived, and a
 * tab open past its expiry would otherwise retry with a credential the hub
 * keeps refusing. A failure here is not fatal — the hub may simply be down,
 * which the reconnect below will discover and reschedule.
 */
async function reconnect(url, stream) {
    setState(stream, STATE_CONNECTING);

    if (stream.authUrl) {
        try {
            await fetch(stream.authUrl, {credentials: "same-origin", cache: "no-store"});
        } catch {
            // Offline, or the app is down too. Reconnecting anyway keeps the
            // one retry path — the error handler will reschedule.
        }
    }

    if (stream.listeners.size === 0) {
        return;
    }

    try {
        stream.es.close();
    } catch {
        // Already closed; nothing to release.
    }

    open(url, stream);
}

/**
 * A tab that comes back to the foreground, or a machine that regains network,
 * should not sit out the remaining backoff — by then the wait is up to 30s of
 * an inbox that looks live and is not. Both reset the attempt count, because
 * the previous failures say nothing about a connection made under new
 * conditions.
 */
function reviveAll() {
    for (const [url, stream] of streams) {
        if (stream.listeners.size === 0 || stream.state === STATE_CONNECTED) {
            continue;
        }

        if (stream.retryTimer !== null) {
            clearTimeout(stream.retryTimer);
            stream.retryTimer = null;
        }

        stream.attempt = 0;
        reconnect(url, stream);
    }
}

if (typeof document !== "undefined") {
    document.addEventListener("visibilitychange", () => {
        if (document.visibilityState === "visible") {
            reviveAll();
        }
    });
    window.addEventListener("online", reviveAll);
}

function subscribe(url, listener, authUrl) {
    let stream = streams.get(url);

    if (stream) {
        // A navigation is in flight, not a teardown — keep the connection.
        if (stream.closeTimer !== null) {
            clearTimeout(stream.closeTimer);
            stream.closeTimer = null;
        }
    } else {
        stream = {
            es: null,
            listeners: new Set(),
            closeTimer: null,
            retryTimer: null,
            attempt: 0,
            state: null,
            authUrl: authUrl ?? null,
        };

        streams.set(url, stream);
        setState(stream, STATE_CONNECTING);
        open(url, stream);
    }

    stream.listeners.add(listener);

    // A late subscriber (the sidebar mounting after the pane) would otherwise
    // see no state event until the next transition, and render as though the
    // connection were still being established.
    document.dispatchEvent(
        new CustomEvent(MERCURE_STATE_EVENT, {detail: {state: stream.state}}),
    );
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

        // A reconnect in flight has to go too, or it rebuilds a connection
        // nobody is listening to — and, because it re-enters open(), leaves an
        // EventSource behind that this teardown has already forgotten about.
        if (stream.retryTimer !== null) {
            clearTimeout(stream.retryTimer);
            stream.retryTimer = null;
        }

        if (stream.es) {
            stream.es.close();
        }

        streams.delete(url);
    }, 0);
}

export default class extends Controller {
    static values = {
        url: String,
        authUrl: String,
    };

    connect() {
        // Pin the URL used to subscribe so a value change can never release
        // some other stream's listener.
        this._subscribedUrl = this.urlValue;
        this._onUpdate = (data) => this._handleUpdate(data);

        subscribe(
            this._subscribedUrl,
            this._onUpdate,
            this.hasAuthUrlValue ? this.authUrlValue : null,
        );

        this._onState = (event) => this._onStateChange(event.detail.state);
        document.addEventListener(MERCURE_STATE_EVENT, this._onState);
    }

    disconnect() {
        unsubscribe(this._subscribedUrl, this._onUpdate);
        document.removeEventListener(MERCURE_STATE_EVENT, this._onState);
        this._stopPolling();
    }

    /**
     * While the stream is down, fall back to asking.
     *
     * Without this a dead stream is indistinguishable from a quiet mailbox:
     * the list has no other refresh path, so it simply stops changing. Polling
     * is strictly the worse mechanism — that is why it only runs when the good
     * one is unavailable — but a slow inbox beats a silently frozen one.
     *
     * A minute, not a few seconds: this is someone's Raspberry Pi with a single
     * PHP worker pool, and each tick is a full list render. The reconnect loop
     * is meanwhile still trying, so this rarely runs for long.
     */
    _onStateChange(state) {
        if (state === STATE_CONNECTED) {
            this._stopPolling();

            return;
        }

        if (state === STATE_OFFLINE) {
            this._startPolling();
        }
    }

    _startPolling() {
        if (this._pollTimer) {
            return;
        }

        this._pollTimer = setInterval(() => {
            // A backgrounded tab polling is pure waste: nobody is looking, and
            // the reconnect on visibilitychange will refresh it on return.
            if (document.hidden) {
                return;
            }

            this.dispatch("mailbox-synced", {detail: {poll: true}});
        }, POLL_INTERVAL_MS);
    }

    _stopPolling() {
        if (this._pollTimer) {
            clearInterval(this._pollTimer);
            this._pollTimer = null;
        }
    }

    // Update types come from the notifier services on the PHP side. All are
    // dispatched on <body>, so listeners nested deeper (the sidebar, the
    // insight strip) catch them bubbling up to document rather than via a
    // data-action.
    //
    // A type with no branch here is silently dropped, which is the one thing
    // to remember when adding a publisher: the hub delivers it, this method
    // ignores it, and the feature waiting for it simply never hears anything.
    // Nothing fails and nothing is logged.
    _handleUpdate(data) {
        if (data.type === "mailbox.synced") {
            this.dispatch("mailbox-synced", {detail: data});
        } else if (data.type === "account.synced") {
            this.dispatch("account-synced", {detail: data});
        } else if (data.type === "calendar.sync-finished") {
            // Published by App\Service\Calendar\CalendarNotifier on both the
            // success and the failure path. The account-health card is the
            // consumer: it asked for this sync by hand and has been saying
            // "started" ever since, and this is the only thing that can tell it
            // otherwise without a page load.
            this.dispatch("calendar-sync-finished", {detail: data});
        } else if (data.type === "rule.run") {
            // Published by RuleRunNotifier while "apply to existing mail"
            // walks the mailbox. Only a hint to re-read: the run's progress
            // lives on the MailRule row, so a missed message costs a stale
            // panel until the next load, never a wrong answer.
            this.dispatch("rule-run", {detail: data});
        } else if (data.type === "mail.send-outcome") {
            // The one branch here that renders rather than dispatches, and it
            // is deliberate: the payload carries a turbo-stream the SERVER
            // rendered, so the toast is _toast.html.twig itself rather than a
            // second copy of it living in JavaScript.
            //
            // Published by App\Service\Mail\SendOutcomeNotifier when the send
            // has actually happened. Until this existed, "Message sent." was
            // said by the browser when the cancel window closed — two seconds
            // before the send was even attempted — and a failure afterwards was
            // never mentioned at all.
            if ("string" === typeof data.stream && "" !== data.stream) {
                Turbo.renderStreamMessage(data.stream);
            }
        } else if (data.type === "insights.changed") {
            // Published by App\Service\Insight\InsightNotifier when a sync
            // actually landed insights for this user. The strip above the mail
            // list reloads its frame on it — a hint to re-read and nothing
            // more, so a missed message costs a strip that is one sync stale
            // rather than a wrong one.
            this.dispatch("insights-changed", {detail: data});
        }
    }
}
