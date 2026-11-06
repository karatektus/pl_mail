/**
 * plMail service worker — push delivery only.
 *
 * Served from the domain root so its scope covers the whole app. There is
 * deliberately no offline caching here: a mail client showing stale cached
 * mail is worse than one that says it is offline, and cache invalidation on
 * an authenticated app is a good way to leak one account's mail into another
 * session.
 *
 * Four kinds of payload arrive. The first two are JMAP objects (RFC 8620 §7):
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
 *   UpdateAvailable  — plMail's own too, sent by UpdateNotifier to
 *                      administrators when the update channel publishes a newer
 *                      build. Carries its own text for the same reason, and
 *                      opens Admin → Updates.
 */

const VERIFY_URL = "/settings/push/verify";
const INBOX_STATE_URL = "/settings/push/inbox-state";
const NEW_MAIL_TAG = "plmail-new-mail";
// How long a push waits for /settings/push/inbox-state before showing anyway.
const INBOX_STATE_TIMEOUT_MS = 5000;

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
        return;
    }

    if (payload["@type"] === "UpdateAvailable") {
        event.waitUntil(notifyUpdateAvailable(payload));
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

    // A user who asked to skip mail that is already read: wait, then look
    // again. The push cannot be recalled and says nothing about WHICH mail
    // arrived, so this is the only point where "read on the phone a minute
    // ago" can still be told apart from "new". Asked twice rather than once
    // because the first answer also carries the setting and the delay.
    let state = await inboxState();

    if (state && state.skipRead === true) {
        await new Promise((resolve) => setTimeout(resolve, Math.max(0, Number(state.delay) || 0) * 1000));

        // The reader may have come back to plMail while this waited.
        const now = await self.clients.matchAll({ type: "window", includeUncontrolled: true });

        if (now.some((client) => client.focused)) {
            return;
        }

        state = await inboxState();

        if (state && state.hasUnread === false) {
            await showNothing();

            return;
        }
    }

    await showNewMail();
}

function showNewMail() {
    return self.registration.showNotification("plMail", {
        body: "New mail",
        icon: "/icons/icon-192.png",
        badge: "/icons/icon-192.png",
        // One replaceable notification rather than a growing stack: the push
        // carries no per-message detail, so several would all say the same.
        tag: NEW_MAIL_TAG,
        renotify: true,
        data: { url: "/mail/inbox" },
    });
}

/**
 * The mail this push was about has been read: show nothing, and take down the
 * notification this worker showed for an earlier push, whose mail is read now
 * too.
 *
 * Except on WebKit (Safari, and every browser on an iPhone), which expects
 * every push to show a notification and may cancel the subscription of a site
 * whose pushes keep showing none. There one is shown silently and closed at
 * once, which is what it asks for and leaves nothing on screen. Chrome and
 * Firefox ask only for a notification to be on screen when the push is
 * handled, and closing it would earn the browser's own "updated in the
 * background" notice instead, so there nothing is shown. Not checked on an
 * iPhone yet: if WebKit counts a closed notification as none, the risk is the
 * one this setting already had.
 */
async function showNothing() {
    const shown = await self.registration.getNotifications({ tag: NEW_MAIL_TAG });
    shown.forEach((notification) => notification.close());

    if (!isWebKit()) {
        return;
    }

    await self.registration.showNotification("plMail", {
        body: "New mail",
        icon: "/icons/icon-192.png",
        tag: NEW_MAIL_TAG,
        silent: true,
    });

    const placeholder = await self.registration.getNotifications({ tag: NEW_MAIL_TAG });
    placeholder.forEach((notification) => notification.close());
}

/**
 * Safari on a Mac, or any browser on an iPhone or iPad — all of them WebKit.
 * Chrome on iOS calls itself CriOS, so "no Chrome in the name" catches it too.
 */
function isWebKit() {
    const agent = self.navigator.userAgent || "";

    return /AppleWebKit/.test(agent) && !/Chrome|Chromium|Android/.test(agent);
}

/**
 * Whether the mail a push was about is still unread, and whether this user
 * wants that checked (User::$notifySkipsRead). Null when it cannot be told —
 * offline, signed out, a server error, a server too slow to answer — and the
 * caller then shows the notification, because a missed notification is worse
 * than one for mail that is already read.
 *
 * Bounded by a timeout, because the notification waits for this answer even
 * for somebody who never switched the check on: a server that hangs must not
 * hold every notification back with it.
 */
async function inboxState() {
    try {
        const response = await fetch(INBOX_STATE_URL, {
            credentials: "same-origin",
            headers: { Accept: "application/json" },
            signal: typeof AbortSignal.timeout === "function" ? AbortSignal.timeout(INBOX_STATE_TIMEOUT_MS) : undefined,
        });

        return response.ok ? await response.json() : null;
    } catch (e) {
        return null;
    }
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

/**
 * A newer build on the update channel, told to an administrator once per build.
 *
 * Tagged by the build's commit, like the calendar alert is by its occurrence:
 * a push the browser replays after waking replaces the notification rather than
 * stacking a second one beside it, and renotify stays off so it does not buzz
 * twice for the same news.
 */
async function notifyUpdateAvailable(payload) {
    await self.registration.showNotification(payload.title || "plMail", {
        body: payload.body || "",
        icon: "/icons/icon-192.png",
        badge: "/icons/icon-192.png",
        tag: `plmail-update-${payload.tag || ""}`,
        renotify: false,
        data: { url: payload.url || "/admin?section=updates" },
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
