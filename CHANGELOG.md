# Changelog

Notable changes per release. plMail runs its migrations automatically on boot,
so anything that changes the schema irreversibly is called out explicitly.

The published image tags: `latest` follows the most recent release below,
`main` follows the tip of the default branch, and `sha-…` pins one commit.

## Unreleased

Nothing yet.

## v0.0.10 — 2026-08-03

**Deployment shape changes.** The single `messenger-worker` service is replaced
by three — `worker-export`, `worker-ingest`, `worker-maintenance` — so remove
the old one when upgrading. Roughly 150MB more resident in total. No schema
change.

### Fixed

- **Sending waited behind whatever the mailbox was doing.** Seventeen message
  types shared one queue and one worker, so they were ordered by arrival:
  pressing Send behind a Gmail batch meant waiting for the batch. Outgoing work
  now has its own queue and its own process, and retries faster than the rest —
  a relay refusing a connection clears in seconds, not minutes.
- **Learning contacts re-read the whole mailbox after every sync.** A sync that
  brought in twenty messages re-read all fifty thousand, hydrating whole
  messages — bodies, headers and search vectors included — to use five address
  fields. Measured on a real account at five and a half hours of database time
  a day, more than every other query on the server combined. Addresses are
  learned from each batch as it arrives; the full sweep is now
  `app:backfill contacts`, run when it is actually wanted.

## v0.0.9 — 2026-08-03

Mostly internal. **One schema change**: five tables gain an `updated_at`, added
nullable, backfilled from `created_at` and then made `NOT NULL`, so it is safe
on a populated database. Restart the workers after upgrading — several service
constructors changed and `messenger-worker` and `imap-supervisor` hold a
compiled container.

### Fixed

- **A draft created by an app went missing from Drafts.** A thread's labels are
  rebuilt from the messages it holds, and a freshly threaded draft was not in
  that collection yet, so the rebuild took back the Drafts label it had just
  been given. Drafts written in the browser were unaffected, which is why this
  survived so long.
- **Mail sent from an alias went out as the account's main address.** The
  composer wrote the chosen address onto the message and the save overwrote it
  one line later. Both the web composer and JMAP were affected.
- **`Mailbox/set` could destroy a mailbox that had just been given a child.**
  The refusal counts children, and a child created earlier in the same request
  was not visible to the count — which JMAP makes reachable, since create and
  destroy travel together.
- **Editing a draft in place, popping the editor out and closing it** left the
  conversation without that draft's row until a reload.
- **A phone opened on the calendar** if the calendar pane had ever been opened
  on a desktop. The pane state is one preference shared by every device, and
  below 1024px the pane replaces the mail rather than sitting beside it.
- Settings gets the same edge-to-edge cards on mobile the admin area got.

### Added

- **Statement statistics are enabled at boot**, so a slow query reported next
  week is explained by numbers that were already being collected. Where the
  database refuses, the admin Database tab offers a button; where the server
  cannot support it at all, it says so instead.

### Changed

- Every entity is properties and hooks — no getters, no setters, no fluent
  setters — and every one takes `TimestampableTrait` rather than tracking its
  own timestamps. Both timestamps are non-nullable, which removed 35 suppressed
  type mismatches.
- All SQL, DQL and DBAL now lives in a repository, and every hand-written query
  that stayed carries a comment saying why it could not be a plain Doctrine
  call.
- Controllers hold their actions and the responses those actions share.
  Everything else moved into services grouped by purpose — draft persistence,
  attachments, reply building, JMAP change announcements, OAuth state — several
  of which were previously implemented twice, once for the browser and once for
  JMAP.

## v0.0.8 — 2026-08-03

### Fixed

- **A Gmail rate limit looked exactly like a permissions failure, and was fatal
  either way.** Gmail signals throttling with 403 rather than 429, and the only
  place it names the cause is a response body that was never read — so a
  transient quota rejection killed an entire account sync and reported nothing
  but a status. Quota rejections are now retried with a real backoff;
  permissions failures and daily-limit breaches are never retried.
- **Archive, star and trash could vanish silently on Gmail, Outlook and IMAP.**
  All three outgoing pushes swallowed their failures, on the stated grounds that
  the next sync would reconcile the difference. It does not: sync reads the
  server's state *in*, so a change that never arrived leaves nothing to
  reconcile from and the next pass overwrites it. The action disappeared from
  the account while still looking applied on screen. Transient failures are now
  retried; only a refusal that would repeat forever is dropped. An IMAP
  authentication failure is deliberately still not retried — repeated rejected
  logins get mailboxes locked and hosts banned.
- **Retries gave up after seven seconds.** The async queue retried at 1s, 2s and
  4s, which is not a backoff for anything that clears in minutes. Now 5s to 300s
  over five attempts. A send that hits a briefly-unavailable relay may now take
  up to ~8 minutes to go out instead of failing outright.
- **The admin log view showed no log messages on a phone.** The message column
  was zero pixels wide at every phone width and the overflow was clipped rather
  than scrolled, so each entry displayed its level, time and source and nothing
  else. Entries also gained a copy button that copies the whole entry, and works
  over plain HTTP where the clipboard API is unavailable.
- **The admin area spent a third of a phone screen on nested padding**, because
  the page padded its content and every section inside it padded its own.

## v0.0.7 — 2026-08-02

### Fixed

- **Three of the four services failed to start on any deploy that carried a
  migration.** php, imap-supervisor, messenger-worker and scheduler run the same
  entrypoint and all migrate on boot, within milliseconds of each other against
  one database. All four read the migration ledger before any of them writes to
  it, so all four try to apply the same migration; one succeeds and the rest die
  on `column "timezone" of relation "user" already exists`, which under `set -e`
  is a container that never comes up. Boot migrations now run under a Postgres
  advisory lock held for the whole run (`app:db:migrate`), so the containers
  that lose the race wait, find the ledger already current, and start normally.
  Nothing to do on upgrade.

## v0.0.6 — 2026-08-02

### Added

- **The inbox categories cross JMAP.** plMail has classified inbox mail the way
  Gmail does for a long time and the web has had a tab bar over it, but no
  client except the browser could see any of it. `Thread.category` carries the
  conversation's resolved value, `Email.category` the raw per-message signal it
  was derived from, and `Email/query` gained a `threadCategory` filter so a tab
  is narrowed by the server rather than sieved from a page. Only the thread
  value is filterable: offering the per-message one would put a newsletter
  somebody answered into two tabs where the web shows it in one.
- **A timezone, per user.** Settings → General now carries one, and everything
  the app renders honours it. New installs fall back to `APP_DEFAULT_TIMEZONE`
  rather than to the container's clock, which is how this went unnoticed.

### Fixed

- **Every date rendered two hours early for anyone outside UTC.** PHP's default
  timezone is UTC, Twig was never told otherwise, and not one `|date()` call in
  the templates passed a zone — so a correct UTC instant was printed verbatim
  and an 11:00 appointment read 09:00 in Berlin. Mail dates and extracted
  calendar events both.
- **IMAP stored the sender's wall clock, not the instant.** The `Date:` header's
  offset was parsed and then kept, and Doctrine writes a datetime in whatever
  zone the object carries, so a `+0200` message landed in the column two hours
  ahead of the UTC it claimed to be. Gmail and Graph already normalised; IMAP
  now agrees with them. **Rows written before this are wrong by the sender's
  offset**; a resync corrects them and no migration is attempted.
- **Mail written or sent from the web never reached JMAP clients.** Composing,
  sending and discarding all changed messages without recording anything in the
  change log, so a phone learned about them only if something else happened to
  move the state. A draft that became sent mail stayed a draft on the device
  indefinitely.
- **`app:test:seed-mail` could not be used to test sync**, because it wrote
  messages without advancing the Email state — so `Email/changes` never
  mentioned them and no push fired however much mail it created. This is what
  made a genuinely broken client sync look like a server changelog gap.

## v0.0.5 — 2026-08-01

### Added

- **schema.org extraction.** Reads the markup booking confirmations already
  carry, so flights, parcels, hotels and restaurant bookings reach the calendar
  without an invite attached.
- An admin reset ladder, behind a door in the admin panel, exposing the reset
  the console command already had.

### Fixed

- **Mail arriving in the wrong alphabet, or not arriving at all**, and one 8-bit
  header byte costing the whole message.

## v0.0.4 — 2026-08-01

### Fixed

- **A batch with two copies of one invite killed the whole run.** Found on a
  real mailbox, 200 messages into `app:backfill events`; three defects that
  compounded, starting with an invite arriving twice creating two events.

## v0.0.3 — 2026-08-01

### Added

- **Calendar invites on the calendar.** Extraction end to end for the one source
  that is not a guess: a `text/calendar` part becomes a `CalendarEvent`, and the
  confirm/change/cancel life of a booking resolves to one row rather than three.

### Fixed

- The two API providers lost calendar invites at ingest.

## v0.0.2 — 2026-08-01

### Added

- **A look.** Paper and a retuned Dark, a rebuilt mail row, themed toasts and
  tooltips, and a Dark reworked into a dim room rather than flat backgrounds
  that painted nothing.
- **The calendar**, docked beside the mail and resizable against it, over a new
  data layer of JSCalendar events with materialised occurrences.
- **`Mailbox.color` over JMAP** — nine tokens or null, refused with
  `invalidProperties` rather than dropped, and accepted on create as well as
  update. Outlook and Gmail label colours are mapped onto the same vocabulary by
  hue, so a real account arrives already coloured.
- **A push service**, so Android does not have to mean Google; the push URL is
  derived from the host set at first boot.

### Changed

- The three sync paths share one post-ingest sequence instead of three.

## v0.0.1 — 2026-07-31

### Added

- **Snooze.** Put a conversation away and have it come back later — from the
  message row, the list toolbar, or a JMAP client via the `Thread/set`
  extension. Snoozed conversations get their own sidebar entry and view.
  `app:mail:wake-snoozed` runs every minute to bring them back.
- **JMAP draft attachments.** `Email/set` now accepts uploaded blobs in
  `attachments`, per RFC 8621; drafts created over JMAP previously dropped them
  silently.
- **Login throttling.** Five attempts per account per fifteen minutes on the
  password form, and a separate limit on the two-factor code form — which the
  firewall's throttling does not cover, and which is the form an attacker
  reaches holding a stolen password.
- **Admin user management.** plMail's data model has always been multi-user, but
  there was no way to create the second user: the setup wizard and `app:setup`
  both make the first one and then refuse. Admin → Users adds, edits, promotes
  and removes people. An administrator deliberately cannot change an existing
  user's password or remove anyone's second factor — both would make an admin
  session a way into someone else's mailbox.
- **`GET /healthz`** — reports database, queue and worker health without a
  session, so Docker healthchecks and uptime monitors can use it. The image's
  healthcheck now points here instead of at Caddy's metrics port, which
  answered before PHP could reach the database.
- **`app:backup`** — one command for the database dump, the stored files and
  the generated secrets, and it says explicitly when `APP_ENCRYPTION_KEY` is
  *not* in the backup because the install supplies it from the environment.
- **PHPStan** at level 5, with a baseline, in CI. **Dependabot** for composer,
  npm and GitHub Actions.
- **LICENSE.** The project has always said AGPL-3.0 in its README; the licence
  text is now actually distributed with it, and `composer.json` no longer
  contradicts it by claiming "proprietary".

### Fixed

- **Snoozing from the web UI did nothing but set a timer.** No labels moved and
  nothing propagated, so the conversation stayed in the inbox — locally and at
  the provider — while its row vanished from the list. The sweep would then
  "wake" a thread that had never left.
- **The snooze button on a message row cleared a snooze instead of setting
  one**, and the toolbar's snoozed everything to a hardcoded 8am tomorrow.
- **"Test connection" in account settings crashed** with an `ArgumentCountError`
  whenever the form was incomplete or the password blank — the two cases it
  exists to report.
- **The admin Graph category sync caught an exception class that does not
  exist**, so Graph failures propagated instead of degrading quietly.
- **User search in the admin area returned everything**, and soft-deleted users
  were visible to every query that claimed to exclude them. Both were the same
  mistake: a Doctrine expression built and never passed to `andWhere()`.
- **Gmail label changes ran inside the HTTP request.**
  `ApplyGmailLabelsMessage` was never routed to the async transport, so archive,
  trash, star and mark-read made a live Google API call while the user waited —
  where the IMAP and Graph equivalents were queued. A Gmail outage surfaced as a
  500 on a UI action rather than a retry on the worker.
- **JMAP `Email/set` rejected valid mailbox patches.** Current `mailboxIds` were
  emitted as label ids where the protocol expects per-account binding ids; since
  both are autoincrement ints, a patch removing one mailbox failed with "No such
  Mailbox".

### Changed

- `latest` is published from release tags only. It previously followed every
  push to `main`, which meant the tag the README tells people to pull could
  carry unreviewed work and automatic schema migrations.
- The login form no longer shows a "forgot password?" link. There is no reset
  flow — recovery is a console command — and the link was an `href="#"` that did
  nothing.
