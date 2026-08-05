# Google

Gmail and Google Calendar both reach plMail through one OAuth application that you register in the
Google Cloud console. Google stopped accepting plain passwords for Gmail, so this registration is
not optional if you want a Gmail account in plMail — and because it is *your* Cloud project rather
than one operated by this software, everything on this page is something you do once, as the
administrator of your own installation.

Three things come out of that project, and they are worth separating before you start, because
people routinely set up the first and then wonder why the third does not work:

| What | Buys you | Needed? |
|---|---|---|
| An OAuth client | Signing in, reading and sending mail, and calendars | Yes, for any Gmail account |
| A Cloud Pub/Sub topic and subscription | Gmail arriving the instant it is sent | Optional; without it Gmail is checked every fifteen minutes |
| A verified domain | Calendar changes arriving as they happen | Optional; without it calendars are read every fifteen minutes |

Everything under **Settings** that this enables is described in
[Accounts and aliases](../features/accounts.md) and [Connected calendars](../features/calendar-sync.md).

## The Cloud project and the APIs on it

1. Open [console.cloud.google.com](https://console.cloud.google.com) and create a project, or pick
   an existing one. The project is the unit everything else here belongs to: the OAuth client, the
   consent screen, the Pub/Sub topic and the domain verification all live inside it, and using two
   projects for two halves of this is the most common way to end up with a consent screen that
   grants a scope the API has not been enabled for.
2. Under **APIs & Services → Library**, enable the **Gmail API**. Without it every call plMail makes
   after a successful sign-in is refused, which presents as an account that connects and then never
   syncs.
3. Enable the **Google Calendar API** on the same project if you want calendars. This is the step
   the admin screen means when it says there is nothing to set up on the plMail side — calendar
   access is bought entirely in this console.

Note your **project ID** while you are here. It is lower-case and may differ from the display name
you typed; the Pub/Sub topic below is named with the ID, not the name.

## The OAuth consent screen

Under **OAuth consent screen**, choose **Internal** if you have a Google Workspace organisation and
everyone signing in belongs to it, otherwise **External**. Fill in the application name and support
email. This is the screen your users read before they hand plMail access to their mailbox, so the
name you type is the name they will see; on a single-user install it is still worth making it
recognisable, because it is also what appears in the account's Google security settings afterwards.

Add the scopes listed in the next section. An **External** project starts in **Testing** status,
where only the accounts you list as test users may consent at all — which is the normal and correct
state for a self-hosted install serving a handful of people. Add yourself, and anyone else who will
connect a mailbox, as a test user.

## The OAuth client and its redirect URI

Under **Credentials**, create an **OAuth client ID** of type **Web application**, and add this to
its *Authorised redirect URIs*:

```
https://your-domain/oauth/google/callback
```

That path is a route in plMail (`/oauth/{provider}/callback` with `google` as the provider), and
Google matches it character for character against the address the browser was sent from — a
trailing slash, `http` instead of `https`, or the wrong hostname all fail with
`redirect_uri_mismatch` before plMail sees the request at all. You do not have to transcribe it:
**Admin → Integrations → Mail sign-in → Gmail sign-in** renders the exact URI for your installation
with a copy button beside it, built from your public address, and the setup wizard shows the same
thing. The screen's own warning is worth repeating — the URI cannot be changed later without
breaking every connection already authorised against it.

Copy the client ID and client secret. They can go in either of two places:

- **Admin → Integrations → Mail sign-in → Gmail sign-in**, which stores them in the database.
- `GOOGLE_OAUTH_CLIENT_ID` and `GOOGLE_OAUTH_CLIENT_SECRET` in the environment, which is what an
  older installation will already be doing. See the
  [configuration reference](../install/configuration.md).

A stored value wins over the environment, and only when it is actually set — a half-filled row
cannot shadow a working environment variable. The form refuses a client ID with no secret behind
it, because that combination looks configured and fails at the consent screen.

## The scopes, and what each one is for

plMail asks for these four at consent time, for every Gmail account:

| Scope | What it is for |
|---|---|
| `https://mail.google.com/` | The mailbox itself: reading messages, sending, applying and removing labels, moving and deleting. This is full mailbox access, and it is what a mail client is. |
| `https://www.googleapis.com/auth/calendar` | Calendars on the same account, read **and** write. |
| `openid` | Identifies the account the token belongs to. |
| `email` | Supplies the address, which is what plMail names the account after. |

The calendar scope is read-write rather than read-only on purpose. Subscribing to a calendar is only
half the feature: an event you edit in plMail is written back, which means creating, updating and
deleting on Google's side, and none of that is possible with a read-only grant.

The authorisation request also carries `prompt=consent` and an offline access type. Those are not
scopes; they are what makes Google return a **refresh token**. Without one, the connection works
until the first access token expires and then stops, and the only repair is reconnecting the
account.

### Calendar is on the same sign-in as mail

There is no second connection, no second OAuth application and no second consent screen for
calendars. The calendar scope is requested alongside the mail scopes on the one grant, so as the
administrator you tick an extra box in this console and there is nothing else to configure — which
is exactly what the admin screen tells you: *"Nothing to set up here. Enable the Google Calendar API
for this project and add the `.../auth/calendar` scope to the consent screen — users then get their
calendars with the same sign-in they already use for mail. Without it, mail keeps working and
calendars simply do not appear."*

The cost of putting both on one grant is that the consent screen asks for more up front, and
Google's consent screen lets a user untick an individual permission. A token can therefore come
back with mail access and no calendar access. plMail does not try to detect that at grant time; it
finds out when a calendar call is refused, and says so in the calendar settings — *"Google's consent
screen lets you untick calendar access while allowing mail. Reconnect the account and leave the
calendar box ticked."*

### What "restricted scope" verification means here

`https://mail.google.com/` grants access to the whole mailbox, and Google treats scopes of that
reach as restricted: an application that requests one and is **published** goes through Google's
verification review, and the same is true of the full `drive` scope plMail's Google Drive
integration uses. The app's own Drive setup notes say it plainly — Google requires app verification
before users outside your test list can consent.

For a self-hosted install this usually means you do not publish at all. Leave the project in
**Testing**, add the handful of addresses that will connect as test users, and consent works
immediately with an "unverified app" interstitial you click through. The trade is that Google
expires refresh tokens issued by an app in Testing after about a week, so an account connected this
way needs reconnecting periodically; publishing and going through verification is what removes
that, and is only worth it for an install with real users who are not you.

## Instant Gmail delivery, via Cloud Pub/Sub

Gmail does not call a webhook directly. It publishes to a **Cloud Pub/Sub** topic, and Pub/Sub
pushes from there to plMail, so instant delivery needs two cloud resources and a shared secret. This
is one-time setup for the whole installation, not per account.

Create the topic:

```bash
gcloud pubsub topics create gmail-push --project=YOUR_PROJECT_ID
```

Let Google's mail service publish to it. This grant is what makes Gmail able to tell your topic
anything at all:

```bash
gcloud pubsub topics add-iam-policy-binding gmail-push \
  --project=YOUR_PROJECT_ID \
  --member=serviceAccount:gmail-api-push@system.gserviceaccount.com \
  --role=roles/pubsub.publisher
```

Pick a secret of your own, then create the push subscription that delivers to plMail:

```bash
gcloud pubsub subscriptions create gmail-push-sub \
  --project=YOUR_PROJECT_ID \
  --topic=gmail-push \
  --push-endpoint="https://your-domain/gmail/push?token=YOUR_SECRET"
```

`POST /gmail/push` is the route that receives these. The token in the query string is the only thing
authenticating the endpoint — anyone on the internet can POST to it — so plMail compares it in
constant time and answers `403` to anything that does not carry it. It fails closed: with no token
configured, every notification is rejected.

Finally, tell plMail the same two values, either in
**Admin → Integrations → Mail sign-in → Gmail sign-in** (fields **Pub/Sub topic** and **Push
verification token**) or in the environment:

```ini
GMAIL_PUBSUB_TOPIC=projects/YOUR_PROJECT_ID/topics/gmail-push
GMAIL_PUBSUB_VERIFICATION_TOKEN=YOUR_SECRET
```

The stored values win and fall back to the environment, exactly as the OAuth credentials do.

Then turn on **Instant delivery** for the account under **Settings → Mail accounts**. It is per
account and opt-in: without the topic configured the account stays on the fifteen-minute schedule
and says so rather than failing.

A Gmail watch lasts at most seven days. plMail renews any watch expiring within the next day, from
a nightly sweep, so there is nothing to maintain by hand. **Admin → Gmail webhooks** is where to
look when mail is not arriving instantly: it shows the topic, the exact endpoint the subscription
must POST to — token included — the watch expiry, the last push received, and why any notification
was refused.

## Calendar push: watch channels, not Pub/Sub

Calendar push is a completely different mechanism from the one above, and none of the
`GMAIL_PUBSUB_*` configuration applies to it. plMail opens a **watch channel** on the calendar's
events, which is a plain webhook: Google POSTs to an address you own whenever anything in that
calendar changes. An installation with no Pub/Sub at all can have calendar push, and an
installation with Pub/Sub working perfectly can have no calendar push.

| | Gmail push | Google Calendar push |
|---|---|---|
| Mechanism | `users.watch` publishing to a Pub/Sub topic | `events.watch` channel, a plain webhook |
| Endpoint | `POST /gmail/push?token=…` | `POST /webhook/google/calendar` |
| Proof it is genuine | the token in the URL | the channel token in `X-Goog-Channel-Token` |
| Cloud resources | a topic, a publisher grant, a subscription | none |
| Extra requirement | — | the callback domain must be verified |
| Lifetime | seven days | a week, whatever Google grants |

Two things have to be true before a channel can be opened:

**A publicly reachable HTTPS callback.** The address is built from your configured public URL, never
from the incoming request, because the process that registers channels is a scheduled command with
no request to infer a hostname from. An address that is not `https://`, or that resolves to
`localhost`, is refused locally before a single API call is made. See
[Behind a reverse proxy](../install/reverse-proxy.md).

**Domain verification in the Cloud project that owns the OAuth client.** Verify the domain in Search
Console, then add it under **Domain verification** in the Cloud console. Until you do, every
`events.watch` is refused. This is the one thing about this feature you cannot discover from inside
plMail, which is why the admin screen states it: *"Google will not open a watch channel unless the
callback domain is verified in the Cloud project that owns the OAuth client… Microsoft needs no
equivalent step."*

**Neither of these breaks anything.** A calendar that cannot register a channel stays on the
fifteen-minute sweep, which is a working calendar that is at most fifteen minutes behind. The admin
screen says the same: *"Without a public HTTPS address, connected calendars simply stay on the
fifteen-minute sweep."* Registration failures are logged as warnings, not errors, and the scheduled
`app:calendar:push` sweep retries every hour — so an install that fixes its address or completes its
domain verification starts pushing within the hour, with nobody clicking anything.

You can also ask for it immediately:

```bash
docker compose exec php php bin/console app:calendar:push
```

### Calendars Google will not let you watch

Some calendars Google serves are generated rather than stored — a country's public holidays, the
birthdays drawn from Contacts, week numbers — and `events.watch` refuses every one of them with
`pushNotSupportedForRequestedResource`. That is a permanent fact about the calendar, so plMail
records it on the calendar, logs it once at info level, and stops asking. The calendar is polled
from then on and reads as polled in the interface, because that is exactly what it is.

## Things that bite

**The project ID is not the project name.** It is lower-case, may carry a numeric suffix, and is
what belongs in `GMAIL_PUBSUB_TOPIC`. A topic named after the display name resolves to nothing and
the watch registration fails with a message about a topic that does not exist.

**The redirect URI must match exactly, and cannot be changed afterwards.** Copy it from
**Admin → Integrations** rather than typing it. Changing it later breaks every connection already
authorised against it, which means every user reconnecting.

**A consent screen without `prompt=consent` returns a refresh token only once.** plMail always sends
it, so this bites the other way round: if you build your own authorisation URL while debugging and
omit it, the account connects, works for an hour, and then cannot refresh.

**Calendar access can be missing on an account whose mail works perfectly**, because Google's consent
screen lets the user untick it. The symptom is an account that appears under *Where calendars come
from* with no calendars on it. Reconnect and leave the box ticked.

**Enabling the Calendar API is not the same as adding the calendar scope**, and doing one without
the other fails in two different ways: the scope without the API is a 403 on every calendar call,
and the API without the scope is a token that was never granted calendar access.

**Domain verification is per Cloud project, not per Google account.** Verifying the domain in Search
Console is only the first half; it also has to be added under **Domain verification** in the same
Cloud project that owns the OAuth client, or `events.watch` keeps being refused with the domain
apparently verified.

**The first notification on a new channel is a handshake, not a change.** Google sends
`X-Goog-Resource-State: sync` the moment a channel opens, meaning only "the channel is open".
plMail ignores it deliberately — acting on it would queue a full calendar read for every
registration and every weekly renewal in the install.

**An app left in Testing expires its refresh tokens after about a week.** The account keeps working
until then and stops without warning afterwards; reconnecting fixes it. This is Google's policy for
unpublished apps requesting scopes of this reach, not something plMail can work around.

**The Pub/Sub token has to match character for character.** plMail rejects notifications that do not
carry it, and the rejection is logged rather than silent — **Admin → Gmail webhooks** will show
refused notifications, which is the difference between "Pub/Sub is not reaching us" and "Pub/Sub is
reaching us and being turned away".
