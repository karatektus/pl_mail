# Files and integrations

plMail can pull a file out of a service you already use and attach it to a message, and push an
attachment the other way into that service. Six services are supported: **Nextcloud**, **Immich**,
**Google Drive**, **Google Photos**, **OneDrive** and **Dropbox**.

Connections belong to one user and are revocable one at a time. Which services are offered at all
is an administrator's decision — see [Administration](admin.md).

## Connecting a service

**Settings → Integrations**. Services your administrator has enabled appear under **Connect a
service**; anything not enabled says *Your administrator has not enabled this service*, and
anything plMail cannot talk to yet is listed separately under **Not available here** with its setup
notes still readable.

How you connect depends on the service:

| Service | How it authenticates | What you supply |
|---|---|---|
| Nextcloud | App password | Server address, username, app password |
| Immich | API key | Server address, API key |
| Google Drive | Sign-in | Nothing — the consent screen |
| Google Photos | Sign-in | Nothing — the consent screen |
| OneDrive | Sign-in | Nothing — the consent screen |
| Dropbox | Sign-in | Nothing — the consent screen |

The app-password services also take a **Name**, which is only shown to you and exists so two
connections can be told apart. That is not decoration: you can legitimately have two Nextclouds,
home and work, and the button stays available after the first connection for exactly that reason.

If your administrator has pinned a server address for a self-hosted service, the address field is
absent rather than disabled, and a submitted value is ignored regardless.

For Nextcloud and Immich, create a credential on the service rather than using your login password.
Nextcloud puts app passwords under **Settings → Security → Devices & sessions**; Immich puts API
keys under **Account Settings → API Keys**. An app password is individually revocable and works
alongside two-factor authentication; a login password is neither.

Saving always probes the connection there and then. A connection that stores cleanly but cannot
list a folder is worse than a visible failure, because you would only find out halfway through
writing a message. The result is kept on the connection, so the list can say a connection has gone
stale before you next need it.

## Managing a connection

Each connected service offers **Test**, **Pause** / **Resume**, **Edit** and **Disconnect**.

**Test** re-probes on demand, for when the service was down or a credential was rotated at the far
end. **Pause** keeps the connection but takes it out of every menu. **Disconnect** removes it;
files already attached to mail are unaffected.

On the edit form the credential field is write-only. Leaving it blank keeps the stored one, so
renaming a connection does not mean re-pasting an app password you may no longer have.

## What each service can do

Not every service can do everything, and the interface keys off the capability rather than off the
service name — so a service that gains or loses an ability changes in exactly one place.

| | Browse | Download | Upload | Share link | Preview | Search | Timeline |
|---|---|---|---|---|---|---|---|
| Nextcloud | yes | yes | yes | yes | yes | yes | — |
| Google Drive | yes | yes | yes | yes | yes | yes | — |
| OneDrive | yes | yes | yes | yes | yes | yes | — |
| Dropbox | yes | yes | yes | yes | yes | yes | — |
| Immich | yes | yes | yes | — | yes | yes | yes |
| Google Photos | yes | yes | yes | — | yes | — | — |

The practical consequences: a service without **Upload** never appears in **Save to** or in the
`saveToIntegration` filter action; a service without **Share link** can only attach a copy; a
service without **Search** gets no search box in the picker. Immich is the only one that can
summarise its library as dates, which is what its scrubber is.

Google Photos has no text search because its Library API offers none — only album and date filters.

## Attaching from a service

In the compose window, **Attach from a service** opens the picker in a modal. It offers every
service you have connected that plMail can **download** from, since a service you can list but not
fetch from would open a picker that could attach nothing.

Folders are plain links inside the modal, so there is no client-side routing and the back button
behaves. Photo libraries render as a grid of previews and file stores as a list of names — nobody
recognises a photo by reading `IMG_4821.jpg`, and nobody picks a spreadsheet out of a wall of
thumbnails.

Previews are proxied through plMail rather than fetched by the browser, because services put
previews behind the same credential as the originals. Something with no preview at all — a zip, a
face crop that was never generated — gets a neutral placeholder rather than a failed request.

Each selected file is attached as a **Copy** or, where the service supports it, as a **Link**. A
copy pulls the bytes into the draft and counts against the **25 MB per file** attachment ceiling; a
link asks the service for a public URL and moves nothing.

Where a service is down, the picker says so where the files would have been. It does not answer
with an error page — a failing service is a fact about the connection, recorded on it for the
settings list to show, not a reason to stop rendering.

## Saving an attachment out

**Save to** on an attachment in a message uploads it to a connected service that supports
uploading. Where it lands is the service's own default — the files root, or no album — unless a
folder has been set on the connection.

This works for attachments plMail has never stored locally: a Gmail or Microsoft Graph attachment
is materialised on first access and uploads exactly like a locally stored one.

A filter can do the same thing automatically with the **Save attachments to** action; see
[Filters](filters.md).

## Where to read further

- [Administration](admin.md) — enabling services, registering OAuth applications, pinning a server
  address.
- [Filters](filters.md) — saving attachments to a service without being asked.
- [Mail](mail.md) — the compose window and the attachment ceiling.
- [Security model](../internals/security-model.md) — how stored credentials are encrypted.

## Things that bite

**A private or loopback address is refused unless it is allow-listed.** Self-hosted services live on
addresses an authenticated user could otherwise aim plMail's outbound HTTP client at — including
`localhost:5432` and the cloud metadata endpoint at `169.254.169.254`. Loopback, link-local, RFC1918
and carrier-grade NAT ranges are all blocked unless the host appears in `INTEGRATIONS_ALLOWED_HOSTS`.
On a home LAN this is the setting that makes Nextcloud and Immich reachable at all.

**`http://` is refused unless `INTEGRATIONS_ALLOW_HTTP` is on.** Self-hosting on a LAN without TLS is
an ordinary situation, so this flag will often be set — the point is that sending credentials in
plaintext becomes a deliberate decision rather than a silent default.

**A photo above the attachment ceiling cannot be attached from Immich or Google Photos at all.**
Neither can mint a public URL for a single item without creating a shared album, which is a heavier
side effect than attaching a file should have. So there is no link fallback: over 25 MB, the answer
is no.

**A newly registered Google Photos application usually cannot browse an existing library.** Google
restricted the Photos read scopes in March 2025; a new app is generally granted only
`appcreateddata`, which sees nothing but media plMail itself uploaded. Saving attachments into
Photos works regardless — browsing may show an empty library until the read scope is approved.

**Google Drive asks for the full `drive` scope, and that needs Google's verification.** The narrower
`drive.file` only ever sees files the application itself created or the user picked through Google's
own client-side picker, so a server-rendered browser would show an empty Drive — and a share link
needs write access to the file being shared.

**Pinning a server address retroactively overrides existing connections.** When an administrator
pins one, connections made before the pin stop using their own address; a stale row cannot keep
reaching somewhere else.

**A connection is re-checked when a filter uses it, not trusted from the stored rule.** A service
disconnected, paused or no longer capable of uploading makes the filter's save action a no-op with
a warning in the log, rather than an error the user sees.
