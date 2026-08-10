# Changelog

Notable changes per release. plMail runs its migrations automatically on boot,
so anything that changes the schema irreversibly is called out explicitly.

The published image tags: `latest` follows the most recent release below,
`main` follows the tip of the default branch, and `sha-…` pins one commit.

## v0.0.26 — 2026-08-10

**No migration. Every theme now defines every variable — all 38 of them,
explicitly, in every palette.** Three themes were four-line diffs against a
light-tuned base, which is how Nord got the light theme's link blue on a
near-black surface and an ink ramp that ran backwards. The real remnant bug
was worse than CSS: picking Paper seeded its clay accent onto the account
itself, where it survived onto every theme chosen afterwards — and picking
System on a dark desktop painted the app light until the next navigation.
Both fixed. A parser-backed test now fails the build if a theme ever misses a
variable, and a browser test switches through every theme without reloading
and asserts the result is pixel-for-pixel what a fresh load produces.

**The reading pane wears the theme.** Two conflicting definitions of the mail
sheet had been shipped, and the older plain-white one was silently winning —
the warm-paper redesign was dead code. Resolved in the redesign's favour, and
the sheet's seven colour channels now come from the theme: Nord reads mail on
snow storm, Solarized on its own base, Paper on cream. This is why the thread
list looked like the odd one out on the light theme — now the whole reading
surface agrees with wherever it is.

**Search respects the bin.** A deleted conversation no longer turns up in
search results through its other labels — the same rule the list views
learned in v0.0.25, applied to search's own SQL. `in:trash` and `in:bin`
still find it on purpose.

**The default calendar speaks your language.** A user provisioned in German
gets "Persönlich", not "Personal". Calendars that already exist keep their
names — they are the user's data, and may have been renamed.

The E2E dev server on CI restarts itself if it crashes, so one segfault can
no longer disguise itself as forty-five failing tests.

## v0.0.25 — 2026-08-10

**No migration. A message that moves is still one message.** Moving mail —
trash, archive, snooze, a filter filing something away — used to leave the row
holding its *old* IMAP address, so the destination folder's next sync met the
real message as a stranger and inserted it again: one ghost per move, which is
how a mailbox of 35 became 86. Moves now mark the row as relocated and sync
reconciles it by Message-ID; an external move is told apart from a genuine
copy by asking the server, and an answer of "could not tell" never merges and
never deletes. Installs already carrying ghosts heal on their own syncs — each
duplicate group collapses onto the copies the server actually confirms, and a
message whose every copy is unconfirmed is left strictly alone. The
flags-worker no longer warns forever about vanished UIDs, and a
half-completed move on a server without native MOVE support can no longer be
retried into a second copy.

**Archive works now.** Archiving removed the Inbox label and added nothing, so
the Archive view — which lists by the Archive label — was permanently empty.
Archived mail is now filed under Archive, keeps its other labels, and the
sidebar's "More" section stays open while you are inside it instead of
snapping shut on every navigation. Spam also actually exists: the sidebar
visibility toggle had nothing to show, because there was no Spam view at all —
there is now, in every locale.

**Trash stays in Trash.** A trashed thread kept its other labels and went on
listing under them; every list and unread badge now excludes trashed mail
except the Trash views themselves. Search is deliberately unchanged for now —
it has its own query builder and an `in:trash` operator that deserve their own
change.

**The little lies of the UI.** The onboarding integrations row wraps instead
of forcing the modal to scroll sideways, and provider names no longer break
mid-word. Dropdown search results mark exactly what was typed, in bold,
without the phantom trailing space (a flex gap painted beside the highlight) —
and the search box inside long dropdowns, which the v0.0.22 theming had
silently removed from every select in the app, is back. Selecting text in a
modal and releasing the mouse outside no longer dismisses the modal — a
dismiss now requires the whole gesture, press and release, on the backdrop.
Switching density no longer rewrites your background setting as a side effect
(that was the "page breaks until reload"). Two-factor copy now protects your
"account" rather than your "mailbox", in every locale.

**The calendar E2E specs drive dropdowns the way a person does.** Ten specs
still fired the retired native-select gesture at the themed combobox and died;
they now go through a shared helper that operates the widget by its ARIA
contract — which doubles as a regression tripwire on the accessibility the
theming promised. A new spec pins that contract directly.

## v0.0.24 — 2026-08-10

**No migration, and no runtime change at all — this release exists to turn the
release checks back on.** The E2E workflow had been red on every tag since
v0.0.19: a backup-exporter test hardcoded that `APP_ENCRYPTION_KEY` must appear
in an exported config, which is true wherever the key is set in the real
process environment (compose does this) and false on the CI runner, where the
key exists only in `.env.test` — a layer a backup deliberately does not read,
because the shipped `.env` defaults are placeholders and restoring
`mailto:admin@example.com` over a working install is the exact harm the
exporter is scoped against. The exporter was right; the test now derives its
expectation from the exporter's own rule and pins both directions, so it can
never again be green on one harness and red on the other. If this tag's E2E
run is green, that is the fix proving itself.

## v0.0.23 — 2026-08-10

**No migration. A reply you send is one message again.** A message sent from
the web frontend on an IMAP account used to come back from the Sent folder as
a stranger: the composed row carried no Message-ID, and the MIME was left for
Symfony to stamp — which mints a fresh id on every serialisation, so the copy
that went to the recipient and the copy appended to Sent did not even agree
with each other. The next sync inserted the Sent copy as a second message in
the same conversation. Sends now mint one Message-ID before anything reads the
mail, written to the row and the headers alike, and the Sent-folder sync
reconciles on it — a provider's own auto-saved copy is recognised and dropped
too. The rows the old path left behind repair themselves: the next sync of the
Sent folder pairs each ghost with its imported twin and removes it, so
affected conversations heal without anyone touching them.

**Answering a conversation moves it back to the top.** Thread lists sorted on
the last message the thread *received*, so your own reply left the
conversation buried under everything that had arrived since. Sending now
records the activity the same way an arrival does — forward only, so a
backdated arrival cannot drag a live conversation down, and draft autosaves
never reorder anything. The Sent list sorts by your own send dates as a
consequence rather than by luck.

Two counters that were only ever right by accident of the duplicate are right
on their own now: a reply draft counts toward its conversation's message
count, and a sent reply updates the conversation's labels immediately instead
of waiting for the duplicate to drag the Sent label in.

## v0.0.22 — 2026-08-09

**One migration runs, and it is irreversible — but it changes no table.** It
deletes the per-account "sync only the last X mails" setting from the settings
bag, because the feature is gone: the cap applied per sync run, not per
mailbox, so an account that outgrew it between runs lost the *middle* of its
mail — interior holes, not a window. Removed everywhere: the settings control,
the caps in both the IMAP and Gmail syncers, and the capability the app read.
An account that wants its full history re-syncs (`app:reset`) or is re-added;
previously capped Gmail accounts simply complete their backfill on the next
sync.

**Dropdowns finally wear the theme.** Every user-facing select is a themed
widget now — the same one compose's recipient fields always had — because a
native select's popup obeys the operating system, not the stylesheet, and
looked pasted-on in every dark theme. The native selects remain underneath as
the source of truth: forms submit identically, and without JavaScript
everything degrades to working controls. Includes the five selects the filter
rule builder creates in JavaScript, which no earlier styling pass could reach.

**Finishing a login twice is not forbidden.** Submitting the two-factor code
twice — a double click, a second Enter, the browser's "resend?" after Back —
answered the second attempt with a bare 403 at the exact moment the login had
succeeded. The resubmit now lands where the first submit went, and the form
guards itself against double clicks.

**The backup sheds three more machine-local values.** `MERCURE_JWT_SECRET`
pairs the app with the hub container *beside it* — restoring another
machine's copy re-keys half of a running pair and kills every live update
until the whole stack restarts. `APP_SECRET` signs cookies and nothing
durable — the only thing another machine's copy can do is keep another
machine's cookies alive on the new install. `TRUSTED_PROXIES` describes the
machine's own reverse-proxy chain. Old backups carrying any of them import
fine; the values are classified and left alone.

## v0.0.21 — 2026-08-09

**No schema changes.** The backup grew a format version, not a table.

**A config backup knows its own operator now.** Backups carry every user and
everything they configured: password and app-password hashes, two-factor
secrets and recovery codes, mail accounts with their IMAP/SMTP credentials
and OAuth tokens, aliases, integrations, filters, labels, calendars, and
share and booking links — whose published URLs keep working after the move.
Everything sensitive travels only inside the encrypted envelope, re-encrypted
under the target install's key on arrival. Restoring onto a fresh install
ends at sign-in instead of "create the administrator"; importing onto a live
install never overwrites an existing user — found means kept, and the review
says so. Older plMail versions refuse the new format whole rather than
applying half of it. Mail, calendar entries, sync state and per-browser
grants stay out, deliberately.

**The restore page stops listing non-tasks.** Values already identical to the
environment are a footnote, not a chore; `MAILER_DSN` and
`MESSENGER_TRANSPORT_DSN` left the export entirely, joining `DATABASE_URL` as
machine-local deployment choices the compose file owns; and the
old-encryption-key note folds behind one line for the only reader it
concerns — someone also restoring their old database.

**One browser, one remembered device.** With "remember this device", every
sign-in quietly added another row to Settings → Security: the 2FA library
re-registers a trusted browser on each login to extend its lifetime, and the
database-backed manager read that as "insert a sibling", orphaning the old
row and its cookie each time. The manager now renews the grant the presented
cookie actually resolves to — same row, same secret, expiry pushed out.
Matching is on the cookie alone, never user-agent and address, so two
identical laptops behind one router stay two rows; revoked grants are never
resurrected. Existing duplicates age out or clear with "Revoke every
remembered device".

**Settings looks finished.** The sidebar is grouped — Personal, Mail,
Calendar, Access — instead of thirteen equal links; file pickers look like
the buttons they are (Tailwind's preflight had stripped them bare); and every
native select shares one theme-aware look instead of six accidental ones,
including three that ignored the accent colour.

## v0.0.20 — 2026-08-08

**No schema changes.** Templates, one controller, and the calendar's layout
plumbing; nothing touches a table.

**A shared calendar is a calendar now.** The public share page grows the same
four views the owner has — month, week, day and agenda — with a switcher whose
every view is a plain link, because the page has no session to keep a choice
in and must not acquire one. The day-by-day list that used to trail under the
grid is gone; it became the agenda view. Days the link does not publish stay
visibly "not shared" in every view, paging still stops at the window's edges,
and busy/free links get all four views with nothing more to leak: the grid's
layout engine asks entries only for a start, an end and whether they run all
day, so it structurally cannot place a title anywhere.

**Both calendars now render through the same shells.** The toolbar, time grid
and agenda were extracted out of the authenticated calendar and both pages
draw through them — a restyle of one is a restyle of both, and the shared page
can no longer drift out of looking like plMail.

**Three bugs fell out of building it.** A shared page shown in the owner's
zone captioned a Berlin August as "July 2026" (the owner's own calendar was
shielded by middleware the public page rightly lacks); the shared agenda's
back-step was built from a phrase PHP accepts and does not mean, so stepping
backwards silently refused; and one long multi-day entry could cost a public
GET millions of date additions — the spread is now bounded by the page being
drawn. Switching views also keeps your place now: the switcher carries the day
you were looking at, not the first of the month it was filed under.

## v0.0.19 — 2026-08-07

**No schema changes.** Everything below lands in code and templates; nothing
touches a table.

**One meeting is one row, everywhere.** The time grid always collapsed a
meeting that lives on two calendars into a single chip with a multicoloured
dot; every other surface listed each copy separately. The same collapse now
runs in "Happening soon", on shared calendar pages and their `.ics` feeds, and
in the alert sweep — which was quietly the worst offender, sending two pushes
per reminder for a two-calendar meeting.

**Search sorts by date, and says so.** Results used to come back
relevance-ordered, which put a 2004 eBay mail above last week's. The default
is now most-recent, with a dropdown beside the pagination for most-relevant,
and the choice follows you to your other devices. Fixed underneath, because it
had to be: neither order was total, so paging through tied rows could show a
result twice and another never — measured at 148 distinct rows out of 150
before the fix. The search page also finally speaks the reader's language;
its headings and filter pills were English written into the template.

**Restoring a backup is uploading a backup.** The import used to print a wall
of `.env` lines to paste by hand, built on the assumption those values were
hand-maintained — they are generated on first run, so the import now writes
them where the entrypoint reads them and asks for one restart instead. The
database's own credentials left the export entirely: they are machine-local,
consumed once at initdb, and useless-to-harmful on any restore target. Old
backups still import; those entries are recognised and left alone. Beside the
export form there is now a generate button that fills both password fields and
puts the password on your clipboard — and where the browser withholds its
clipboard, the fields unmask instead of pretending.

**The badge that would not die is dead.** FrankenPHP keeps services alive
between requests, and four topbar helpers memoised per request without ever
being reset — so the first answer a worker computed was the answer every later
request got. That is the yellow log-alert outline that survived an emptied log
table, and on a multi-user install two of the four could show one user's
sidebar counts or calendar dot to another. All four now reset per request,
like the services that always did.

**Registered devices can be removed from settings.** A push subscription that
delivers perfectly well to a phone that no longer wants it — an old build's
leftover registration, a retired device — used to be a `DELETE` typed into
psql. It is now a button in Settings → Notifications, and the delivery log
deliberately keeps the removed device's history.

**Shared calendars and booking pages look like plMail now.** A share link
renders the app's own month grid — today marked, chips in place, prev/next —
in the owner's theme, falling back to Paper; booking pages get the same
treatment. What a link does not reveal is unchanged, and deliberately so:
chips on a busy/free page carry the owner's accent rather than calendar
colours, because colours would let a stranger group anonymous blocks by which
diary they came from. Also fixed: the share page had printed the literal text
`calendar.share.window` under its heading since the feature shipped.

## v0.0.18 — 2026-08-06

**Five schema changes, all additive and applied automatically on boot.**
`calendar_event` gains `my_participation` and `label_binding` gains
`graph_category_id` (neither backfilled — the reasons are in the migrations and
are worth reading before upgrading, because one of them changes when an
invitation appears on your calendar). New tables `fcm_config` and
`push_delivery`, and `message` gains two submission timestamps; those three
carry the push and scheduled-send features below and touch nothing existing.

**An invitation lands on the calendar when you accept it, and not before.**
*Yes* or *Maybe* puts it there, *No* takes it back off, and nothing about that is
one-way — the invitation itself never goes anywhere, so changing your mind later
moves the meeting on or off again. Until now every invitation was drawn the
moment the mail arrived, so a week of unanswered requests looked exactly like a
week somebody had agreed to. Only invitations addressed to you are affected: a
flight confirmation, a mirrored Google calendar and a meeting you organised
yourself all appear as they always did.

Invitations that arrived **before** this upgrade keep their old behaviour and
stay on the calendar whatever you answered — emptying somebody's calendar during
a `docker compose up` is not an acceptable way to ship a feature.
`app:backfill events` re-reads the mail and brings them into line.

**Events read out of mail land on your default calendar**, not on the
per-account one. A person has one diary; which mailbox a flight confirmation
happened to arrive at is a property of the message, not of the flight, and
filing by it split one day across as many calendars as you had accounts. The
per-account calendars still exist, and `Account::SETTING_CALENDAR_TARGET` still
points at one for anybody who wants the split.

**A meeting you put on a second calendar still hears its own updates.** Ticking
another calendar in the editor was recorded as an edit, which is the flag that
tells plMail to stop letting mail revise an event — so a shared meeting went
quiet, and the only symptom was a reschedule that never arrived. Sharing is not
correcting; and a later message now updates every copy rather than the one on
the calendar extraction happens to file to.

**All-day events are no longer two hours long, or on the wrong day.** They are
floating — a wall-clock date with no zone — and every reader was converting them
into one anyway, so an all-day invitation opened in the editor reading
"02:00 – 02:00" for anyone east of UTC and was drawn on two days. West of UTC the
same arithmetic moved it onto the day before. The container's own timezone was
never the problem and is still deliberately UTC.

**A twelve- or twenty-four-hour clock, in Settings → General.** It reaches every
time plMail prints — the mail list, a thread, a calendar chip, the day grid's
hour axis. The default is *Follow your language*, and it stays that state rather
than being silently converted into whichever format it currently resolves to.

**"Happening soon" lists what you typed as well as what was found in your mail.**
It filtered on "was this extracted?", which meant the one thing a person had put
in the calendar themselves was the one thing missing from the list telling them
what is coming up.

**Double-clicking empty space on the time grid creates an event there**, at the
quarter hour under the pointer. A single click deliberately does not — it cannot
be told apart from the end of a drag without arbitration nobody would enjoy
maintaining — and the **+** in each day heading stays, because a double-click is
not a gesture a keyboard has.

**Every day column lines up with its own heading again, and every chip prints
the hour it is drawn against.** The headings and the all-day band were siblings
above the scrolling hours, so they were as wide as the pane while the hours were
as wide as the pane minus the scrollbar; all three share one scroll container
now. Separately, a block was positioned against the calendar's zone while the
time inside it was printed on the reader's, so a meeting on the 15:00 line could
say 17:30. Both are the same kind of fault — one grid, two clocks — and neither
looked like an error.

**The calendar switch has three positions**: mail, split, calendar. The third is
a full-width calendar without navigating away from the mail — the mail is still
there behind it, so coming back is instant. The drag handle reaches the same two
ends: past the pane's limits it keeps moving with resistance, and letting go past
the threshold switches. The docked pane also draws the same time grid the page
does now, rather than a column list of its own, and choosing Week or Month
widens it to fit — animated, and only ever upward.

**Renaming a label no longer creates a second Outlook category.** With label sync
on, renaming a category that had come from Outlook created a new one under the
new name and left the old one standing, which the next sync imported back as a
label beside the renamed one. A master category is now addressed by the name it
had, its id is recorded when Outlook is read, and every message carrying the old
name is pushed again so it carries the new one. Also fixed alongside: a category
id was being written into the column that means "this label is an Exchange
folder", so a label plMail created as a tag started being pushed as a location.

**Push can reach a phone through Firebase now, beside Web Push.** Configured at
runtime under Admin → Push: paste your own Firebase project's service-account
key and its `google-services.json`, and the session starts advertising the
public half — which is how one stock Play-Store build of the Android app
configures itself against whichever instance it is signed into. No Firebase, no
Google: Web Push and pull-only still work exactly as before, and a client is
told honestly which transports this instance can actually deliver over.
Messages travel as data-only payloads; Google learns that something arrived and
when, never what it says.

**Every push attempt is on the record.** Admin → Push → Deliveries lists what
was sent to which device over which transport, with the outcome, the error name
that tells a dead phone from a Firebase outage, and the latency; your own
devices and their last delivery appear in Settings → Notifications. Nothing of
a payload beyond its type is stored, and the record outlives the subscription
it is about. Pruned after 30 days.

**A scheduled send is now something the server admits to.** `EmailSubmission/get`
answers a held submission as `pending` with its real release time, a cancelled
one as `canceled`, and the changes feed reports both transitions — so a mail
scheduled on one device is visible, and cancellable, from every other. Found
and fixed alongside: cancelling a mail that was never submitted used to arm a
flag that silently swallowed that draft's *next* send.

**JMAP grew the surface a native client was waiting for.** `Email/set` update
applies `attachments` instead of silently dropping them (a refused patch now
writes nothing at all, the subject beside it included); `EmailSubmission/set`
honours `identityId`, so sending as an alias actually sends as the alias;
scheduled send via the standard FUTURERELEASE envelope parameters, up to thirty
days; `Contact/autocomplete` serves recipient suggestions ranked by the same
query the web composer uses; `Appearance/get|set` syncs the theme a user chose;
the session states each account's sync window instead of leaving a client to
probe for it; and `CalendarEvent/query` expands recurrences server-side, so
drawing a month of a repeating series costs one round trip instead of
thirty-one. Every extension sits under a `urn:plmail:` URN and is documented in
`docs/CLIENT_DEVELOPMENT.md`.

**Your configuration fits in one file you can actually restore.** Admin →
Backup exports the instance's secrets and credentials — env values, the JWT
keypair, the provider and Firebase credentials, decrypted so they survive the
move to a machine with a different encryption key — sealed with a password of
yours (Argon2id + secretbox, format documented down to the shell one-liner that
opens it). Import shows its plan before touching anything, applies what it
honestly can, and prints the exact lines for what only you can place. A fresh
install offers the same restore on `/install`, before the first account exists
and never after.

## v0.0.17 — 2026-08-05

**Two schema changes, applied automatically on boot.** `calendar_event` gains a
`remote_instances` map and `calendar_event_occurrence` a start-only index;
`calendar_alert_delivery`, `calendar_share_link`, `calendar_share_link_calendar`,
`booking_page`, `booking_page_calendar` and `calendar_booking` are new tables.
All additive; nothing existing is altered or rewritten, and an install that
neither shares a calendar nor sets a reminder is unaffected by any of it.

**One deployment change, and read it before pulling.** `compose.yaml` now mounts
three named volumes it should always have mounted — `app_attachments`,
`app_raw` and `app_uploads`. If you have been running the stock `compose.yaml`,
your attachments and raw sources are currently inside the containers rather than
beside them, and **mounting an empty volume over that directory hides what is
there now**. Nothing is deleted, but the files stop being visible.

Before `docker compose up -d`, copy what the running containers hold:

```bash
docker compose cp php:/app/var/attachments ./attachments-backup
docker compose cp php:/app/var/raw ./raw-backup
```

then bring the stack up and copy them back into the new volumes:

```bash
docker compose cp ./attachments-backup/. php:/app/var/attachments
docker compose cp ./raw-backup/. php:/app/var/raw
```

An install that already used `compose.override.yaml` or `truenas.compose.yaml`
has these mounts and is unaffected.

### Fixed

- **Attachments downloaded fine and then 404'd, and everything vanished on a
  restart.** The stock `compose.yaml` — the file the README tells you to run —
  mounted no volume for `var/attachments`, `var/raw` or `var/uploads`. The
  workers write those during sync and the web container serves them back, so
  each kept its own copy in its own layer: the file was written by a process
  that was not the one answering the request, and both copies died with the next
  container recreate. `compose.override.yaml.dist` and `truenas.compose.yaml`
  both mount them, which is why this survived — the one path an operator
  actually follows was the one that did not. The same omission in the `.dist`
  cost real data once before; see the deployment note above for moving what is
  currently stranded.

- **A repeating event quietly ran out of dates, and its reminders with it.**
  Occurrences are drawn when an event is saved, two years ahead, and nothing
  moved that window afterwards — so a weekly standup created today reached six
  months in eighteen months' time and eventually stopped being drawn at all.
  Reminders read those rows, so they stopped too. Silently: the event still
  existed and still said it repeated weekly. `app:calendar:materialise` now rolls
  the horizon forward nightly. The query it runs had existed since the
  materialiser did, documented as exactly this sweep, and nothing had ever called
  it.

- **One mailbox with an unusable address could stop everybody's reminders.** The
  reminder mailer built its message outside its own error handling, and an
  account whose username is not an email address threw before anything was sent
  — ending the whole minute's sweep for every user on the install, once a
  minute.

- **The Microsoft setup wizard asked for three permissions out of the nine
  plMail requests.** Following it produced an account with no categories and no
  calendar, and Microsoft does not upgrade a token already issued when the
  permission is added later.

### Added

- **The week is a time grid you can drag events around on.** Hour rows, blocks
  sized by their real times, meetings that overlap sharing the width, and
  all-day events in a strip of their own rather than squashed against midnight.
  Drag one somewhere else to move it, drag its edge to make it longer. Dragging
  one occurrence of a repeating event asks the same question the editor does —
  this one, or all of them — and read-only calendars refuse the drop and say
  why. The docked pane keeps its column list: a 380px pane has no business
  drawing a grid.

- **Calendars go in and out as `.ics`.** Download one event or a whole calendar,
  upload a file into a calendar you pick, or subscribe to a published calendar
  by URL — `https://` or `webcal://` — and have it refreshed on the same
  schedule everything else is. A subscribed feed is read-only. Importing the
  same file twice updates rather than duplicates, and an event that arrived by
  invitation is recognised as the same meeting rather than added again.

- **Reminders.** Six one-click offsets — at the time, five, ten or thirty
  minutes, an hour, a day — plus any number of minutes you like, and as many
  reminders on one event as you want. They arrive as a browser notification or
  as mail. A reminder set on a repeating event fires for each occurrence, and a
  reminder never fires twice however many times a sweep is interrupted or
  replayed. Reminders travel to and from Google, Microsoft and CalDAV, and are
  written into an exported `.ics`.

  **A fresh install can deliver neither.** Browser notifications need the
  browser to have been given permission, and mail needs an account that can
  send. Until one of those exists, a reminder is stored and nothing arrives.

- **What is happening soon, beside the mail it came out of.** A control in the
  top bar, which appears only when there is something to show and wears the icon
  of whatever is next — a plane, a parcel — opens a list of the next fortnight's
  bookings, each naming the message it was read out of.

- **Calendars over JMAP**, under `urn:plmail:params:jmap:calendars`:
  `Calendar/get`, `CalendarEvent/get`, `CalendarEvent/query` and
  `CalendarEvent/set`. An edit made by a JMAP client reaches Google and
  Microsoft exactly as one made in the browser does. Client authors: an event id
  is the series rather than a dated occurrence, and a query must name a date
  window — see the handbook.

- **A handbook.** [The wiki](https://github.com/karatektus/pl_mail/wiki) now has
  34 pages: every feature and how to use it, installing on Docker, Linux, WSL2,
  macOS and NAS boxes, a complete environment-variable reference, step-by-step
  registration of the Google and Microsoft applications with the exact
  permissions to tick, and seven pages on how the internals work. It is
  generated from `docs/` in the repository, so it cannot drift from the code —
  and a build now fails when a command, a variable, a link or a page goes
  undocumented.

- **You can send somebody a link to your calendar without giving them an
  account.** Settings → Sharing makes one. Tick-boxes on the link decide what it
  says: with none of them ticked it shows only when you are busy, and each box
  adds one thing on top — the title, the location, the description, who is
  coming. You choose which calendars it covers and whether it shows a rolling
  window from today or two fixed dates, you can change all of that afterwards
  without the address breaking, and you can revoke it. An event you marked
  private stays a plain busy block whatever the link says, and one you marked
  secret does not appear at all. There is an `.ics` beside the page for people
  who would rather subscribe than look.

  **The address is shown once, when you create it.** It is a password to your
  diary, so plMail stores only a hash of it — the same treatment device pairing
  codes get. That means it cannot be shown to you a second time: copy it when it
  appears, and if you lose it, press regenerate, which gives the link a new
  address and kills the old one.

- **People can book an hour of your day.** A booking page publishes the weekdays
  and hours you are available, how long an appointment is, how much room to leave
  around one, how far ahead somebody may reach and which calendar the booking
  lands on. The times it offers are free against your real calendar — every
  calendar you nominate, including mirrored ones — so cancelling a meeting brings
  its hour back and moving one takes the gap with it. Whoever books gives a name,
  an address and optionally a note, and gets a confirmation with a calendar file
  attached.

  **Two people cannot take the same slot.** That is enforced by the database
  rather than by a check, so it holds when both of them press the button at the
  same instant; the second one is told the time has just gone and shown what is
  still free. A booked appointment appears in your calendar marked as one, and
  lands on the calendar you nominated — so if that calendar syncs to Google,
  Microsoft or a CalDAV server, the booking is on your phone as well.

- **An event can be put on another calendar, including one you sync.** The
  editor's calendar list is now every calendar you have, ticked wherever the
  meeting already is. Tick one it is not on and a copy is made there — on a
  mirrored calendar that means it is pushed to Google, Microsoft or your CalDAV
  server straight away, which is how a booking read out of an email gets onto
  the calendar on your phone. The copy is the same meeting rather than a second
  one, so the views still draw a single entry, and a later update from the
  organiser still reaches every copy of it.

### Changed

- **The event editor's calendar dropdown is gone.** The checkbox list does
  everything it did and one thing it could not, and two controls answering
  "which calendars is this on?" can disagree — a calendar picked in one and
  three boxes ticked in the other is a meeting asked to be in three places at
  once. Unticking a calendar still leaves that copy exactly as it was rather
  than removing it; Delete is what removes, and it reads the same ticks. Moving
  an event to a different calendar is therefore two steps now: tick the new one
  and save, then re-open, tick only the old one and delete.

## v0.0.16 — 2026-08-05

**Three schema changes, applied automatically on boot.** `calendar_event` gains a
sync state and a synced-at stamp, `calendar` gains a last-synced-at and a
last-sync-error, `calendar` also gains four push-registration columns, and
`event_proposal` is a new table. All additive; nothing is dropped or rewritten,
and an install that never connects a calendar is unaffected by any of it.

**Google and Microsoft accounts now ask for calendar permission at sign-in.**
Existing connections keep working for mail either way. To let them carry
calendars, enable the Google Calendar API and add the `.../auth/calendar` scope
in Google Cloud, or add the `Calendars.ReadWrite` delegated permission in Azure
— there is no second app to register and nothing to configure in plMail.

### Added

- **Calendars from Google, Microsoft and CalDAV, synced both ways.** Subscribe
  from Settings → Calendars: plMail asks the account what calendars it has, you
  tick the ones to mirror, and changes travel in both directions. Google and
  Microsoft ride the same sign-in you already use for mail. CalDAV is a
  connection of its own — give it an address and it finds the rest, with the
  option to reuse a mail account's stored password rather than typing an app
  password, off by default because most servers want a separate one anyway.
  Each mirrored calendar says where it comes from, whether it accepts writes,
  when it last synced and why it stopped, because a calendar that syncs on a
  sweep nobody watches is the only kind that can break silently.

- **A date in an ordinary email can be added to the calendar.** Not an
  invitation, just a sentence — "Termin wie vereinbart: 04.08.2026 um 14 Uhr",
  or "let's meet Saturday at 3pm". A card offers it, quoting the line it read so
  the guess can be judged at a glance, and nothing reaches the calendar until
  somebody says yes. German and English, explicit and relative dates, durations
  where they are stated. "Not an event" is remembered, so it is not offered
  again.

- **One occurrence of a repeating event can be changed on its own.** Saving or
  deleting a recurring event now asks whether you mean this one or all of them,
  and only asks when the event actually repeats. Moving a single occurrence
  leaves its siblings where they were — and choosing "all events" from an editor
  you opened on one occurrence applies what you *changed* rather than where that
  occurrence happened to be, so renaming a weekly meeting from next month's
  entry does not move the meeting to next month. A single-occurrence change
  reaches a CalDAV server; Google and Microsoft take the series but not yet the
  instance, so on those it stays local for now.

- **Google and Microsoft calendars can arrive by push rather than being waited
  for.** Where the installation has a public HTTPS address, a change made
  elsewhere shows up in seconds instead of at the next quarter-hour sweep.
  Google additionally refuses to open a channel unless the callback domain is
  verified in the Cloud project that owns the OAuth client; the admin
  diagnostics panel says so, and Microsoft needs no equivalent step. Without a
  public address nothing breaks and nothing needs configuring — calendars stay
  on the sweep, which remains the backstop either way.

- **One meeting that reached plMail twice is drawn once.** An invitation you
  were sent and the same meeting on a connected calendar are two entries for one
  appointment; the views now draw them as a single entry with a dot showing each
  calendar's colour. Editing it asks which copies to change — all of them by
  default, because that is what editing "the meeting" means — and a copy on a
  read-only calendar is listed but cannot be ticked. Untick one and it stops
  matching the others, so it separates back into its own entry, which is the
  point rather than a side effect. Copies that disagree are never merged: if one
  says two o'clock and the other says three, you see both, because a tidier
  screen is not worth hiding that from you.

- **The admin header says which build is running.** The release and the commit
  it was built from, on every admin page — the first thing worth knowing when a
  deployment behaves unlike the branch you are reading, and not otherwise
  discoverable now that migrations run on boot and `latest` moves. A checkout
  that was never built from a tag shows nothing rather than a placeholder.

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

- **A recurring invitation or CalDAV event appeared exactly once.** The
  repeating rule was read and then stored unconverted, and the part of the
  application that draws occurrences reads the converted form — so a weekly
  meeting that arrived by mail, or lived on a CalDAV server, drew a single
  entry and nothing else. One conversion now serves all three sources; a rule
  that cannot be converted faithfully is still refused outright rather than
  half-applied, because an event on the wrong days is worse than one that does
  not repeat.

- **An instance moved out of its series landed in the wrong place, differently
  on every provider** — a duplicate on the new day from Google, the old time
  from Microsoft. Moved and cancelled instances are now carried as the
  per-instance exceptions the format has for them, and CalDAV writes them back,
  so editing a series' title no longer deletes every instance somebody moved.

- **An event accepted from a suggestion could be silently rewritten by a later
  email.** The protection was accidental: the guard only covered events that
  came out of extraction, and what actually kept mail off these was that plMail
  mints identifiers no sender collides with. A message carrying the same
  identifier overwrote the lot. Events a person decided on are now off limits
  to mail by rule, and the refused claim is still recorded.

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
