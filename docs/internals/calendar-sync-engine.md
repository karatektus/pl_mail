# The sync engine

The driver contract every provider implements, the order the engine does things in, what a
dead token costs, push channels versus polling, and how a local edit gets out. The data model
this writes into is described in [The calendar model](calendar-model.md); the user-facing
side is [Connected calendars](../features/calendar-sync.md).

## Three classes, because they answer different questions

| Class | Owns |
|---|---|
| `App\Service\Calendar\CalendarSyncService` | the **order**: push, then pull, then the token; what a dead token costs; what the calendar records afterwards |
| `App\Service\Calendar\CalendarPusher` | how a local row becomes a remote write |
| `App\Service\Calendar\CalendarPuller` | what one remote change means against one local row |

Folding them together would put the conflict rules, the paging recovery and the per-event
mapping in one class of four hundred lines whose test would have to set up a remote to assert
anything about a rule.

Only `CalendarSyncService` flushes. It is the top of a Messenger handler's unit of work
rather than a step inside somebody else's, and the token, the events and the bookkeeping have
to land together or the next run re-reads a window it already applied. The pusher and the
puller join the caller's unit of work, like everything else in `Service/Calendar/`.

## The conflict rules

Two-way sync is only worth having if "changes sync back" is trustworthy, and trust is
entirely a matter of what happens when both sides moved. Seven rules, in the order they
apply, written down because the next person will otherwise reconstruct them from the code and
get the third one wrong.

**1. Push before pull, always.** Every locally pending row goes out first, so the question
the pull then answers — "did the remote change too?" — is asked of a remote that has already
been told. The common case, where the user edited here and nobody else touched it, resolves
to no conflict at all. The reverse order makes every local edit collide with its own echo.

**2. A matching etag is not a change.** Equal etags mean skip entirely: no row write, no
re-materialised occurrences, no `updated_at`. That is the cheap path for the ninety-odd per
cent of a delta window that is an echo, and it is also the correctness rule that stops a pull
immediately after a push from re-applying the remote's copy of the edit over anything the
user typed in between. Both etags must be present for the answer to mean anything — a null on
either side is not "the same", it is "this provider does not version its events", and the
safe reading is to write.

**3. A changed etag means the remote wins.** Not last-write-wins: there is no clock the two
sides share, and comparing a provider's modified timestamp with a container's is comparing
two guesses. The remote is picked because it is the one copy other people can also see —
losing an edit made on a phone is recoverable by making it again, while diverging from what
an organiser and four attendees are looking at is not.

**4. A row that changed on both sides loses its local change, loudly.** Rule 1 means this
only happens when the push for that row failed. It is still resolved remote-wins, and the
discarded JSCalendar object is logged **in full** at warning level before it is overwritten.
That log line *is* the rule: silently discarding a user's edit with no trace is the one
outcome nobody can debug afterwards, and a line saying "an edit was discarded" without saying
which edit answers no question anybody will actually ask.

**5. A read-only calendar is never pushed to.** Asserted, not assumed:
`CalendarPusher::push()` throws a `LogicException` — not a `CalendarSyncException`, because
this is not something that can go wrong at a remote — if it is ever called for one. Pending
rows on such a calendar are left alone and reported once per run by
`reportUnpushableEdits()`. Nothing is discarded and nothing is retried: clearing the pending
state would make the edit vanish on the next pull with no record it was made, and pushing
anyway is what `isReadOnly` exists to prevent.

**6. A dead token costs a full read, once.** `MAX_RESYNCS` is 1. The engine clears
`Calendar::$syncToken` — on the entity as well as in the local variable, so a second pull
that throws still starts from scratch next run — and re-pulls. A driver that answers
`requiresFullResync` to a *null* token has a bug, and looping on it would hammer a provider
forever, so the second one is a `CalendarSyncPermanentException` whose message says whose bug
it is.

**7. A full read is authoritative about deletions.** With no token there are no tombstones,
so a removal that happened while the token was dead is knowable only by absence. Local rows
carrying a remote id the full read did not list are removed —
`CalendarEventRepository::findRemoteRowsNotIn()`. **Rows with no `remoteId` are excluded from
that sweep**, and that exclusion is the load-bearing half: an event made here and not yet
pushed has never been at the remote, so the remote's silence says nothing about it. Its exact
complement, `findRowsTheRemoteNeverGave()`, is what unsubscribing asks — everything with a
`remoteId` is a copy of something the provider still holds, while everything without one
exists only here, and deleting that second set would destroy the only copy of a dinner
reservation because somebody unticked a calendar.

Failure is recorded on the calendar either way — `lastSyncedAt` on success, `lastSyncError`
on failure — and the recording is flushed **separately** from the events, because the
interesting case is the one where the sync threw: Doctrine has usually closed the manager by
then, and an error nobody could write down is an error nobody sees. If the manager is already
closed, that is logged rather than papered over with a second manager.

## The driver contract

`App\Domain\Interface\CalendarSyncDriverInterface` has five methods — `supports`, `discover`,
`pull`, `push`, `delete` — and a docblock longer than most of its implementations. The
property being protected is that the engine knows nothing about HTTP, OAuth, CalDAV or any
provider's resource shapes. Six documented rules hold it up.

**Every id is opaque.** A `RemoteCalendar::$remoteId`, a `RemoteEvent::$etag`, a
`CalendarChangeSet::$nextSyncToken` — whatever a driver puts in one comes back byte for byte.
Nothing outside the driver parses, orders, truncates or compares one for anything but
equality. A Google resource id, a Graph delta link and a CalDAV href are all just strings.

**JSCalendar is the only event vocabulary.** A driver maps its provider's representation to
and from RFC 8984 at its own boundary. Nothing above the line has ever seen a VEVENT or a
Graph `event` resource. The alternative — a lowest-common-denominator struct of
title/start/end — throws away participants, alerts, links and `recurrenceOverrides` on the
way in, silently.

**Times crossing the boundary are UTC `DateTimeImmutable`.** The JSCalendar object keeps its
own LocalDateTime-plus-zone as the spec requires; `RemoteEvent::$startsAt` and `$endsAt` are
instants, because that is what the local columns and every range query are. They sit beside
the object although both are derivable from it, because the driver has already done the
parse to read the provider's response and making the engine repeat it is two implementations
of one conversion.

**Every failure is a `CalendarSyncException` or a subclass.** Transport exceptions, JSON
errors, non-2xx statuses and XML parse failures are all translated at the driver boundary, so
callers never see an HTTP concern and never have to guess whether a null meant "empty" or
"broken". The hierarchy is shaped by what the caller should do:

```
CalendarSyncException              — unclassified; the transport's retry strategy decides
  ├── CalendarSyncPermanentException  — stop, this will never work
  ├── CalendarSyncThrottledException  — back off and retry
  └── CalendarResyncRequiredException — the token is dead, read from scratch
```

Choosing the subclass is the driver's most consequential decision, and the instruction when
the body does not say clearly enough is to raise the unclassified base rather than guess
"permanent". The message lands in `Calendar::$lastSyncError` and is rendered in the settings
list, so it is phrased for a person and must never carry a credential or a full request URL.

**Nothing in a driver flushes, persists or touches Doctrine.** It reads the two entities it
is handed and returns DTOs. `Calendar::$syncToken`, `CalendarEvent::$remoteId` and every
other column are the engine's to write, which is what makes "the token is stored only after
the whole window applied" a rule one class can keep.

**A recurring series is one event, and a changed instance is an override of it.** A driver
never emits an instance as an event of its own, because a second row is a duplicate on the
day it moved to beside a series that still draws it where it was. There are two ways to say
it, and which one a driver uses is decided by its provider rather than by taste:

- A provider whose resource is **atomic** — CalDAV, where every component sharing a UID
  arrives in one `.ics` — puts the whole `recurrenceOverrides` map inside the master's own
  JSCalendar object. That map is complete by construction, so the engine *replaces* what it
  holds, and an instance moved back is a map the resource no longer mentions.
- A provider whose instances are **separate resources** — Google's `recurringEventId`,
  Graph's `type: exception` — returns a `RemoteEvent` carrying `$seriesRemoteId` and
  `$recurrenceId`, and the engine files it onto the master's map without touching the rest,
  because a delta window is only ever a statement about the instances it names. An instance
  that is off is `RemoteEvent::deletedInstance()`, never `deleted()`: the series is alive, and
  a tombstone against a row that does not exist does nothing at all. **`$recurrenceId` is the
  instance's ORIGINAL start, not the moved one** — it is the only name an instance keeps once
  it has been dragged.

An override whose series the engine holds no row for is logged and dropped. Creating a master
from an instance would invent a series with one occurrence and the wrong rule.

A driver is also entitled to three assumptions, stated so no implementation defends against
them twice: it is never asked to push to a read-only calendar, never handed a `Calendar`
whose source it did not claim in `supports()`, and never called concurrently for the same
calendar — the sweep dispatches one message per calendar and the handler is the only caller.

### `pull()` in particular

A null token means "everything", and the driver must return every event that currently
exists. It must **not** return tombstones for a full read: there is nothing to tombstone
against, and the engine treats a full read as authoritative. The one exception is a cancelled
*instance*, which is deliberately returned by a full read as well — it is not a tombstone
against a row, it is a fact about a series that is still there, and a full read that dropped
it would resurrect every instance the user had cancelled.

`$syncToken` is passed explicitly rather than read off `$calendar`, so the engine can re-pull
with null after a resync without first writing the cleared token to the database. The two
disagree exactly once, on that second call, and that is the design.

A dead token is reported by returning `CalendarChangeSet::resyncRequired()` rather than by
throwing, because token expiry is a *normal* outcome of polling a calendar nobody touched for
a week. Throwing `CalendarResyncRequiredException` is permitted and handled identically, for
the case where the discovery happens too deep to return from; `CalendarSyncService::pullOnce()`
normalises the two so the loop has one thing to look at.

`CalendarChangeSet::$instances` is the odd field: a list of `RemoteInstance`, saying which
occurrence each of the provider's instance ids stands for — **including the ones nothing
happened to**. It is not a change and is not applied as one. It is kept out of `$events`
deliberately, because a driver listing fifty-two unchanged occurrences a year as events would
make the engine decide which of its own changes said nothing.

## The four drivers

| Driver | Reached via | Change detection | Instances | Writes |
|---|---|---|---|---|
| `Sync\Google\GoogleCalendarSyncDriver` | the mail account's OAuth grant | `syncToken` on `events.list` | separate resources under the series | yes, where `accessRole` allows |
| `Sync\Graph\GraphCalendarSyncDriver` | the mail account's OAuth grant | `calendarView/delta` over a bounded window | separate resources; `@removed` carries an id only | yes |
| `Sync\CalDav\CalDavCalendarDriver` | an `Integration` | sync-collection (RFC 6578), else getctag + calendar-query | inside the master's `.ics` | where privileges allow |
| `Sync\IcsUrl\IcsUrlCalendarDriver` | an `Integration` | HTTP ETag / Last-Modified | inside the file | never |

`App\Domain\DTO\Calendar\CalendarSource` is what `supports()` and `discover()` take, because
discovery happens before any `Calendar` row exists — the user has just connected something
and the question is "what calendars are there?". It carries either an `Account` or an
`Integration` and exactly one, structurally, through two named constructors. It is a union of
two nullable fields rather than an interface over the pair, because `Account` and
`Integration` have nothing else in common and are not going to grow it: an interface with no
methods is a comment pretending to be a type.

### Google

Rides the mail account's own OAuth grant — `MailProvider::Google::scopes()` already asks for
`https://www.googleapis.com/auth/calendar` — and holds no credentials of its own. The
consequence is not theoretical: Google's consent screen lets a user untick an individual
scope, so a perfectly working mail account can hold a token every calendar endpoint refuses.
That is discovered on the first call and reported as a permanent failure whose message names
the fix.

**Recurring events are pulled as series.** `singleEvents` stays false, so a weekly standup
arrives once carrying its RRULE and the local materialiser expands it. Asking Google to
expand instead would turn one row into hundreds and make the sync token's window meaningless.

`INITIAL_WINDOW` is `-1 year`: a calendar in use for a decade holds tens of thousands of
events, and an unbounded first read fetches all of them. The forward direction is deliberately
unbounded. The cost is honest — an event older than the window is never learned about, and a
full resync re-establishes the same window rather than a wider one — but a series that
started earlier still arrives, because Google matches a series on its instances.
`PAGE_SIZE` is Google's own default of 250; `WRITABLE_ACCESS_ROLES` is an allow-list of
`owner` and `writer`, stated that way so a role Google adds later is treated as unwritable
until somebody decides otherwise.

Paging is followed to the end every time, because `nextSyncToken` only arrives on the final
page — returning a page cursor as if it were a sync position is how a delta feed silently
stops halfway and never catches up.

### Microsoft Graph

Graph offers delta on exactly one calendar surface: `calendarView`. There is no
`events/delta`. So the choice is between a delta over a bounded window and re-listing
`/me/calendars/{id}/events` in full every fifteen minutes forever, with no way to learn about
a deletion except by comparing the whole set. The window wins, and it is
`RecurrenceMaterialiser::HORIZON_PAST` and `HORIZON_FUTURE` **read from the constants** rather
than copied — the two have to agree, and the way to make them agree is to have one of them.

**The cost is that `calendarView` expands recurrences and this driver has to undo it.** A
weekly meeting arrives as fifty-odd entries with `type: occurrence` and a `seriesMasterId`.
Letting the expansion through would put fifty rows in `calendar_event`, fifty UIDs no other
client shares, and fifty pushes back at Graph the first time somebody edited "the meeting".
So an occurrence is not an event here — it is a *mention of a series*, and the master is
fetched once per series and emitted once. An `exception` entry is a mention *and* a fact about
one instance, so it becomes an override keyed on `originalStart`.

**A cancelled instance is recognised only by what an earlier window wrote down.** Graph
reports it as `@removed` with an id and nothing else — no series, no start — and the resource
is gone, so it cannot be asked. Hence every occurrence and exception a window mentions is
also reported as a `RemoteInstance`, and the engine keeps them (see below).

Two deliberate absences: **no `$select`**, because a `$select` on a delta call sticks for the
whole chain and one property Graph refuses to project takes the entire request with it —
exactly how `meetingMessageType` once stopped Outlook mailboxes syncing at all. And **no
`Prefer: IdType="ImmutableId"`**, because an event id changes when the event moves between
calendars, and this driver syncs one calendar at a time, so a departure is a deletion here and
a creation over there whichever id scheme is in force.

### CalDAV

The one driver that talks to software nobody here chose: Nextcloud, Radicale, Baïkal,
Fastmail, iCloud, a Synology box in a cupboard. Every capability is **asked for** rather than
assumed — writability from `current-user-privilege-set`, incremental reads from
`supported-report-set` — and there is no per-vendor branch anywhere in the file. A server
plMail has never heard of works the day it is pointed at.

Two ways to read changes:

- **sync-collection (RFC 6578)** where advertised. One REPORT carrying the stored token
  answers with the changed events and a `<status>404</status>` per removal, which is exactly a
  `CalendarChangeSet`. It is the only mechanism here that can express a deletion
  incrementally.
- **getctag plus calendar-query** where not, which is not a rare fallback — Radicale, older
  Baïkal and several appliance servers advertise no sync-collection at all. The ctag is one
  value for the whole collection: equal means nothing changed anywhere, which is the answer to
  most polls and costs one PROPFIND.

When the ctag *has* moved, the driver asks for a **full resync rather than returning the
listing**. That looks wasteful and is deliberate: a calendar-query answers with everything
that currently exists and says nothing about deletions, and the engine only treats a listing
as authoritative when it asked with a null token. Returning the listing against a live token
would apply every edit and keep every deleted event forever.

Both mechanisms store their position in the same `Calendar::$syncToken` and the two spellings
cannot be told apart — which is safe in both directions and needed no flag. A ctag presented
to a server that has since grown sync-collection support comes back as the `valid-sync-token`
precondition and becomes a resync; a sync-token compared against a ctag simply never matches,
which is also a resync. Both self-heal in one poll.

Every `remoteId` here is an **absolute URL**, calendars and events alike: an href is
meaningful only against the server that issued it, RFC 6764 bootstrapping routinely lands on
a different host than the one the user typed, and a connection's base address can be edited
afterwards.

### ICS feeds

The fourth driver, and the only one whose remote cannot answer a question. There is no delta
feed, no change token, no per-event resource id, nobody to ask for permission.

- **Identity is the UID.** A `RemoteEvent::$remoteId` here *is* the UID, which is legitimate
  because ids are opaque and only compared for equality — and it has a real payoff: the
  meeting an invitation already put on another calendar is recognised by `CalendarPuller`'s
  fallback lookup.
- **Change detection is HTTP's.** ETag and Last-Modified are packed into `Calendar::$syncToken`
  separated by `TOKEN_SEPARATOR`, which is `\x1f` — a unit separator rather than a pipe,
  because an ETag is an opaque quoted string that may legally contain any printable character,
  and RFC 9110 §5.5 forbids control characters in field values. An unchanged calendar is a 304
  with no body.
- **A change surrenders the token**, exactly as the CalDAV ctag fallback does and for exactly
  the same reason. Two downloads per actual change, none in between, in exchange for a
  calendar that does not accumulate ghosts.
- **Read-only is a fact, not a setting.** `isReadOnly` is hard-coded true on the discovered
  calendar, and `push()` and `delete()` throw rather than doing nothing quietly: the engine
  promises never to call them, so reaching either is a bug, and silence there would present as
  edits that vanish on the next sweep with no trace.

`REMOTE_ID` is the literal `'feed'` rather than the URL, twice over: `Calendar::$remoteId` is
255 characters and a signed feed address exceeds that routinely, and an address the user
later corrects would orphan the calendar mirroring it. The address lives on the
`Integration`. See [ICS feeds](../providers/ics-feeds.md).

## Instance identity, and the bare tombstone

`CalendarEvent::$remoteInstances` is a jsonb map from the provider's opaque instance id to
that occurrence's **original start**, as a UTC ISO 8601 instant with the `Z` on it —
`CalendarEvent::INSTANCE_START_FORMAT`, named once because it is a contract between the
puller that writes it and the drivers that read it. The `Z` is not decoration: a value written
without one is read back in whatever zone the reader holds, which for a Berlin calendar is an
instance two hours off.

It exists because Microsoft's tombstone carries an id and nothing else. Applied as it stands
it matches no row — an instance has never been one — so the deletion does nothing, and the
occurrence the user removed in Outlook is drawn forever. `CalendarPuller::recognisedInstance()`
asks the map first (via `CalendarEventRepository::findOneByRemoteInstanceId()`, a `jsonb_exists`
lookup backed by a GIN index) and turns the tombstone back into `RemoteEvent::deletedInstance()`.

Three design notes on the column:

- **Keyed by the id, not by the start**, although a push reads it in the other direction. The
  one question that cannot be answered in PHP is "whose instance is this id?" — asked against
  the whole table, from a tombstone naming nothing else — and a jsonb key is what an index can
  answer. The reverse lookup a push wants is a scan of one series' own map, already in memory.
- **A column rather than a table**, and the trade is stated: a row per instance would index
  better, but every read is a read of one event's own map by something already holding the
  event, and the entries have no life of their own.
- **Pruned to the occurrence horizon.** An id for an instance no view can show answers no
  question. `rememberInstances()` also replaces an id whose original start another id already
  claims, because Microsoft re-keys an occurrence for some edits and a push addressing the
  dead id would patch a resource that is not there.

## Applying a window

`CalendarPuller::apply()` runs in two passes and the split is what makes an override that
arrives *before* its master land:

1. Every non-instance event: identity is `remoteId` first, then `uid` within the calendar. The
   uid fallback is not a nicety — an invitation arriving by mail creates an event with the
   organiser's UID, and the same meeting on the connected calendar carries that UID with a
   remote id plMail has never seen. Without it, accepting an invite puts the meeting on the
   calendar twice.
2. Every instance, grouped by series, one write per series rather than one per instance. The
   rows the first pass produced are consulted **before** the repository, and that is not an
   optimisation: nothing here flushes, so a series created a moment ago is in the unit of work
   and not in the database, and `findOneByRemoteId()` would answer null and drop every
   override that arrived in the same window as its series.

Then the prune, if this was a full read, and only then the token —
`Calendar::$syncToken` is written **last**, after every event in the window has been applied.
A crash halfway through re-reads the same window on the next run, and every operation here is
idempotent, so that costs a repeat; storing the token first would step over whatever had not
been applied and never look at it again.

An instance's own remote id is deliberately **not** in the `$seen` list the prune uses. It
never named a local row, so listing it would protect nothing — and leaving it out is what
lets a full read clear away the duplicate rows that moved instances used to create.

A patch built from an instance renders `start` in the **series'** zone rather than the
instance's, because RFC 8984 §4.3.3 expands a rule in the event's own timeZone. An instance
claiming a different zone — Graph will, for an occurrence moved while travelling — would
otherwise land at the right wall-clock time in the wrong place.

## Marking and pushing a local edit

Nothing infers that a row changed. `App\Domain\Enum\Calendar\SyncState` records the intent at
the moment of the edit, and the alternative — a shadow copy of what was last pushed — doubles
storage for every event and still answers wrongly whenever the comparison and the serialiser
disagree about key order or a null.

| Case | Meaning |
|---|---|
| `Clean` | in step with the remote, or belonging to no remote |
| `PendingCreate` | made here and never sent; has no `remoteId` yet |
| `PendingUpdate` | changed here since the last successful push |
| `PendingDelete` | deleted here; the row waits for the remote to be told |

The cost is that a write which forgets to mark is a change that never leaves — which is why
marking lives on `CalendarEventWriter`, the one class every local write already goes through:

- `markLocallyChanged()` applies `SyncState::afterLocalEdit()`. It is called by whatever made
  the change and **never by `write()` itself**, because `write()` is also how the sync engine
  applies what it just *read*, and marking there would make every pull queue a push of the
  remote's own data straight back at it. It is a no-op on a calendar that mirrors nothing, so
  callers need not carry their own copy of the "is this synced?" question.
- `markLocallyCreated()` is separate because `afterLocalEdit()` cannot tell a brand-new event
  from a clean one, and guessing from a null `remoteId` is wrong for the one case that
  matters — an event whose create is still pending is also `remoteId`-less.
- `markLocallyDeleted()` sets `PendingDelete` and **clears the occurrences immediately**.
  Every view reads occurrences and none reads events, so the deletion looks instant without any
  view learning that `PendingDelete` exists. It returns whether the caller still has to remove
  the entity: false means the row is now the pusher's problem.

`afterLocalEdit()` is an exhaustive match with two deliberate fixed points: a `PendingCreate`
stays a create however many times it is edited, because promoting it would send an update for
a resource the remote has never heard of; and a `PendingDelete` is not undone by an edit.

`SyncState::pendingCases()` is derived from `isPending()` rather than written out, so a fifth
case cannot be added and silently left out of the query that finds work — which would present
exactly as "some edits never sync".

`CalendarPusher` sweeps `findPendingSync()`, **ordered by id** so pushes go out in the order
the edits were made. That matters for the one sequence that is not commutative: an event
created and then deleted before either left produces a `PendingCreate` row that is already
gone, and any other ordering would push the delete for a resource the remote has not been
told about.

Create and update are one operation, and which it is comes off `$remoteId` rather than a
flag: the id is the fact, and a flag beside it is a second copy that can disagree. Both
`remoteId` and `etag` are stored back even on an update, because a provider that re-keys an
event when it is edited would otherwise leave the local row pointing at an id that no longer
resolves — and the next pull would treat the same meeting as a stranger and write a second
copy.

Failures are **per event**, and only `CalendarSyncPermanentException` is swallowed:
`abandon()` logs the whole JSCalendar object at error level and leaves the row `Clean`. An
event the remote has refused permanently would otherwise be re-offered on every sweep
forever, and a queue that retries something known to be impossible eventually retries nothing
else. Throttling and resync are allowed through, because both mean the *connection* is
unusable rather than this row.

### Pushing instances

A series is pushed with its instances, and the two halves travel differently by provider —
the same split the pull makes. An atomic driver writes the overrides inside the resource. A
driver whose instances are separate resources must, after the master's own write, find the
provider's instance for each override's **original** start and write it there: moved, renamed
or lengthened by a patch, cancelled by an `{"excluded": true}`.
`App\Domain\DTO\Calendar\InstanceOverride::listOf()` resolves the stored map into the instants
both need.

**Failing to place one instance must not fail the push.** The master has already been written
by then, and a driver that threw would leave the engine unable to record the id it just came
back with — which on a create is a second copy of the meeting on the next sweep.

A driver may read `CalendarEvent::$remoteInstances` to address an instance directly and must
not write it: like every other column it is the engine's.

## Push channels versus polling

**Push is never load-bearing.** A self-hosted install may have no publicly reachable HTTPS
address at all, so a failed registration means "stay on polling", not "error". The
fifteen-minute sweep runs regardless and is unchanged by any of this.

| | Google Calendar | Microsoft Graph |
|---|---|---|
| Mechanism | `events.watch` channel, a plain webhook | `/subscriptions` over `me/calendars/{id}/events` |
| Endpoint | `POST /webhook/google/calendar` | `POST /webhook/graph/calendar` |
| Proof | channel token in `X-Goog-Channel-Token` | `clientState` in the body |
| Lifetime | a week, whatever Google grants | just under three days |
| Renewal | re-register, then stop the old channel | `PATCH` the expiry |
| Teardown | `channels/stop` with (id, resourceId) | `DELETE /subscriptions/{id}` |

`App\Service\Calendar\Push\CalendarPushRegistry` resolves the manager and answers **null**
rather than throwing for a calendar nobody claims — a CalDAV calendar has no push and neither
does a hand-made local one, so every caller skips quietly. The managers live under
`Service/Calendar/Push/` rather than beside each provider's sync driver, deliberately:
`Sync/Google/` means "the sync driver and the pieces it is assembled from", and a reader
opening it to understand a pull should not meet channel registration on the way.

The four registration columns on `Calendar` — `pushChannelId`, `pushResourceId`,
`pushSecret`, `pushExpiresAt` — are columns rather than keys in the `settings` jsonb bag, and
that is the one decision there worth arguing. This is what two unauthenticated,
internet-facing webhooks look a notification up by and verify it against; it needs a unique
index (`uniq_calendar_push_channel_id`, unique so a notification cannot be ambiguous, with
Postgres allowing any number of NULLs for the column's ordinary state) and a constant-time
comparison against a known column. `pushResourceId` is stored because a Google channel is
stopped by POSTing the pair `(id, resourceId)` and the resourceId is only ever seen in the
answer to the watch call — not keeping it means never being able to stop the channel.
`clearPushChannel()` clears all four at once, because a teardown that cleared the id and left
the secret would leave a calendar that verifies notifications for a channel it can no longer
stop.

`pushExpiresAt` is what the **provider** granted, not what plMail asked for. Google is free to
grant less than the requested ttl, and renewing off a local constant instead is how a channel
silently dies a day before anything tries to replace it.

`App\Service\Calendar\Push\PushCallbackUrl` builds the callback from the configured public
base URL rather than from the incoming request — reverse proxies are the normal deployment, so
a request-derived URL carries an internal hostname or `http://` after TLS termination, and
there is no request at all in the scheduled command that actually registers these. It is
resolved per call, never injected as a string, because the workers are long-running and the
public address is typically saved from the setup screen after they booted. It restates
`GraphSubscriptionManager`'s routability rule (HTTPS, not a loopback name) rather than calling
into it, and the docblock says so: the two are free to diverge, what must not diverge is the
answer.

**Nothing registers a channel except `app:calendar:push`.** Ticking a calendar to mirror it
dispatches `RegisterCalendarPushMessage`, but the hourly sweep is the retry rather than the
only way in — registration fails for deployment reasons that have nothing to do with the
click, and tied to the subscribe flow those calendars would never get push until somebody
re-subscribed them.

A notification carries **nothing** about what changed in either mechanism, so each webhook
does exactly one thing — dispatch `SyncCalendarMessage` for the calendar it names — and every
decision stays in the engine. Google's first notification after registering is a
`X-Goog-Resource-State: sync` handshake meaning only "the channel is open"; acting on it would
put a full calendar read in the queue for every registration and every hourly renewal across
the install. See [Google](../providers/google.md) for domain verification, which Google
requires before it will deliver at all and Microsoft has no equivalent of.

## Things that bite

**A driver that returns tombstones for a full read deletes things twice, and a driver that
omits a cancelled instance resurrects it.** The two rules look symmetric and are not: a
tombstone against a row is nothing on a full read, while an excluded instance is a fact about
a series that is still there.

**Returning a page cursor as `nextSyncToken` silently truncates the feed.** The engine stores
whatever it is given verbatim, so the failure is a calendar that stops updating with no error
anywhere.

**A `supports()` written too broadly steals another driver's calendars.** The registry takes
the first driver that says yes, and `supports()` must not perform I/O — it is called once per
registered driver on every sync of every calendar.

**Clearing `Calendar::$syncToken` by hand is a full read, and a full read prunes.** Rows with
a `remoteId` the listing does not mention are removed. For Graph in particular, the window is
bounded, so an event aged out of `HORIZON_PAST` is absent from the listing and its row goes —
which is the wanted answer, since its occurrences would have been dropped by the materialiser
on the same run, but it is not obvious from the outside.

**`markLocallyChanged()` inside `write()` would be an infinite loop of one.** Every pull would
queue a push of the remote's own data back at it. The marking lives with the caller for that
reason alone.

**A per-instance edit on Google or Graph reaches the remote only if the driver's `push()`
places it.** Skipping instance placement does not lose the change locally — it means the
change is visible in plMail alone until a full read carrying any exception for that series
replaces the whole map and takes the local patch with it.

**Anything that widens `MAX_RESYNCS` re-opens the loop it closed.** One is the number; a
driver that answers `requiresFullResync` to a null token has a bug, and the permanent
exception exists to name it rather than to hammer a provider until the quota runs out.
