# Building a client for plMail

Everything an engineer (or an agent) needs to write a *new* plMail client — a native iOS or Android
app, a desktop app, a CLI, another web front end — without reading the whole Symfony codebase first.

It covers three things, in this order:

1. **What plMail is**, and the design philosophy a client is expected to inherit.
2. **How it should look and feel** — the visual system, the layout rules, the motion, the copy.
3. **How it behaves, and the API it behaves through** — JMAP, auth, push, blobs, search.

For installing and running the server see [README.md](../README.md). For developing the *server* see
[CONTRIBUTING.md](../CONTRIBUTING.md). This document assumes the server already runs somewhere.

---

## 0. Read this first: the server is under active development

**plMail is being actively developed, and the server side is fully in scope for your client's needs.**
This document describes what exists *today*. It is a snapshot, not a fixed contract, and several
things it lists as absent are absent only because nothing has needed them yet.

So, when you hit a wall — a missing JMAP method, an endpoint that doesn't expose a field you need, a
concept that lives in the database but not in the API, a limit that's wrong for mobile:

> **Ask. Do not work around it.**
>
> Adding the endpoint, the method, the field or the vendor extension is a completely normal outcome
> and usually the *right* one. A client-side workaround that reconstructs missing server behaviour is
> almost always the wrong answer: it duplicates logic that belongs in one place, it drifts from the
> web UI, and it quietly becomes load-bearing.

Concretely, **stop and ask before** you:

- reimplement server logic client-side because the API doesn't expose it;
- scrape or drive the HTML / Turbo Stream routes because JMAP lacks something;
- poll aggressively to compensate for a missing change-tracking method;
- invent local state (custom keywords, shadow flags, local-only labels) that can't round-trip;
- denormalise or cache something in a way that would break if the server later did it properly.

Things this document flags as **not implemented** — `Email/queryChanges`, anchor paging, JMAP
Contacts, JWT issuance, a cross-account unified query — are all *candidates for
being built*, not permanent constraints. Raise the need with the maintainer and decide together
whether it belongs in the server or the client.

**The calendar was the example of exactly this, and it is now the example of it working.** This
section used to say there was no JMAP calendar API and that a `using` containing a calendar URN was
rejected outright. There is one: `Calendar/get`, `CalendarEvent/get`, `CalendarEvent/query` and
`CalendarEvent/set`, under `urn:plmail:params:jmap:calendars`, advertised in `Capability::SUPPORTED`
and served from exactly one account. See [JMAP](internals/jmap.md) for the id spaces and the two
things that surprise people — a `CalendarEvent` id is the *series* rather than a dated occurrence,
and `CalendarEvent/query` requires a date window.

If you are building something that wants events, **say so** — the storage is already JSCalendar
precisely so the API can be JSCalendar, and the shape of the methods (`Calendar/get`,
`CalendarEvent/query`, `/changes` against the existing `StateManager`) is settled. Two things to
know before you ask:

- JMAP for Calendars is still an IETF **draft**, so when this ships it will advertise a vendor URN
  (`urn:plmail:params:jmap:calendars`), following the precedent already set by
  `urn:plmail:params:jmap:push`. Do not hard-code the draft's URN.
- Do **not** read `calendar_event.jscalendar` through some side channel, and do not reconstruct
  recurrence client-side. Occurrences are materialised server-side to a bounded horizon for good
  reasons (an unbounded `FREQ=DAILY` has no last instance), and a client that expands rules itself
  will disagree with the web UI at DST boundaries and on overridden instances.

The corollary: **when you read something surprising here, check it against the code before designing
around it.** `src/Jmap/` is the authority, and it moves.

---

## 1. The product

### What plMail is

plMail is a **self-hosted mail client**. It runs on a machine the user owns — a NAS, a home server, a
small VPS — connects to the mailboxes they already have (IMAP, Gmail, Outlook/Microsoft 365), and
syncs every message into a local PostgreSQL database.

It is emphatically **not a mail server**. It does not receive mail from the outside world, host a
domain, or run MX. It is the *client layer*: one interface, one search box, one set of labels across
however many providers the user has.

### What that implies for your app

Three consequences drive almost every client-side decision:

**The server is the source of truth, and it is fast.** Mail is already in Postgres, indexed, threaded
and full-text searchable. Your app should not build a competing sync engine against Gmail or IMAP —
it talks to plMail, and plMail talks to the providers. A client that reaches around the server breaks
the single-database promise the whole product is built on.

**The server is *the user's* server.** It may be on a home LAN, behind Tailscale, or on a slow ADSL
uplink. It may be briefly unreachable when the NAS reboots. Assume: variable and sometimes high
latency, occasional self-signed or private-CA certificates, no CDN, no global anycast, and a single
PHP worker pool that a badly-behaved client can genuinely exhaust. Cache aggressively, poll rarely,
degrade gracefully, and never busy-loop.

**Multiple accounts are the normal case, not the edge case.** A user with a work Gmail, a personal
IMAP and an Outlook account is the target user. The unified inbox is the default view. Every screen
in your app should be designed multi-account first and single-account second.

### Design philosophy

The server codebase is unusually opinionated, and the opinions are worth inheriting because they are
what make the product feel coherent.

- **Gmail is the vocabulary, not the aesthetic.** Labels rather than folders. Conversations rather
  than messages. A single search box with `from:`/`is:`/`has:` operators. Users arriving from Gmail
  should not have to learn new nouns. But the *look* is plMail's own — softer, calmer, more
  configurable.
- **Everything is themable, and the theme is the user's.** Colour, density, corner radius,
  translucency, background — all user-set, all synced server-side. A client that hardcodes its
  palette is wrong. See [§2](#2-look-and-feel).
- **Labels are the user-facing concept; mailboxes are plumbing.** IMAP folders exist as sync
  infrastructure. The user sees labels, and a message can carry several. Do not surface folders.
- **Nothing is ever hard-deleted.** "Delete" means "move to Trash". The server has no hard-delete
  path at all — deleting a row would discard the local copy of mail the provider still holds. Your
  destructive UI should say Trash, and should be undoable.
- **Comments explain *why*, and so should your UI.** The server code is full of "this looks odd
  because X bit us". Carry that spirit into empty states and error messages: say what happened and
  what the user can do, never just "Error".
- **Local first, provider second.** Actions apply locally and then propagate outward to
  Gmail/IMAP/Graph. Your UI can and should be optimistic.

### Prior art in the repo

Before you invent a screen, look at how the web UI does it. The most instructive files:

| What | Where |
|---|---|
| Design tokens, utilities, theme blocks | [assets/styles/app.css](../assets/styles/app.css) |
| The app shell (viewport, PWA, theme bootstrapping) | [templates/\_layout/app.html.twig](../templates/_layout/app.html.twig) |
| The list row anatomy | [templates/\_partials/\_thread\_row.html.twig](../templates/_partials/_thread_row.html.twig) |
| Mobile list ⇄ reading-pane behaviour | [assets/controllers/mail/mail\_pane\_controller.js](../assets/controllers/mail/mail_pane_controller.js) |
| Drawer / icon-rail sidebar | [assets/controllers/ui/sidebar\_drawer\_controller.js](../assets/controllers/ui/sidebar_drawer_controller.js) |
| Screenshots of the real thing | [docs/screenshots/](screenshots/) |

---

## 2. Look and feel

### The two-axis appearance model

plMail separates **Theme** (the palette) from **Layout** (the treatment). Every theme composes with
every layout. On top of both sit numeric knobs the user can override individually.

**Theme** — [`App\Domain\Enum\Theme\Theme`](../src/Domain/Enum/Theme/Theme.php)

| Theme | Surface | Ink | Accent | Dark? |
|---|---|---|---|---|
| `system` | follows OS | — | `#2563eb` | follows OS |
| `light` | `#ffffff` | `#27272a` | `#2563eb` | no |
| `dark` | `#111827` | `#f4f4f5` | `#3b82f6` | yes |
| `nord` | `#2e3440` | `#eceff4` | `#88c0d0` | yes |
| `dusk` | `#1e1b2e` | `#ede9fe` | `#a78bfa` | yes |
| `solar` | `#fdf6e3` | `#586e75` | `#b58900` | no |

**Layout** — [`App\Domain\Enum\Theme\Layout`](../src/Domain/Enum/Theme/Layout.php)

| Layout | Radius | Pane blur | Pane alpha | Character |
|---|---|---|---|---|
| `flat` (default) | 0.75rem | 0 | 1.0 | Chrome sits straight on the background; one opaque content card. |
| `boxed` | 1.0rem | 24px | 0.7 | Everything is a floating translucent card over the background. |

Selecting a layout *seeds* the knobs below; the user can then override each one.

**Density** — [`App\Domain\Enum\Theme\Density`](../src/Domain/Enum/Theme/Density.php)

| Density | Row padding (block) | Gap |
|---|---|---|
| `comfortable` (default) | 0.875rem | 0.75rem |
| `cosy` | 0.625rem | 0.5rem |
| `compact` | 0.375rem | 0.375rem |

**User knobs** — see [`Appearance`](../src/Entity/Embeddable/Appearance.php) for the authoritative
list and clamps:

| Field | Type | Range | Meaning |
|---|---|---|---|
| `accent` | hex | `#rrggbb` | Accent colour. Default `#2563eb`. |
| `paneAlpha` | float | 0.15 – 1.0 | Opacity of card surfaces. |
| `paneBlur` | int | 0 – 60 | Backdrop blur in px. |
| `radius` | float | 0.0 – 2.0 | Corner radius in rem, for *panes only*. |
| `scrimAlpha` | float | 0.0 – 0.7 | Black scrim over a custom background image. |
| `inkColor` / `inkMuted` / `inkFaint` | hex\|null | — | Text colour overrides. |
| `mainTint` / `mainAlpha` | hex\|null / float\|null | — | Tint and opacity of the main content pane specifically. |
| `backgroundKind` | enum | `theme` \| `preset` \| `solid` \| `custom` | Where the app background comes from. |
| `backgroundPreset` / `backgroundSolid` / `backgroundFile` | — | — | The chosen background. |

`Appearance::toArray()` is the export format (versioned, `version: 1`), and `applyArray()` the
import. The web UI lets users export/import this as a file.

> **None of this is reachable over JMAP today.** `Appearance` hangs off the `User` entity and is
> served only to the web UI; there is no JMAP method that returns it. So a client cannot literally
> honour the user's server-side theme yet — see [§4](#feature-parity-checklist). What it *can* do,
> and should, is model the same two-axis Theme×Layout shape with the same semantic tokens and drive
> it from local settings, so that when a `UserPreferences`-style vendor method lands it changes
> where the values come from and nothing else. The rule being enforced is "don't hardcode a
> palette", and that rule bites with or without the network fetch. If you need this, ask — the
> export format already exists, so it is a small addition.

> **Radius applies to panes, not controls.** Modals, the compose window, dropdowns, menus and toasts
> take `--app-radius`. Buttons, inputs, chips and list rows keep a *fixed* small radius — they must
> not grow to 2rem corners. This distinction is deliberate and easy to get wrong.

### Semantic colour tokens

Never reference raw palette values. Build your client against the same semantic token set the CSS
uses, so a theme change re-resolves everything at once. The canonical list lives in the
`@theme inline` block of [app.css](../assets/styles/app.css):

| Token | Use |
|---|---|
| `surface` | Card / pane background. |
| `line` | Hairline dividers (very low alpha). |
| `raised` / `hover` | Subtle raised fills and hover states. |
| `ink` / `ink-soft` / `ink-muted` / `ink-faint` | Text, in four decreasing weights of emphasis. |
| `accent` / `accent-strong` / `accent-soft` / `accent-ink` | The accent and its variants. |
| `sunken` | Recessed wells (inputs backgrounds, code blocks). |
| `field` / `field-border` | Form controls. |
| `danger` / `warning` / `success` / `info` | Status. Each has a `-soft` background variant. |
| `inverse` / `inverse-ink` | Tooltips and inverted chips. |

Composed surfaces: `pane` (card with border + shadow), `pane-flat` (no shadow), `popover` (**fully
opaque** — a translucent dropdown over a photo grid is illegible), `main-pane` (the content card,
respects `mainTint`/`mainAlpha`), and `app-bg` (the gradient/image background plus scrim).

### The mail sheet — where the theme stops, and how it stops

Rendered mail bodies do not take the app's palette. Mail arrives authored for a white background, so
handing it a dark surface produces black text on black. The web UI's `mail-sheet` utility redeclares
the palette channels locally so *everything inside* — including your own chrome, if you nest any —
resolves to light values.

**On the web that means a permanently light sheet.** On a phone it cannot: a mail app whose reading
pane is the one screen that stays white at night is not acceptable, and users will say so.

So a native client should render dark — but **not by inverting everything**, which is the approach
that reliably looks broken. Photographs come out as negatives, logos come out in the wrong brand
colours, and a message that already ships its own dark styles double-inverts into something worse
than either extreme.

Choose a strategy per message, from what its HTML declares about itself:

| The message | What to do |
|---|---|
| Brings no colours of its own — a typed reply, most personal mail | **Restyle it** in your dark palette. Nothing is inverted, so nothing can look like a negative. This is the best available result. |
| Has a palette of its own — newsletters, anything designed | **Invert with `hue-rotate(180deg)`**, then invert `img`, `picture`, `video`, `svg` and background-image elements *back*. That second rule is the one everyone forgets, and skipping it is what gives inversion its reputation. |
| Already declares `prefers-color-scheme` | Tell it the scheme is dark and **leave it alone.** The sender did the work. |
| Any of the above, in light appearance | Render exactly as sent. |

Two things follow. **Offer a way back to the original** wherever you transformed a message —
inversion gets some mail wrong, and being told a mangled message is fine is worse than seeing that it
was mangled. And note `invert`+`hue-rotate` is a matrix approximation rather than a true HSL
rotation, so round-tripped colours come back slightly desaturated; that is the cost of the technique.

What has not changed: **never pass the user's *theme* into the message renderer.** The message gets
one of the treatments above, not the accent colour, the pane alpha or the background image.

### Layout and navigation

**Desktop / tablet (≥768px)** — three regions:

```
┌──────────────────────────────────────────────┐
│ topbar: search, sync, account, settings      │
├────────────┬─────────────────────────────────┤
│ sidebar    │ list        │ reading pane      │
│ Compose ▸  │ (threads)   │ (thread)          │
│ Inbox   12 │             │                   │
│ Starred    │             │                   │
│ Sent       │             │                   │
│ Labels…    │             │                   │
└────────────┴─────────────────────────────────┘
```

The sidebar collapses to a **56px icon rail** (state persisted; on web it is applied before first
paint so the wide sidebar never flashes). Active and hovered nav rows use a Gmail-style pill that
runs off the left edge and caps with a full radius on the right.

**Mobile (<768px)** — the sidebar becomes a **slide-in drawer over a backdrop**, and list/reading
become **two stacked panes**: tapping a row replaces the list with the thread, and Back returns to
the list. On the web this is done with `history.pushState`, so the hardware/browser Back button works
naturally — a native client should map this to a standard navigation stack push.

Compose on mobile is **fullscreen**; on desktop it is a **docked window** in the bottom-right
(`fixed bottom-4 right-6`), and several can be open at once.

### The list row

From [`_thread_row.html.twig`](../templates/_partials/_thread_row.html.twig), one row shows:

- **Participants** — everyone who has written in the conversation, oldest first. Not the newest
  sender; that made every thread you had answered look like it came from you.
- **Subject**, falling back to a translated "(no subject)".
- **Snippet** — first ~100 chars of the latest message's plain-text body, tags stripped.
- **Message count** when > 1.
- **Date** — last message time.
- **State affordances**: unread (weight/indicator), starred, attachment paperclip.
- **Hover actions** (desktop): archive, trash, snooze, mark read/unread, label.

Unread and starred are exposed as `data-unread` / `data-starred` on the row, so styling keys off
state rather than duplicated classes. Mirror that: one row component, state-driven.

**Draft rule (subtle, get it right):** a row opens the *compose* surface instead of the reading pane
only when the row **is** the draft — i.e. a thread holding a single draft message, or a bare draft
message row. A real conversation carrying an unsent reply still opens the thread, and that draft is
edited from inside the reading pane. In the Drafts list this is overridden: every row there opens its
draft.

### Motion, gesture and touch

- **Motion is functional, not decorative.** Drawer slide, pane transition, toast in/out. No spring
  physics, no parallax, no hero animations on mail rows.
- **Honour reduced-transparency.** The CSS forces `paneAlpha: 1`, `paneBlur: 0`, `scrimAlpha: 0`
  under `prefers-reduced-transparency: reduce`. Do the same, and honour reduced-motion too.
- **Safe areas.** The web app runs `viewport-fit=cover` and pads by `env(safe-area-inset-*)`. Native
  clients get this for free but must not let the compose dock or toolbar sit under the home indicator.
- **The web app has no swipe gestures on rows** — actions are buttons. A native client *should* add
  swipe-to-archive / swipe-to-trash, because that is the platform idiom; just make sure whatever a
  swipe does is also reachable as an explicit control, and is undoable.
- **Nothing scrolls the page sideways.** Panes scroll their own overflow. Toolbars that don't fit
  scroll horizontally with the scrollbar hidden.

### Copy and tone

Read [translations/](../translations/) for the actual strings. The register throughout is calm,
concrete and lowercase-ish — plain sentences, no exclamation marks, no "Oops!". Errors name the
cause. The UI ships in **English and German**; if you add strings, add both, and design for German
being ~30% longer.

### Accessibility baseline

- Text inputs render at ≥16px on small screens (below that iOS zooms on focus regardless of viewport
  settings). Match this.
- Everything clickable must look and behave clickable, and everything reachable by pointer must be
  reachable by keyboard/screen-reader.
- Icon-only controls carry `aria-label`s in the web UI. Carry accessibility labels natively.
- Contrast must hold in all six themes, which is precisely why the semantic tokens exist.

---

## 3. The API

### Which API to use

plMail exposes **JMAP** (RFC 8620 / RFC 8621) at `/jmap`. **This is the API for third-party and
native clients.** It is the only stable, documented, versioned surface.

The web UI's own routes (`/mail/*`, `/compose/*`, `/settings/*`) return **HTML and Turbo Streams**,
not JSON. They are internal, unversioned, CSRF-protected and will change without notice. Do not build
against them.

Known JMAP clients that already work against this server: **ltt.rs** (Bearer) and **Sterna** (Basic).
Testing against one of them is the fastest way to sanity-check your own implementation.

### Authentication

The JMAP firewall is **stateless** and accepts two credential types.

**App passwords — available today, and what you should use.**

The user creates one in **Settings → App passwords**. The secret is shown exactly once and looks
like:

```
plmail_<64 hex chars>
```

Only a SHA-256 digest is stored server-side, plus a 6-character hint so the listing can show which is
which. Tokens are **user-scoped, not account-scoped**: one credential enumerates every connected mail
account. They can be revoked individually. `lastUsedAt` is updated at most every 5 minutes, so it is
a coarse "recently active" signal, not an audit log.

Send it either way:

```http
Authorization: Bearer plmail_abc123…
```

```http
Authorization: Basic base64(user@example.com:plmail_abc123…)
```

If you send Basic, the username **is verified** against the token's owner — a wrong address is
rejected with a clear message rather than silently operating as whoever owns the token.

**JWT — wired, but not yet issuable.** The firewall accepts JWTs (for a future first-party app) and
the server generates a keypair on first start, but **there is currently no endpoint that issues
one**. A `Bearer` token that starts with `plmail_` is routed to the app-password authenticator;
anything else falls through to JWT.

Today, build against app passwords. But if you're writing the *first-party* app, a proper login
endpoint issuing short-lived JWTs is exactly the kind of thing to request — most of the plumbing is
already there. Don't fake a session layer on top of app passwords to get around it; ask.

**Failure shape** — `401` with `application/problem+json` and a `WWW-Authenticate: Basic
realm="plMail JMAP"` challenge:

```json
{ "type": "urn:ietf:params:jmap:error:unauthorized", "status": 401, "detail": "Invalid or revoked app password." }
```

### Session discovery

```http
GET /.well-known/jmap        (or GET /jmap/session)
Authorization: Bearer plmail_…
```

Returns the Session object. Everything else is discovered from it — **never hardcode the other
paths**, and always re-read `apiUrl` etc. from here:

```json
{
  "capabilities": {
    "urn:ietf:params:jmap:core": {
      "maxSizeUpload": 50000000,
      "maxConcurrentUpload": 4,
      "maxSizeRequestObject": 10000000,
      "maxConcurrentRequests": 4,
      "maxCallsInRequest": 32,
      "maxObjectsInGet": 500,
      "maxObjectsInSet": 500,
      "collationAlgorithms": ["i;ascii-numeric", "i;ascii-casemap", "i;unicode-casemap"]
    },
    "urn:ietf:params:jmap:mail": {},
    "urn:ietf:params:jmap:submission": {},
    "urn:plmail:params:jmap:push": { "vapidPublicKey": "BN…" }
  },
  "accounts": {
    "7": {
      "name": "me@example.com",
      "isPersonal": true,
      "isReadOnly": false,
      "accountCapabilities": {
        "urn:ietf:params:jmap:mail": {
          "maxMailboxesPerEmail": null,
          "maxMailboxDepth": null,
          "maxSizeMailboxName": 255,
          "maxSizeAttachmentsPerEmail": 50000000,
          "emailQuerySortOptions": ["receivedAt", "from", "to", "subject", "size"],
          "mayCreateTopLevelMailbox": true
        },
        "urn:ietf:params:jmap:submission": { "maxDelayedSend": 0, "submissionExtensions": {} }
      }
    }
  },
  "primaryAccounts": { "urn:ietf:params:jmap:mail": "7" },
  "username": "me@example.com",
  "apiUrl": "https://mail.example.com/jmap/api",
  "downloadUrl": "https://mail.example.com/jmap/download/{accountId}/{blobId}/{name}?accept={type}",
  "uploadUrl":   "https://mail.example.com/jmap/upload/{accountId}",
  "eventSourceUrl": "https://mail.example.com/jmap/eventsource?types={types}&closeafter={closeafter}&ping={ping}",
  "state": "…"
}
```

**Critical modelling detail:** *one JMAP account is exposed per connected mail account.* A user with
three mailboxes sees three JMAP accounts under one login. **The unified inbox is a client-side
concern** — you run one `Email/query` per account and merge the results yourself, ordering by
`receivedAt`. There is no server-side cross-account query.

`urn:plmail:params:jmap:push` is a **vendor extension** carrying the VAPID public key you need before
you can create a Web Push subscription; RFC 8620 defines no standard place for it. An empty
`vapidPublicKey` is your signal that Web Push is unconfigured on this instance — don't offer it.

Note `capabilities` advertises the push URN but the *supported* `using` list is Core, Mail and
Submission only. Do not put the push URN in `using`.

#### Push on Android, without Google

Web Push assumes a **push service**: something that owns the endpoint URL, holds the connection to
the device and receives the server's encrypted POST. Browsers ship one. A native Android app does
not, and Android's own service is FCM, which speaks its own protocol — `WebPushSender` cannot POST
to it.

So an Android client has three options, and only the first needs nothing from this server:

1. **UnifiedPush.** The user installs a *distributor* app; it supplies an RFC 8030 endpoint and
   decrypts the RFC 8291 `aes128gcm` payload this server already sends. No server change at all.
2. **Firebase.** Nothing for the user to install, and what most Android users expect — but it needs
   a Firebase project and an FCM sender here, and Google then learns that a message arrived and
   when.
3. **An embedded distributor**, where the app holds the socket itself. Costs a foreground service
   and a permanent notification, per app.

For (1) this repository can supply the push service too, so a self-hoster does not have to find one:

```bash
docker compose --profile push up -d ntfy
```

That is the whole setup. It is off by default, adds no plMail code, and needs no configuration of
its own: the endpoint URL is derived from the `SERVER_NAME` set at first boot, because the host
phones already reach is the only thing it has to be. Override `NTFY_BASE_URL` if push should live
somewhere else.

The derived URL is `http://$SERVER_NAME:8090`. Two consequences worth knowing. It cannot be folded
behind the app's own Caddy at a path the way the Mercure hub is at `/.well-known/mercure` — ntfy
refuses a `base-url` with a path at startup — so it takes a port of its own. And the endpoint URL is
itself the secret, so facing the open internet you want TLS in front of it and `NTFY_BASE_URL` set to
the https address; over a LAN or Tailscale the default is fine as it stands.

It is baked into every endpoint issued, so changing it later forces every device to re-register.

Payloads are encrypted to the device's own key before they reach it, so the push service cannot read
mail whichever one you use. It does learn *when* mail arrives, which is the argument for running
your own rather than a public one.

### The API endpoint

```http
POST /jmap/api
Content-Type: application/json
Authorization: Bearer plmail_…
```

```json
{
  "using": ["urn:ietf:params:jmap:core", "urn:ietf:params:jmap:mail"],
  "methodCalls": [
    ["Email/query", { "accountId": "7", "filter": { "inMailbox": "42" }, "sort": [{ "property": "receivedAt", "isAscending": false }], "limit": 50 }, "q0"],
    ["Email/get",   { "accountId": "7", "#ids": { "resultOf": "q0", "name": "Email/query", "path": "/ids" }, "properties": ["id","threadId","subject","from","receivedAt","preview","keywords","hasAttachment","mailboxIds"] }, "g0"]
  ]
}
```

Back-references (`#ids`) are supported and are the intended way to pair query with get in one round
trip — important over a slow home uplink.

Two argument details that cost more to debug than to read:

- **`accountId` must be a JSON string.** An integer is rejected with `invalidArguments`, not coerced.
- **`Email/get` returns `list` in repository order, not the order you asked for**, and computes
  `notFound` by difference. If you paired it with `Email/query` you must re-order the result against
  the query's `ids` yourself, or your list arrives sorted by database id.

Request-level errors come back as `application/problem+json` with status 400 and a `type` of
`urn:ietf:params:jmap:error:notJSON` / `notRequest` / `unknownCapability`.

### Implemented methods

Everything registered in [`src/Jmap/Method/`](../src/Jmap/Method/):

| Method | Notes |
|---|---|
| `Core/echo` | |
| `PushSubscription/get` / `PushSubscription/set` | No `accountId`; per-user. |
| `Mailbox/get` / `Mailbox/query` / `Mailbox/changes` / `Mailbox/set` | |
| `Email/get` / `Email/query` / `Email/changes` / `Email/set` | |
| `Thread/get` / `Thread/changes` | |
| `Thread/set` | plMail extension. One property, `snoozedUntil` — see §4. |
| `SearchSnippet/get` | |
| `Calendar/get` | `urn:plmail:params:jmap:calendars`. One account serves calendars. |
| `CalendarEvent/get` / `CalendarEvent/query` / `CalendarEvent/set` | An id is the series, not an occurrence; `/query` requires a date window. |
| `EmailSubmission/get` / `EmailSubmission/set` / `EmailSubmission/changes` | |
| `Identity/get` / `Identity/set` | |

**Not implemented today.** None of these is a deliberate exclusion — they haven't been needed yet. If
your client wants one, **ask for it rather than engineering around it** (see [§0](#0-read-this-first-the-server-is-under-active-development)):

- **`Email/queryChanges` and `Mailbox/queryChanges` do not exist.** `Email/query` returns
  `canCalculateChanges: false`. To refresh a list you re-run the query. Use `Email/changes` for the
  object-level delta and re-query for ordering.
- **Anchor-based paging is not supported.** `anchor` raises `unsupportedFilter`; use `position` +
  `limit`. Negative positions (anchoring from the end) are rejected **by `Email/query`**;
  `Mailbox/query` does accept them and anchors from the end.
- **`Email/query` always returns `total`; `Mailbox/query` only with `calculateTotal: true`.**
- **`VacationResponse/*` and `Blob/copy`** are absent. `SearchSnippet/get` is not — it was listed
  here as missing while `SearchSnippetGetMethod` was in the tree.
- **No JMAP Contacts.** Calendars are served, under `urn:plmail:params:jmap:calendars`; there is no
  `Calendar/set` and no `/changes` on either type, because calendars cannot calculate changes — see
  [JMAP](internals/jmap.md).

### Object mapping — the four things that surprise people

**1. A JMAP `Mailbox` is a plMail *label binding*, not an IMAP folder.**

Labels are user-scoped and span accounts; a `LabelBinding` is the per-account instance of a label,
and *that* is what has a stable identity inside one JMAP account. So:

- `Mailbox.id` = binding id.
- `Mailbox.labelId` = the **user-scoped label id** this binding materialises. A plMail extension,
  not RFC 8621. Binding ids are per-account by necessity, so one label reachable from three accounts
  is three Mailboxes with three unrelated ids and nothing tying them together. This is what lets a
  client collapse them into a single sidebar row — matching on `name` instead breaks the moment the
  label is renamed in one account. It is **not** an id you can pass to `inMailbox` or `Email/set`;
  those take binding ids, always.
- `Mailbox.name` = the **leaf** name (JMAP models hierarchy via `parentId`, so `"Invoices"`, not
  `"Work/Invoices"`).
- `Mailbox.parentId` = the parent's *binding* id, or `null` if the parent has no binding in this
  account (so a child never points at an unresolvable id).
- Roles map from plMail's `LabelRole`: `inbox`, `sent`, `drafts`, `trash`, `junk` (plMail's `Spam`),
  `archive`, plus `flagged`, `important` and `all`. An unmapped role degrades to `null` — the
  mailbox still appears.
- `myRights`: system labels (`role !== null`) are not renamable or deletable; custom labels are fully
  mutable. Everything else is permitted.
- `isSubscribed` mirrors the label's visibility toggle. Note **Archive is created hidden by default**
  and only appears once the user switches it visible.

Sidebar order for system labels is fixed: Inbox 0, Sent 10, Drafts 20, Spam 30, Trash 40, Archive 50.
Custom labels sort after, alphabetically.

**2. `Email.mailboxIds` comes from the per-message label join, translated into the binding id space.**

Not from the thread-level union — reading that would report a mailbox for every message in the
thread. Standard JMAP map shape (`{"42": true}`), and `{}` when empty (never `[]`).

The join stores user-scoped **label** ids, but the ids published here are **binding** ids, so they
match `Mailbox.id` and can be passed straight back to `inMailbox` and `Email/set`. **One id space
throughout — there is no case where you need to translate.** A label the account has no binding for
is omitted rather than published as an id you could not resolve.

> Until mid-2026 this property emitted untranslated label ids. Because both are autoincrement ints
> from different tables, the wrong ids usually *looked* valid and named some unrelated mailbox, so
> the symptom was a plausible wrong answer rather than an error — and it was invisible on a
> single-account install, where the two sequences tend to line up. If you are reading this against
> an older server, that is what you are seeing.

**3. Bodies are synthetic parts.**

plMail stores a *flattened* body (`bodyText` / `bodyHtmlSafe`), not a MIME tree. So every Email
publishes at most two body parts with the fixed `partId`s **`"text"`** and **`"html"`**. They are
stable per message, which is all `fetchTextBodyValues` / `fetchHTMLBodyValues` need. Treat `partId`
as opaque anyway, as the spec requires.

**Note the capitalisation: `fetchHTMLBodyValues`, not `fetchHtmlBodyValues`.** That is the RFC 8621
spelling and what the server reads. An unrecognised argument is simply absent, so getting it wrong
returns empty `bodyValues` with no error at all.

**The HTML published is always the sanitised version**, never the raw column — this body is handed
straight to third-party clients that render it.

`preview` is the plain-text body, whitespace-collapsed, capped at 256 characters.

**4. Keywords are partly columns, partly flags.**

| Keyword | Backed by |
|---|---|
| `$seen` | `seen_at` timestamp column |
| `$flagged` | `starred_at` timestamp column |
| `$draft` | the IMAP `flags` JSON array |
| `$answered` | the IMAP `flags` JSON array |

Any **other keyword is rejected** with `unsupportedFilter` when filtered on. Do not invent custom
keywords for your own state; they will not round-trip.

Address shape is translated at the boundary: plMail stores `{name, address}`, JMAP emits
`{name, email}`. `messageId` / `inReplyTo` / `references` are emitted as bare ids with angle brackets
stripped.

### Email/query filters

Compiled by [`EmailFilterCompiler`](../src/Jmap/Query/EmailFilterCompiler.php). **Anything not
understood raises `unsupportedFilter` rather than being silently ignored** — a quietly-dropped filter
returns too many emails and the client cannot tell.

| Condition | Behaviour |
|---|---|
| `inMailbox` | Mailbox (binding) id. |
| `inMailboxOtherThan` | Non-empty array of binding ids. |
| `before` / `after` | UTCDate against `received_at` (`<` and `>=`). |
| `minSize` / `maxSize` | `>=` / `<` on byte size. |
| `hasKeyword` / `notKeyword` | Only the four keywords above. |
| `hasAttachment` | Boolean. |
| `text` | **Real full-text search** — Postgres `tsvector` + `websearch_to_tsquery('english')`. Stemmed, ranked, not a substring scan. |
| `body` / `subject` / `from` | `ILIKE` substring. `from` covers both address and display name. |
| `to` / `cc` / `bcc` | Substring over the serialised JSON address array (matches name or address). |
| `filename` | Substring over attachment filenames. Inline parts have null filenames and never match. |
| `listId` | Substring over the canonicalised `list-id` header. |

`AND` / `OR` / `NOT` FilterOperators nest freely. Note `NOT` is implemented as `NOT (a OR b …)`.

`EmailFilterCompiler` also understands `hasLabel` / `notLabel`, which take **user-scoped Label ids**
rather than Mailbox (binding) ids. These exist for mail rules, which have no reason to know about
the JMAP id space. They are not part of the client-facing filter vocabulary — use `inMailbox`.

**Sort:** `receivedAt`, `from`, `to`, `subject`, `size`. **Limit:** capped at 500 (`null` or larger
becomes 500). `collapseThreads` is supported.

The full-text config string (`'english'`) must match how the column was generated — a mismatch
silently returns nothing, because the stemmed tokens never line up. Just don't try to route around it.

### The web UI's search syntax

Your search box should accept the same Gmail-style operators the web UI does, and translate them into
JMAP filter conditions. From [`SearchQueryParser`](../src/Service/Search/SearchQueryParser.php):

| Typed | Means |
|---|---|
| `from:alice` | `from` |
| `to:bob` | `to` |
| `subject:invoice` | `subject` |
| `has:attachment` | `hasAttachment: true` |
| `is:unread` / `is:read` | `notKeyword: "$seen"` / `hasKeyword: "$seen"` |
| `is:starred` | `hasKeyword: "$flagged"` |
| `in:inbox\|sent\|drafts\|trash\|archive\|junk` | `inMailbox` of the role's mailbox |
| `after:2024-01-01` / `before:2024-12-31` | `after` / `before` |
| anything else | free text → `text` |

Quoted strings are kept together. Unknown operators fall through to free text rather than erroring —
match that leniency.

### Writing: Email/set

Creates drafts, updates keywords and `mailboxIds`, and "destroys".

- **`destroy` is a move to Trash**, not a row delete. There is no hard-delete path anywhere in the
  product. Present it as Trash in your UI.
- Every mailbox/keyword change goes through the same propagator the web UI uses, so a change made by
  your client reaches Gmail / IMAP / Graph exactly as one made in the browser. Archiving from your
  app archives in Gmail.
- `ifInState` is honoured — use it for conflict detection on batch mutations.
- Draft creation writes through the same draft writer the composer uses.

**Semantic reminder:** "archived" in plMail's domain model means *carries no Inbox label*. To archive,
remove the Inbox mailbox id. The Archive label itself is IMAP location bookkeeping for plain-IMAP
accounts, and is hidden by default.

### Sending: EmailSubmission/set

Sending is queued on the same message bus the web composer uses. That pipeline performs the whole
draft→sent transition itself (adds Sent, removes Drafts, clears `\Draft`, sets `sentAt`, re-points the
mailbox), so a client that omits `onSuccessUpdateEmail` still ends up correct.

```json
["EmailSubmission/set", {
  "accountId": "7",
  "create": { "s1": { "emailId": "#draft1", "identityId": "3" } },
  "onSuccessUpdateEmail": { "#s1": { "mailboxIds/42": null, "mailboxIds/17": true } }
}, "c0"]
```

Things to know:

- **A submission has no table of its own — its id *is* the Email id.** plMail sends each draft at most
  once, so the mapping stays one-to-one.
- `undoStatus` is reported as **`"pending"`**: the send is genuinely queued and has not happened yet
  when the call returns.
- **The web UI's undo-send grace period is deliberately NOT applied to JMAP submissions.** A JMAP
  client asked to send *now*. If you want an undo window in your app, implement it client-side by
  delaying the submission call.
- `maxDelayedSend` is `0` — there is no scheduled send.
- Errors: `invalidProperties` (missing/unknown `emailId`), `alreadyExists` (already sent),
  `noRecipients`.

**Identities** come from the same list the web composer's From dropdown shows — the account's
sendable aliases, primary first. An account with no alias rows yet yields one synthetic identity for
the account address itself. Always let the user pick, and default to the primary.

### Blobs: upload and download

**Upload** — `POST {uploadUrl}` with raw bytes and a `Content-Type`:

```json
{ "accountId": "7", "blobId": "u-91", "type": "image/png", "size": 40213 }
```

Max 50 MB (matches `maxSizeUpload`); larger gets `tooLarge` / 413. The declared type is stored as
metadata and echoed back, exactly as the spec requires — nothing is parsed or trusted. Uploads are
*staged*: unused ones are swept by a scheduled `app:prune:blobs` job, so upload close to when you
reference the blob.

**Download** — `GET {downloadUrl}` with `{accountId}`, `{blobId}`, `{name}` filled in.

`blobId` is namespaced and opaque: `m-<id>` (a whole message's RFC822 source), `p-<id>` (an
attachment part), `u-<id>` (a staged upload). Do not parse it — the namespacing exists precisely
because the underlying tables have independent autoincrement ids.

**Security behaviour you must design around:** the `accept` query parameter is **ignored** (honouring
it would let a caller relabel HTML as an image). `X-Content-Type-Options: nosniff` is always set, and
**only `image/*` is served inline** — everything else comes back as an attachment disposition. This
matters more here than in the web UI because a JMAP client may hand the URL straight to a webview.
Don't build a viewer that assumes inline rendering of arbitrary types.

The `{name}` segment is used only for the download filename and is never trusted for lookup.

### Staying current: push

Three mechanisms, in descending order of what you should prefer.

**1. Web Push / `PushSubscription` — the right answer for background delivery.**

Create a subscription via `PushSubscription/set`, using the `vapidPublicKey` from the session's
`urn:plmail:params:jmap:push` capability as your `applicationServerKey`.

**There is a mandatory verification handshake, and it is the whole point.** On create, the server
immediately POSTs a `PushVerification` object to your URL. You read the code out of it and echo it
back via a `PushSubscription/set` update. **Until you do, the subscription receives nothing.** This is
what stops the endpoint being an open relay — without it anyone with an account could register a
stranger's URL. Budget for this round trip in your onboarding.

**2. EventSource (SSE) — for a foreground session, briefly.**

`GET {eventSourceUrl}`, `text/event-stream`. Emits a `state` event immediately on connect (so you know
where you stand without an extra round trip), then further `state` events on change, plus `ping`
events (default 30s, minimum 5s).

**Read this before you use it:** each connection **holds a PHP worker for its entire life**. Under
FrankenPHP that is a hard capacity limit — N connected clients means N occupied workers, and once
they're all taken the server stops answering ordinary requests. On a home NAS, N is small.
Consequently the server **hard-closes every connection after 300 seconds** and expects you to
reconnect. Reconnect with backoff, and **disconnect as soon as your app backgrounds**. Background
delivery belongs on Web Push, not here.

`?closeafter=state` gives you one StateChange and an immediate close — the cheap way to resync
without holding a connection.

**3. Polling** — the fallback. Keep it infrequent; this is someone's Raspberry Pi.

**What a push actually contains:**

```json
{ "@type": "StateChange", "changed": { "7": { "Email": "9", "Mailbox": "3" } } }
```

Deliberately tiny. **JMAP never pushes mail content, only the news that a state token moved.** You
then call `Email/changes` to find out what. Tracked types: `Mailbox`, `Email`, `Thread`,
`EmailSubmission`. `Identity` is excluded — it changes only when the user edits their own addresses,
which they just did in your app.

Every token comes from the same state manager the `/get` and `/changes` methods use, so a push and a
subsequent `/changes` can never disagree.

**Paging changes:** `/changes` returns at most **256** rows per call and sets `hasMoreChanges`. The
limit is deliberately modest for mobile — loop until it clears.

### Sync model, end to end

Understanding where mail comes from helps you set the right expectations in your UI:

| Account type | Ingest | Instant delivery |
|---|---|---|
| IMAP | `webklex/php-imap`, one IDLE connection per mailbox, supervised | IMAP IDLE — works on a LAN, no public URL needed |
| Gmail | Gmail REST + Batch API over OAuth2 | Google Cloud Pub/Sub watch → `/gmail/push` (requires public HTTPS + one-time instance setup) |
| Outlook / M365 | Microsoft Graph over OAuth2 (**not IMAP** — Exchange Online blocks it under Security Defaults) | Graph subscriptions → `/webhook/graph` (requires public HTTPS) |

A scheduled polling sync (every 15 minutes) backs all of them up whenever push isn't available. So:
**your app should never claim mail is "up to date" on the basis of push alone**, and should offer a
manual refresh. Equally, don't hammer a sync endpoint — the server is already trying.

Each account has a **sync window** (how far back history is fetched), set per account in settings.
Mail older than the window is *not in the database*, and therefore **not searchable**. If a user
searches and finds nothing old, the honest message is "search covers synced mail; widen the sync
window in settings" — not "no results".

Threading is currently **RFC Message-ID based**, not Gmail-native `threadId`. Expect occasional
divergence from what the Gmail web UI groups together.

---

## 4. Behaviour: what your app must do

### Feature parity checklist

Ordered roughly by how much users will miss them.

**Reading**
- Unified inbox across accounts (client-side merge; see [§3](#session-discovery)).
- Conversation threading, newest expanded, older collapsed.
- Unread / starred / attachment state at a glance.
- Full-text search with the operator syntax above.
- Attachments and inline images; original (raw) message available on request via the `m-<id>` blob.

**Writing**
- Compose, reply, reply-all, forward. Rich text.
- Contact autocomplete (harvested from synced mail server-side; there is no JMAP Contacts — for a
  native client, either cache addresses locally from mail you've seen or fall back to the OS address
  book).
- Send from any account **and any sendable alias** — always show the From picker.
- Draft autosave.
- Undo send (client-side for JMAP; see above).

**Organising**
- Labels: apply, remove, create, delete. Nested labels exist in the data model; nested label *UI* is
  still on the server roadmap, so flat-with-paths is acceptable.
- Archive = remove Inbox label. Trash = `destroy`. Both undoable.
- Snooze — bring a conversation back later. A **thread-level** property (`MessageThread.snoozedUntil`),
  exposed as `Thread/set`, which is a plMail extension accepting that one property and nothing else.
  It goes through the same `ThreadSnoozeService` the web UI does, so a snooze set from a client and
  one set in the browser mean the same thing — which is the point, and why a locally-tracked snooze
  is still the wrong idea: it would disagree with the web UI and break on reinstall. This section
  used to say snooze was not exposed at all, and cited itself as the canonical "ask, don't work
  around" case; somebody asked.
- Mark read/unread, star.

**Settings**
- Appearance (theme, layout, density, accent) — build the token system and drive it from local
  settings for now; the server does not expose `Appearance` over JMAP yet (see [§2](#2-look-and-feel)).
- Account list and order.
- Notification preferences.
- App password management is web-only today; link out to the web UI rather than reimplementing.

### Interaction rules

- **Be optimistic, then reconcile.** Star, read, archive and label changes should apply instantly in
  the UI and reconcile against the returned state. The server propagates outward asynchronously
  anyway.
- **Every destructive action is undoable**, and the undo lives in a toast at the bottom of the screen
  for a few seconds. Trash, archive, and send all follow this pattern in the web UI.
- **Reading a thread marks it read**, but only after it has actually been shown — not on prefetch.
- **Back always means back.** The mobile list⇄thread transition is a real navigation step.
- **Offline is a first-class state, not an error.** A home server is unreachable more often than a
  cloud one. Show cached mail, queue mutations, say plainly that you're offline, and retry.
- **Never poll aggressively.** No 5-second refresh loops, no keeping SSE open in the background, no
  re-querying the full list on every foreground.

### Error handling

| Situation | What to show |
|---|---|
| 401 | "Your app password was revoked or is invalid" → re-auth flow. Don't retry silently. |
| `unsupportedFilter` | A bug in your query builder. Log it; don't surface raw JMAP errors. |
| `tooLarge` on upload | Name the 50 MB limit. |
| Server unreachable | "Can't reach your server" — with the hostname. Users self-host; the hostname is genuinely useful to them. |
| Empty search results | Mention the sync window if the query had a date/`before:` component. |
| No accounts connected | Deep-link to the web UI's account setup; account creation involves OAuth flows that belong in a browser. |

### Things not to do

- **Don't build against the HTML/Turbo routes.** They will change.
- **Don't hardcode `apiUrl`, `uploadUrl`, `downloadUrl` or `eventSourceUrl`.** Read them from the
  session object every time.
- **Don't parse `blobId`.** It's namespaced server-side and the spec forbids it.
- **Don't assume one account.** Ever.
- **Don't hold an SSE connection in the background.** You will take down someone's mail server.
- **Don't render message HTML on a dark background naively** — pick a strategy per message, and
  invert imagery back if you invert (see [§2](#the-mail-sheet--where-the-theme-stops-and-how-it-stops)).
  And don't render non-image blobs inline.
- **Don't invent keywords** — anything beyond `$seen`, `$flagged`, `$draft`, `$answered` is rejected.
- **Don't implement hard delete.** It doesn't exist and shouldn't.
- **Don't build a workaround for a missing server feature without asking first.** The server is
  actively developed and adding to it is a normal, available option — see
  [§0](#0-read-this-first-the-server-is-under-active-development). A workaround that duplicates
  server logic in the client is worse than a one-line request.

---

## 5. Getting a dev environment

```bash
docker compose up --build
```

Nothing to fill in first — secrets generate on first start, and the one setting with no sensible
default (the address plMail is reached at) is asked for on the setup screen. Open the app, create the
first administrator, add a mailbox.

Then, for your client: **Settings → App passwords → create one**, and point your client at
`https://localhost/.well-known/jmap`.

Useful during development:

```bash
docker compose exec php bin/console debug:router
```

```bash
docker compose exec php bin/console app:mail:sync
```

A test stack with its own Postgres (so you never touch real mail) is available via
`npm run test:env:up`, serving at `http://127.0.0.1:8001`. See
[CONTRIBUTING.md](../CONTRIBUTING.md) for the full console command reference and the test suites.

---

## 6. Quick reference

**Endpoints**

| Path | Method | Purpose |
|---|---|---|
| `/.well-known/jmap`, `/jmap/session` | GET | Session discovery |
| `/jmap/api` | POST | All reads and writes |
| `/jmap/upload/{accountId}` | POST | Blob upload |
| `/jmap/download/{accountId}/{blobId}/{name}` | GET | Blob download |
| `/jmap/eventsource` | GET | SSE state changes |

**Limits**

| Limit | Value |
|---|---|
| Upload size | 50 MB |
| Concurrent uploads | 4 |
| Request object size | 10 MB |
| Concurrent requests | 4 |
| Calls per request | 32 |
| Objects per `/get` | 500 |
| Objects per `/set` | 500 |
| `Email/query` limit | 500 (hard cap) |
| `/changes` rows | 256 per call |
| SSE connection lifetime | 300 s |
| App-password `lastUsedAt` write throttle | 300 s |

**Stack, for context**

Symfony 8 / PHP 8.4 · PostgreSQL 18 · Doctrine ORM · Symfony Messenger (Doctrine transport) ·
Mercure (web UI live updates) · FrankenPHP · AssetMapper + Tailwind v4 + Hotwire Turbo/Stimulus ·
libsodium-encrypted credentials · AGPL-3.0 · `linux/amd64` and `linux/arm64`.

**Server roadmap items that will affect clients**

These are already planned. If your client needs one sooner, say so — priorities are negotiable, and a
concrete client requirement is the best reason to move something up.

- Completing the label-based refactor (Label as *the* user-facing concept; Mailbox demoted fully to
  IMAP sync infrastructure).
- Gmail-native `threadId` threading, replacing Message-ID threading.
- Incoming IMAP flag sync over the IDLE stream.
- Nested label UI.
- A JWT issuance endpoint for a first-party app.
