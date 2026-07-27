import { Controller } from "@hotwired/stimulus";

/**
 * Browser push registration for the plMail PWA.
 *
 * Registers the service worker, subscribes via the Push API using the server's
 * VAPID public key, and hands the resulting endpoint to the server. The
 * verification handshake completes inside the service worker, so this
 * controller polls status briefly to reflect it.
 *
 * Values:
 *   vapidKey  — base64url VAPID public key from the JMAP Session capability
 *   statusUrl — GET .../status/{deviceClientId}
 */
export default class extends Controller {
    static targets = ["toggle", "state"];

    static values = {
        vapidKey: String,
        subscribeUrl: String,
        unsubscribeUrl: String,
        statusUrl: String,
    };

    connect() {
        this.deviceClientId = this._deviceClientId();

        if (false === this._supported()) {
            this._setState("unsupported");

            return;
        }

        this._refreshStatus();
    }

    disconnect() {
        clearTimeout(this._pollTimer);
    }

    async toggle() {
        if (true === this._enabled) {
            await this._disable();

            return;
        }

        await this._enable();
    }

    async _enable() {
        this._setState("working");

        // Must be requested from a user gesture — this handler is one.
        const permission = await Notification.requestPermission();

        if ("granted" !== permission) {
            this._setState("denied");

            return;
        }

        try {
            const registration = await navigator.serviceWorker.register("/sw.js", { scope: "/" });
            await navigator.serviceWorker.ready;

            // An existing subscription may be bound to a rotated VAPID key, in
            // which case subscribe() throws; dropping it first is cheaper than
            // parsing the error.
            const existing = await registration.pushManager.getSubscription();

            if (existing) {
                await existing.unsubscribe();
            }

            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: this._urlBase64ToUint8Array(this.vapidKeyValue),
            });

            const json = subscription.toJSON();

            const response = await fetch(this.subscribeUrlValue, {
                method: "POST",
                credentials: "same-origin",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    endpoint: json.endpoint,
                    keys: json.keys,
                    deviceClientId: this.deviceClientId,
                }),
            });

            if (false === response.ok) {
                this._setState("error");

                return;
            }

            // Verification lands via the service worker moments later.
            this._setState("pending");
            this._pollStatus(6);
        } catch (e) {
            this._setState("error");
        }
    }

    async _disable() {
        this._setState("working");

        try {
            const registration = await navigator.serviceWorker.getRegistration("/");
            const subscription = registration ? await registration.pushManager.getSubscription() : null;

            if (subscription) {
                await subscription.unsubscribe();
            }

            await fetch(this.unsubscribeUrlValue, {
                method: "POST",
                credentials: "same-origin",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ deviceClientId: this.deviceClientId }),
            });

            this._enabled = false;
            this._setState("off");
        } catch (e) {
            this._setState("error");
        }
    }

    async _refreshStatus() {
        try {
            const response = await fetch(`${this.statusUrlValue}/${encodeURIComponent(this.deviceClientId)}`, {
                credentials: "same-origin",
            });

            if (false === response.ok) {
                return;
            }

            const status = await response.json();

            if (false === status.configured) {
                this._setState("unconfigured");

                return;
            }

            this._enabled = status.verified;
            this._setState(status.verified ? "on" : status.registered ? "pending" : "off");

            return status;
        } catch (e) {
            return null;
        }
    }

    /** Verification is asynchronous, so give it a few seconds before giving up. */
    _pollStatus(attemptsLeft) {
        if (attemptsLeft <= 0) {
            return;
        }

        this._pollTimer = setTimeout(async () => {
            const status = await this._refreshStatus();

            if (status && true === status.verified) {
                return;
            }

            this._pollStatus(attemptsLeft - 1);
        }, 1000);
    }

    _setState(state) {
        if (true === this.hasStateTarget) {
            this.stateTarget.dataset.pushState = state;
        }

        if (true === this.hasToggleTarget) {
            this.toggleTarget.dataset.pushState = state;
            this.toggleTarget.disabled = ["unsupported", "unconfigured", "working"].includes(state);
        }
    }

    _supported() {
        return (
            "serviceWorker" in navigator &&
            "PushManager" in window &&
            "Notification" in window &&
            window.isSecureContext
        );
    }

    /** Stable per browser profile, so re-subscribing replaces its own row. */
    _deviceClientId() {
        const key = "plmail.deviceClientId";
        let id = window.localStorage.getItem(key);

        if (!id) {
            id = `web-${crypto.randomUUID()}`;
            window.localStorage.setItem(key, id);
        }

        return id;
    }

    /** applicationServerKey must be a Uint8Array, not the base64url string. */
    _urlBase64ToUint8Array(base64) {
        const padding = "=".repeat((4 - (base64.length % 4)) % 4);
        const normalised = (base64 + padding).replace(/-/g, "+").replace(/_/g, "/");
        const raw = window.atob(normalised);

        return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
    }
}
