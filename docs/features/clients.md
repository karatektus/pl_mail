# Other clients

plMail speaks JMAP, so a third-party mail app can read and send through it without going near your
provider's credentials. It is also a progressive web app, so the browser you already use can behave
like an installed client and notify you of new mail with the tab closed.

## Connecting a JMAP client

Two things: an app password, and one URL.

1. **Settings → App passwords → Generate**, named for the app you are connecting. The secret is
   shown once and looks like `plmail_` followed by 64 hex characters.
2. Give the app your **email address** as the username and the **app password** as the password.
3. If it asks for a server or JMAP URL, give it the session address, which the settings page prints
   for you:

   ```
   https://your-domain/jmap/session
   ```

Give it the **session** address, not `/jmap/api`. The API endpoint only accepts POST, so a client
that has been pointed at it will fail its first request in a way that looks like a broken server.
Everything else — the API URL, the upload and download URLs, the event-source URL — is discovered
from the session object, which is exactly how a JMAP client is meant to find them.

`https://your-domain/.well-known/jmap` answers the same thing, for clients that look there.

Credentials go over `Authorization` as either a bearer token or HTTP Basic. With Basic, the username
is genuinely checked against the token's owner — a wrong address is rejected rather than silently
operating as whoever owns the token.

The JMAP firewall is stateless and holds no session, and two-factor authentication does not apply to
it. That is why app passwords exist: a mail client has no way to present a six-digit code. Access is
withdrawn by revoking the app password, not from the Security page.

An app password is **user-scoped**. One credential enumerates every mail account you have connected,
matching the JMAP session object, which is why a client shows all of them at once.

## Pairing a device

**Settings → App passwords → Pair a device** shows a QR code for a plMail app to scan. The app
exchanges it for an app password of its own, which then appears in the list under whatever name the
device gave — so somebody with four phones can tell which one to revoke.

The code works **once**, expires after **two minutes**, and carries no credential itself. It is
issued from behind your session; the exchange is not, because a device that could already
authenticate would not need to pair.

## What a client can expect

`Mailbox`, `Email`, `Thread`, `EmailSubmission`, `Identity` and `PushSubscription` are implemented,
along with search snippets and a calendar extension of plMail's own. The full, current list —
including what is deliberately absent, how the id spaces work, and the four object mappings that
surprise people — is in **[Client development](../CLIENT_DEVELOPMENT.md)**, which is the
protocol-level reference and the thing to read before writing anything against this server. Do not
work from this page for that; work from there.

Two notes worth having here rather than there:

- The server is under active development, and the client guide's opening section says what that
  means for your app. Read it first — including its standing invitation to ask for a method rather
  than engineer around its absence.
- Calendars are advertised under a **vendor** capability, `urn:plmail:params:jmap:calendars`, rather
  than the IETF calendars URN. JMAP for Calendars is an unratified draft whose object shape is still
  moving, so a vendor URN says what is true: this is plMail's calendar surface, and only something
  written for plMail should use it.

## The web app

plMail ships a web app manifest, so any browser that supports installing one can add it to a home
screen or a dock. It opens standalone, on the inbox.

On iPhone and iPad this is not optional if you want notifications: Safari only offers them once the
app has been added to the Home Screen. Open plMail in Safari, tap Share, then **Add to Home
Screen**, and enable notifications from there.

The service worker deliberately caches **nothing**. A mail client showing stale cached mail is worse
than one that says it is offline, and cache invalidation on an authenticated app is a good way to
leak one account's mail into another session.

## Browser notifications

**Settings → Notifications** turns them on for the device you are sitting at. Each device is
separate — the toggle is about **This device** and says so.

The states it reports:

| State | Means |
|---|---|
| **On — this device gets notified of new mail.** | Registered and verified |
| **Waiting for this device to confirm…** | The verification handshake has not come back yet |
| **Off — this device will not be notified.** | Not registered |
| **Blocked in your browser settings.** | Allow notifications for the site, then try again |
| **This browser cannot receive notifications.** | On iPhone and iPad, add plMail to the Home Screen first |
| **Push is not configured on this server.** | No VAPID keys — see below |

Registration is a genuine handshake, not a claim. plMail sends a verification code to the endpoint
the browser gave it, the service worker posts the code back, and only then is the device
deliverable. First-party or not, the guarantee is the same one a third-party client gets: the
endpoint provably reaches this user's device.

Re-subscribing from the same browser replaces its registration rather than piling up dead endpoints
— browsers rotate the endpoint URL, and plMail keys on an id the browser keeps.

A notification about mail carries **no mail content**. It is a JMAP `StateChange` object, which says
that something moved and nothing about what, so the app fetches the detail when it is opened.
Calendar reminders are the exception: they carry their own text, because a reminder that makes you
open the app to find out what it is about is not a reminder. That payload is encrypted end to end
under the subscription's own key, so the push service sees ciphertext.

If the server has no VAPID keys, nothing here works and the page says so. Generating them is one
command:

```
docker compose exec php php bin/console app:push:generate-vapid-keys
```

## Where to read further

- [Client development](../CLIENT_DEVELOPMENT.md) — the protocol reference: methods, filters, id
  spaces, push, and the behaviour a client is expected to implement.
- [JMAP](../internals/jmap.md) — what is implemented, what is deliberately not, and why.
- [Security](security.md) — app passwords, revocation, and what two-factor authentication does not
  cover.
- [Configuration reference](../install/configuration.md) — `APP_PUBLIC_URL` and the VAPID keys.

## Things that bite

**Pointing a client at `/jmap/api` instead of `/jmap/session` fails in a confusing way.** The API
endpoint only accepts POST. The settings page prints the address to use; copy it from there.

**Two-factor authentication does not protect `/jmap`, and cannot.** Revoking an app password is the
only way to withdraw a client's access.

**An app password reaches every account you have connected.** It is scoped to you, not to one
mailbox, so revoking it disconnects the app from all of them at once — and there is no way to hand a
client only one account.

**The secret is shown once, and there is no second chance.** Not from a screen, not from the
database. Revoke and generate another.

**Notifications need the service worker registered before you subscribe, not after.** The
verification code arrives as a push message, so there has to be something there to receive it — which
is why the sequence is fixed and why a subscription can sit in "waiting to confirm" if the worker
never came up.

**A device that never confirms stays undeliverable.** The state is honest about it rather than
claiming success, but nothing retries on its own; toggling notifications off and on re-issues the
handshake.

**Push notifications require a public HTTPS address.** Without one the browser's push service cannot
reach this server, whatever the toggle says locally.
