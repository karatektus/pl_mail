/**
 * plMail service worker — push delivery only.
 *
 * Served from the domain root so its scope covers the whole app. There is
 * deliberately no offline caching here: a mail client showing stale cached
 * mail is worse than one that says it is offline, and cache invalidation on
 * an authenticated app is a good way to leak one account's mail into another
 * session.
 *
 * Three kinds of payload arrive. The first two are JMAP objects (RFC 8620 §7):
 *   PushVerification — the handshake. The code is posted back so the
 *                      subscription becomes deliverable. This is why the SW
 *                      must exist before subscribing, not after.
 *   StateChange      — something moved. It carries no mail content by design,
 *                      so the notification is generic and the app fetches the
 *                      detail when opened.
 *   CalendarAlert    — plMail's own, sent by PushAlertChannel. Unlike the two
 *                      above it carries its own text, because a reminder that
 *                      makes you open the app to find out what it is about is
 *                      not a reminder. The payload is encrypted end to end
 *                      under the subscription's key (RFC 8291), so the push
 *                      service sees ciphertext.
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
        return;
    }

    if (payload["@type"] === "CalendarAlert") {
        event.waitUntil(notifyCalendarAlert(payload));
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

/**
 * A calendar alert, always shown.
 *
 * Deliberately NOT suppressed when a window is focused, which is the one thing
 * that differs from notifyStateChange above. That check exists because a mail
 * notification duplicates something the open page is about to render by itself
 * over Mercure; nothing renders an alert, and "the meeting starts in ten
 * minutes" is precisely the message somebody staring at a different tab needs.
 *
 * The tag is the alert's identity — event, alert key and occurrence — so a push
 * the browser replayed after waking up replaces the notification rather than
 * stacking a second copy of it beside the first. renotify is off for the same
 * reason: a replay must not buzz again.
 */
async function notifyCalendarAlert(payload) {
    await self.registration.showNotification(payload.title || "plMail", {
        body: payload.body || "",
        icon: "/icons/icon-192.png",
        badge: "/icons/icon-192.png",
        tag: `plmail-alert-${payload.tag || ""}`,
        renotify: false,
        data: { url: payload.url || "/calendar" },
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
