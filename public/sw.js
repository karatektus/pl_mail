/**
 * plMail service worker — push delivery only.
 *
 * Served from the domain root so its scope covers the whole app. There is
 * deliberately no offline caching here: a mail client showing stale cached
 * mail is worse than one that says it is offline, and cache invalidation on
 * an authenticated app is a good way to leak one account's mail into another
 * session.
 *
 * Two kinds of payload arrive, both JMAP objects (RFC 8620 §7):
 *   PushVerification — the handshake. The code is posted back so the
 *                      subscription becomes deliverable. This is why the SW
 *                      must exist before subscribing, not after.
 *   StateChange      — something moved. It carries no mail content by design,
 *                      so the notification is generic and the app fetches the
 *                      detail when opened.
 */

const VERIFY_URL = "/settings/push/verify";

self.addEventListener("install", () => {
    // Take over immediately; there is no old cache to drain.
    self.skipWaiting();
});

self.addEventListener("activate", (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener("push", (event) => {
    let payload = null;

    try {
        payload = event.data ? event.data.json() : null;
    } catch (e) {
        payload = null;
    }

    if (!payload) {
        return;
    }

    if (payload["@type"] === "PushVerification") {
        event.waitUntil(confirmVerification(payload));
        return;
    }

    if (payload["@type"] === "StateChange") {
        event.waitUntil(notifyStateChange(payload));
    }
});

/**
 * Completes the JMAP push handshake. same-origin credentials carry the session
 * cookie, which is what authorises the call.
 */
async function confirmVerification(payload) {
    try {
        await fetch(VERIFY_URL, {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                pushSubscriptionId: payload.pushSubscriptionId,
                verificationCode: payload.verificationCode,
            }),
        });
    } catch (e) {
        // Nothing useful to do in a worker; the user can re-enable in settings.
    }
}

/**
 * A StateChange says only that a token moved. Notify only when Email changed —
 * a Mailbox or Thread move alone is not worth waking someone for.
 */
async function notifyStateChange(payload) {
    const changed = payload.changed || {};
    const touchesEmail = Object.values(changed).some(
        (types) => types && Object.prototype.hasOwnProperty.call(types, "Email"),
    );

    if (!touchesEmail) {
        return;
    }

    // If a window is already open and focused, the UI updates itself over
    // Mercure — a notification would just be noise.
    const clients = await self.clients.matchAll({ type: "window", includeUncontrolled: true });

    if (clients.some((client) => client.focused)) {
        return;
    }

    await self.registration.showNotification("plMail", {
        body: "New mail",
        icon: "/icons/icon-192.png",
        badge: "/icons/icon-192.png",
        // One replaceable notification rather than a growing stack: the push
        // carries no per-message detail, so several would all say the same.
        tag: "plmail-new-mail",
        renotify: true,
        data: { url: "/mail/inbox" },
    });
}

self.addEventListener("notificationclick", (event) => {
    event.notification.close();

    const target = (event.notification.data && event.notification.data.url) || "/mail/inbox";

    event.waitUntil(
        self.clients
            .matchAll({ type: "window", includeUncontrolled: true })
            .then((clients) => {
                for (const client of clients) {
                    if (client.url.includes(target) && "focus" in client) {
                        return client.focus();
                    }
                }

                return self.clients.openWindow(target);
            }),
    );
});
