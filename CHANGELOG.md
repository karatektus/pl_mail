# Changelog

Notable changes per release. plMail runs its migrations automatically on boot,
so anything that changes the schema irreversibly is called out explicitly.

The published image tags: `latest` follows the most recent release below,
`main` follows the tip of the default branch, and `sha-…` pins one commit.

## Unreleased

No schema change, no deployment change.

### Added

- **An invitation can be answered from the mail it arrived in.** Invites were
  parsed, filed on a calendar and then unanswerable — the participants were read
  out of the `.ics` and written into the event, and nothing rendered them. A
  message carrying an invitation now shows a card above it: when it is, who
  called it, who else is coming and how they answered. Yes / Maybe / No records
  the answer and sends the organiser an iTIP `REPLY` — same UID, same SEQUENCE,
  one ATTENDEE line — which is what every other calendar reads to tick a name
  off. The card says what the answer was; the toast says whether it reached the
  organiser, because a reply that could not be sent leaves somebody holding a
  seat for a person who thinks they declined.
- **Calendars can be managed.** They carried a name, a colour, a time zone, a
  visibility flag and a default flag from the first pass, and nothing reached
  any of them: a user with four accounts had four calendars named after their
  usernames, all shown, with no way to say where a new event should land.
  Settings → Calendars now does all of it. A provisioned calendar cannot be
  deleted (it would only be created again) and the default can be neither
  deleted nor hidden (new events would vanish on save), so neither offers the
  button.

### Fixed

- **An invitation from Google Calendar had no organiser, so it could not be
  answered.** The organiser is listed twice in a normal invite — once as
  ORGANIZER and again as an ATTENDEE, because they are going too — and the
  participants were keyed by address and written in that order, so the second
  line landed on top of the first and took the `owner` role with it. The event
  ended up with nobody marked as organiser, which is nobody to reply to, so the
  new invite card correctly offered no answer to what was in fact a perfectly
  ordinary invitation. Roles accumulate onto the address now instead of
  replacing each other. Existing events carry the old shape until
  `app:backfill events` re-reads the mail they came from.
- **Re-reading an invitation un-answered it.** The mail that asked the question
  says NEEDS-ACTION about you forever, so every re-run of extraction reset an
  RSVP that had already been sent — the organiser knew, and the screen did not.
  An answer already recorded is kept unless the incoming copy states a real one.
- **Throwing away an event extraction got wrong put it back.** Extraction is
  re-runnable by design, so deleting an event only lasted until the next
  backfill walked the mailbox again and re-created it from the same message.
  "Not an event" now records the refusal against the claim's dedup key — the
  table for it existed and nothing had ever written a row — so the dismissal
  survives a re-run and also catches the *next* message about the same booking.
- **Correcting an invitation's details un-answered it.** The event's canonical
  object is rebuilt from its columns on every edit, and the editor has no
  participants field, so fixing a meeting's title dropped the RSVP that had been
  stored on it. (Locally only — the organiser had been told long before.)
- **Typing into a modal that had just been opened could be silently discarded.**
  Closing a dialog cleared where the frame pointed but left the previous form in
  it, so the next open showed the last dialog until its own fetch landed —
  pre-filled, focusable and completely convincing. Anything typed in that window
  was thrown away when the real form arrived. The frame goes back to its spinner
  on close.
- **The sidebar's account chevron did not say whether it was open.** It is a
  disclosure button, and which way it will go stopped being guessable when the
  expanded account moved into the user's settings and started surviving
  navigation. It carries `aria-expanded` now, kept in step as it opens and
  closes.
- **Four mailbox pages ran an English word through the translator as a key.**
  The Inbox, Drafts, Sent and Trash titles asked for `'Inbox'|trans` rather than
  the key beside them in the catalogue, so they were untranslatable and reported
  as missing on every visit — and Starred asked for "Drafts", which is what it
  said. `thread_row.unread` was genuinely missing and read out as its own key by
  screen readers; a `|default` behind it could never have fired, because a
  missing translation comes back as the key and the key is not empty.
- **The pirate locale had no calendar at all.** `en_PI` was written before the
  calendar shipped and never caught up, so every string in it fell back to
  English. It has the lot now, including the new settings section.

## v0.0.15 — 2026-08-03

No schema change, no deployment change.

### Fixed

- **An Outlook account could stop syncing entirely.** `meetingMessageType` is
  declared on Graph's event-message type, not on the base message, and naming it
  unqualified does not get ignored — the whole `$select` is rejected, every
  message in the batch answers 400, and nothing arrives. It is asked for through
  the cast now, and a mailbox that refuses even that gets one retry without it
  and is remembered, rather than never syncing again.
- **Deleted Outlook drafts sat in Drafts and Trash at once.** Attaching a folder
  label relied on the source folder's removal notice to take the old one off,
  which only works while both carry the same message id — and personal
  outlook.com mailboxes do not reliably provide those. A folder move now
  replaces the location outright, which is what a single Exchange folder means.
- **A filter with no conditions claimed the whole mailbox.** "If this is any
  message → …" read the same whether the rule was scoped to one account or all
  of them, which is exactly the case where the account *is* the rule. It says
  which account now, and picking one updates the sentence and the match count
  immediately instead of waiting for the next edit.
- **A Graph subscription this install no longer has filled the log.** Microsoft
  keeps delivering to a registration it still holds — several times a second,
  for up to three days — and without the account there is nothing here that can
  cancel it. Logged once an hour per subscription instead of once per
  notification, so it stops burying everything else and lighting the unread-log
  ring over something nobody can act on.
- **"Matches 1 existing messages"** in the filter editor.

### Changed

- **The image tag is the first optional setting in the TrueNAS compose file**,
  with what each channel means: `:latest` for releases, `:main` for every merge,
  and the pinned forms. Also says the image tag carries no leading `v`, and that
  downgrading is not supported because migrations run forward on boot.
- **The README tour is current, and anyone can retake it.** `app:test:seed-demo`
  writes a believable demo mailbox, so the screenshots no longer depend on one
  person's installation; all eight are regenerated, and filters and the calendar
  are in the tour for the first time.

## v0.0.14 — 2026-08-03

No schema change, no deployment change.

### Fixed

- **The sidebar showed labels that no longer existed.** It was carried across
  navigations rather than re-rendered, so after a data reset the deleted labels
  stayed in the nav linking to 404s, and a label created or renamed anywhere
  else was invisible until a full reload. It renders per visit now; scroll
  position, open trees and the expanded account are restored rather than frozen,
  the last of those server-side so its folder rows are in the first paint
  instead of arriving one request later and blinking.
- **Sidebar highlights were inconsistent.** A hover left behind a square bar as
  it faded, accounts and their folder rows were rounded boxes rather than the
  pill every other row has, an open folder under an account was not highlighted
  at all, and a label's parents gave no sign that the open row was inside them.
- **An account's folder badges counted every account.** Clicking one lists that
  account's threads alone, so the badge beside it promised mail the list did not
  then show.
- **Snoozing an Outlook message went looking for a folder that does not exist.**
  Snoozed is plMail's own role with no Exchange counterpart, but every role was
  treated as folder-backed — so each snooze logged a missing `graphFolderId`,
  and a message both snoozed and archived looked like it was in two Exchange
  folders at once. Roles are folders only where the provider has one, and are
  never published as categories.
- **The unread-log ring cleared in the database and not on screen.** The log
  browser is a frame and the user menu is not inside it, so reading or clearing
  the log left the outline until the next full page render.
- **The calendar pane kept limits from a window size that was gone**, which is
  what made the resize handle stop at odd places after the window changed.
- **The settings pages had no live updates.** Their layout named a Stimulus
  controller — `mercure` — that stopped existing when the controllers were
  grouped into directories.
- **Admin and settings pages threw on every load.** `mail--mail-pane` was
  attached to every authenticated page but its targets exist only in the mailbox
  layout, so it failed to connect with "Missing target element".
- **A failed Microsoft Graph sub-request logged only its status.** Graph's own
  reason was sitting unread in the sub-response body, which is the difference
  between a diagnosable 400 and a mystery.

### Added

- **The thread view shows each message's own labels.** A rule that files a
  single reply was visible in the list row and nowhere in the thread it opened.

## v0.0.13 — 2026-08-03

No schema change, no deployment change. The encoding fix applies to mail synced
from now on — see the note below about what is already stored.

### Fixed

- **Mail composed in UTF-8 but labelled ISO-8859-1 arrived as "GrÃ¼ÃŸe".** A
  common sender bug, and every layer believed the label: IMAP bodies are
  converted inside webklex, which had no reason to doubt it, and encoded-word
  subjects went through iconv, which cannot. Bytes that are valid multi-byte
  UTF-8 are now read as UTF-8 whatever a single-byte label claims — the label
  is contradicted rather than merely ambiguous. Genuine latin-1, cp1252
  punctuation and multi-byte charsets like Shift_JIS are unaffected. Applies to
  mail synced from now on; messages already stored keep the bytes they were
  given.

### Added

- **The message details popover says why a message is in the tab it is in.**
  "Promotions — a bulk-mail header: list-unsubscribe", "Primary — you have
  written to this sender". Recomputed from the same class that made the call
  rather than stored, so it can never explain a decision the current rules no
  longer make.
- **A filter no longer needs a condition.** "Act on everything arriving in this
  account" is a rule people want and could not write: the editor demanded at
  least one condition and the validator rejected a rule without one. The editor
  still opens with one condition to fill in — removing it is now how the
  whole-account rule is written, and the panel says what it will then do. The
  live match count is scoped to the rule's account too, which it never was.

- **The user menu is outlined when something has been logged that nobody has
  read.** Amber for warnings, red once anything reached error, with the count
  in its tooltip and beside the Admin entry inside the menu, which then links
  straight to the log browser. Opening that browser is what marks them seen, so
  anything logged while it is open comes back. Admins only — for everyone else
  it would be an alarm about a screen they cannot open. The mark lives in the
  user's settings bag, so there is no schema change.

## v0.0.12 — 2026-08-03

No schema change. Dev stacks need the worker fix below applied to their own
`compose.override.yaml` before anything consumes a queue again.

### Added

- **Collapsed admin panels say what is inside them.** A shut card showed a
  heading and nothing else, so a collapsed dashboard was a list of nouns. Each
  now carries a one-line summary in its header — "2 down", "14 waiting",
  "3/4 armed" — coloured when it is worth noticing.
- **The queue panel is searchable and no longer unbounded.** The backlog list
  has a fixed height with its own scrollbar, loads the next page as that scroll
  reaches the end, and its filter box queries the whole queue rather than the
  page on screen: handler name, queue, or anything in the payload.
- **Search suggests as you type.** The box offers the operators it actually
  supports, and for `from:`, `to:` and `cc:` it completes against your contacts,
  so a sender can be found without remembering how they spell their address.
  Arrow keys move, Enter or Tab accepts, Escape dismisses the list before it
  clears the box.
- **`label:` and `cc:` search.** Both were half-built: `cc:` did nothing at all
  and `label:` was understood by the query builder but never parsed.

### Fixed

- **A collapsed panel beside an open one kept the open one's height.** Grid
  items stretch by default, so shutting one of a side-by-side pair left a 40px
  header inside a 400px outline.
- **Search operators failed silently.** `in:archive` was documented but never
  mapped, so it was dropped and the search answered with everything, unfiltered
  — as did any unknown value, like `is:important`. A half-typed `from:` did the
  same, matching every message through an empty LIKE. Unrecognised operators
  now fall through to free text, an incomplete one filters nothing, and
  `in:archive`, `in:snoozed` and the aliases `bin`, `deleted`, `archived` and
  `draft` are understood.

- **The dev override still described the pre-split worker.** v0.0.10 replaced
  `messenger-worker` with three services but left `compose.override.yaml.dist`
  defining the old one, so a dev stack ran a worker consuming only the retired
  `async` queue: sends and syncs queued up and nothing consumed them, with no
  error anywhere. Copy the new `.dist` over your `compose.override.yaml`, or
  add the three `worker-*` blocks to it, then
  `docker compose up -d --remove-orphans`.

## v0.0.11 — 2026-08-03

No schema change, no deployment change.

### Added

- **The queue panel says what the workers are doing.** It showed a depth and an
  age per queue, which cannot tell a queue that is empty from a queue that is
  stuck — both read as "nothing waiting". It now lists the messages a worker is
  holding right now, each with its handler, its payload and how long it has been
  held, above a per-queue breakdown of pending and in-flight counts, and the
  backlog behind them: what is waiting, what is merely scheduled for later, and
  how often each has already been retried. A message whose envelope no longer
  deserialises is listed as undecodable rather than skipped — it is stuck
  forever, and was previously invisible.

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
