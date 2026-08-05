# JMAP

What is implemented, what is deliberately not, the id spaces and why each one is what it is,
how state tokens work and why calendars cannot calculate changes, and push subscriptions.
`CLIENT_DEVELOPMENT.md` in `docs/` is the protocol-level reference for someone writing a
client; this page is why the server is shaped this way. For connecting a client, see
[Other clients](../features/clients.md).

## Surface

The server lives entirely under `src/Jmap/`, with its own `Method/`, `Mapper/`, `Protocol/`,
`Query/`, `State/`, `Push/` and `Session/`. Endpoints:

| Route | Purpose |
|---|---|
| `/jmap/session` and `/.well-known/jmap` | the Session object (RFC 8620 §2) |
| `/jmap/api` | the method call endpoint |
| `/jmap/upload/{accountId}` | blob upload |
| `/jmap/download/{accountId}/{blobId}/{name}` | blob download |
| `/jmap/eventsource` | EventSource push |

Authentication is app passwords on a stateless firewall — see the
[Security model](security-model.md).

### Methods implemented

**Core** — `Core/echo`, `PushSubscription/get`, `PushSubscription/set`.

**Mail** — `Mailbox/get`, `Mailbox/query`, `Mailbox/changes`, `Mailbox/set`; `Email/get`,
`Email/query`, `Email/changes`, `Email/set`; `Thread/get`, `Thread/changes`, `Thread/set`;
`EmailSubmission/get`, `EmailSubmission/changes`, `EmailSubmission/set`; `Identity/get`,
`Identity/set`; `SearchSnippet/get`.

**Calendars** — `Calendar/get`, `CalendarEvent/get`, `CalendarEvent/query`,
`CalendarEvent/set`.

`App\Jmap\Method\MethodRegistry` indexes every class tagged `app.jmap_method` by its `name()`,
so adding a method is a class implementing `App\Jmap\Method\JmapMethod` and nothing else.
`App\Jmap\Protocol\JmapProcessor` runs the calls in order, resolving back-references through
`ReferenceResolver`, and a failing call yields an inline error without aborting the rest.

### What is deliberately not implemented

- **`Calendar/set`.** The Session advertises `mayCreateCalendar: false`. The two provisioned
  roles are created by `CalendarProvisioner` and a subscribed one by the subscribe flow,
  neither of which a JMAP create could stand in for.
- **`Calendar/changes` and `CalendarEvent/changes`.** Not an omission — see the state section
  below.
- **`VacationResponse`, `EmailSubmission` delayed send, and `urn:ietf:params:jmap:calendars`.**
  The submission capability advertises `maxDelayedSend: 0` because sending is queued on the
  Messenger bus with no scheduling support, so there is no future-send window to claim.
- **Participants, privacy, alerts and links in `CalendarEvent/set`.** Each is listed with its
  reason; see below.

## Capabilities

`App\Jmap\Protocol\Capability` holds the URNs:

| Constant | URN | Advertised in `using` |
|---|---|---|
| `CORE` | `urn:ietf:params:jmap:core` | yes |
| `MAIL` | `urn:ietf:params:jmap:mail` | yes |
| `SUBMISSION` | `urn:ietf:params:jmap:submission` | yes |
| `CALENDARS` | `urn:plmail:params:jmap:calendars` | yes |
| `PUSH` | `urn:plmail:params:jmap:push` | no — Session-only |

Two of those are vendor URNs, and both are vendor URNs on purpose.

**`CALENDARS` is deliberately not `urn:ietf:params:jmap:calendars`.** JMAP for Calendars is an
unratified draft whose object shape is still moving — properties have been renamed and
re-scoped between revisions — so advertising the IETF URN would promise a contract no client
could rely on, and a client that believed it would break on the revision after the one this
was written against. A vendor URN says what is true: this is plMail's calendar surface, and
only something written for plMail should use it. Switching when the draft is ratified is then
an *addition* rather than a breaking change, since both can be advertised while clients move
across.

**`PUSH` carries the VAPID public key**, because RFC 8620 defines no standard place for it and
a client cannot call `pushManager.subscribe()` without one. An empty key is a client's signal
not to offer push at all.

`Capability::SUPPORTED` is what a client may declare in `using`; anything else is an
`UnknownCapabilityException`. `PUSH` is not in that list because it is a Session-level fact
rather than something a request can be made under.

### Session

`App\Jmap\Session\SessionBuilder` exposes **one JMAP account per connected mail account**, so
a single login enumerates all of the user's mail and a unified inbox is a client-side concern
— one `Email/query` per account, merged in the client.

Core limits: `maxSizeUpload` 50 MB, `maxConcurrentUpload` 4, `maxSizeRequestObject` 10 MB,
`maxConcurrentRequests` 4, `maxCallsInRequest` 32, `maxObjectsInGet` 500, `maxObjectsInSet`
500.

The calendars capability states three account-level facts and each is there because a client
would otherwise discover it the hard way:

- **`maxEventsInGet: 100`**, lower than the global 500, because `CalendarEvent/get` resolves
  one id at a time — the ownership-scoped lookup is the only one `CalendarEventRepository`
  offers, and a client obeying 500 would meet a `requestTooLarge` it was told not to expect.
- **`mayCreateCalendar: false`**, matching the absent `Calendar/set`.
- **`materialisedHorizon`**, read straight from `RecurrenceMaterialiser::HORIZON_PAST` and
  `HORIZON_FUTURE`. Occurrences exist only within it, so a query outside answers from a
  partial index — stated rather than left for a client to discover as a recurring meeting that
  stops.

`SessionBuilder` is one of only three places coupled to the mail-account entity shape, and its
docblock names the three calls it makes so a rename has one place to look. The others are
`App\Jmap\Account\AccountResolver` and `CalendarAccountResolver`.

## Id spaces

Every id JMAP hands out is a server-defined string a client must not parse, and every one of
them in plMail is a decision about *which table's autoincrement it is*. That matters more here
than it sounds: ids from different tables are all plain integers, so an untranslated id does
not fail — it names a real, wrong object, which a client fetches and renders.

| JMAP object | plMail id | Why |
|---|---|---|
| Account | `Account` row id | a JMAP account is a connected mail account |
| Mailbox | **`LabelBinding`** row id | a `Label` is user-scoped; a JMAP Mailbox is per account |
| Email | `Message` row id | |
| Thread | `MessageThread` row id | |
| EmailSubmission | the **Email** id | a submission has no table of its own |
| Calendar | `Calendar` row id | one account serves calendars, so there is nothing to translate |
| CalendarEvent | **`CalendarEvent`** row id — the series | see below |
| blobId | `m-<id>` / `p-<id>` / `u-<id>` | two independent sources of bytes, plus staged uploads |

### Mailbox is a binding, not a label

`App\Entity\Label\Label` belongs to the user; `App\Entity\Label\LabelBinding` is where that
label is materialised on one account. A JMAP account is one mail account, so the per-account
row is the thing with a stable id there.

`App\Jmap\Mapper\EmailMapper` therefore translates on the way out. `mailboxIds` comes from
`Message::$labels` — the authoritative per-message assignment — never from `thread_label`,
which is the derived union `ThreadLabelSynchronizer` recomputes and would report a mailbox for
every message in a thread. Those rows hold **label** ids and the wire needs **binding** ids,
which is also what `inMailbox` and `Email/set`'s `mailboxIds` patch consume. Emitting the
untranslated id does not fail loudly: it names some unrelated mailbox that happens to share
the number, which is exactly what shipped once and what `EmailMapperTest` now pins. A label
with no binding on the account is omitted rather than emitted as an id the client cannot
resolve.

`EmailMapper` also publishes two synthetic body parts with the fixed `partId`s `"text"` and
`"html"`, because plMail stores a flattened body (`bodyText` / `bodyHtmlSafe`) rather than a
MIME part tree. Clients treat `partId` as opaque and these are stable per message, which is
all `fetchTextBodyValues` needs.

### A CalendarEvent id is the series

This is the id-space decision the calendar surface turns on, and it is not the obvious one.
The query that finds events runs over `calendar_event_occurrence`, so the ids the database
hands back are **occurrence** ids and something has to translate them.

**The series is the right unit** because it is what a JSCalendar Event *is* (RFC 8984): one
object carrying `recurrenceRules` and `recurrenceOverrides`, from which a client expands
instances itself. An id per occurrence would name rows this application creates and destroys
on every write — the materialiser rewrites them wholesale — so a client's stored id would go
stale the moment somebody corrected a title.

The translation happens in exactly one place, `App\Jmap\Query\CalendarEventQueryRunner`, and
`App\Jmap\Mapper\CalendarEventMapper` carries the argument. The test that guards it puts every
emitted id back into a filter and checks it selects what it came from — one id space
everywhere: `list[].id`, `/query`'s ids, `/get`'s ids, `/set`'s ids.

The published object **is** the stored canonical JSCalendar object plus the envelope JMAP adds
(`id`, `calendarId`, `uid`, `sequence`, `created`, `updated`, `isRecurring`). Nothing is
re-derived from the projected columns: `CalendarEventWriter` is the one place columns become
JSCalendar, and a second derivation in the mapper would be a second answer to what the event
is. An event whose `jscalendar` is empty is therefore published nearly empty, which is honest
— no writer made that row. `isRecurring` is published because a client cannot see it from the
rule alone: a rule this server could not convert is stored verbatim and expands to a single
occurrence, so `recurrenceRules` being present is not the same claim as "this recurs here".

`CalendarMapper` publishes writability as `myRights` rather than an `isReadOnly` flag,
following RFC 8621's Mailbox — two spellings of "may I write here?" is how one of them ends up
not being consulted. `isVisible` is published rather than acted on: it is the web sidebar's
tick, and a JMAP client filtering on it would hide from a phone what its user had chosen to
hide in a browser.

### Calendars are served from exactly one account

A `Calendar` is the **user's** — user-scoped like `Label` and `MailRule`, with a mail account
only ever an optional owner for the one calendar extraction files into. There is no
per-account identity for a calendar the way `LabelBinding` gives a label one.

Serving the list from every account would publish one calendar under three accountIds. A
client keys every object by `(accountId, id)`, so it would draw the calendar three times, and
an event created on it would appear to exist three times over — with no way for the client to
tell the three are one.

So `App\Jmap\Account\CalendarAccountResolver` names exactly one: the account the Session
already lists in `primaryAccounts`, which is the user's first. Any other is refused with
`accountNotSupportedByMethod`, RFC 8620's error for precisely this. It resolves through
`AccountResolver` first, so an unknown or foreign accountId is still `accountNotFound` —
telling a stranger that an id they do not own is merely *unsupported here* would confirm the
id exists.

A user with no mail account has nowhere to serve calendars from and the Session advertises
none. That is a real state — a user can delete their last account and keep a calendar — and it
degrades to "this install has no calendar account" rather than to an error at some other
account's expense.

### Blob ids are namespaced

plMail has two independent sources of downloadable bytes — a whole `Message` (its RFC822
source) and a single `MessagePart` (an attachment) — in different tables with independent
autoincrement ids, plus staged uploads. Emitting a bare id makes blob `239049` ambiguous and
the download endpoint cannot resolve it. `App\Jmap\Blob\BlobId` prefixes `m-`, `p-` or `u-`,
which stays opaque to clients — RFC 8620 §1.6.3 requires that anyway — and `parse()` returns
null for anything malformed so callers answer `notFound` rather than trusting input.

## State

`App\Jmap\State\ChangeLog` is an append-only log and its autoincrement primary key **is** the
state token. A client's state for an `(accountId, objectType)` pair is the highest sequence
recorded for it; `/changes` returns rows with `sequence > sinceState`, capped at
`StateManager::DEFAULT_MAX_CHANGES` (256, kept modest for mobile) with `hasMoreChanges` set
when there are more.

`StateManager::changesSince()` refuses a null `sinceState` with `invalidArguments` and refuses
a non-numeric one with **`cannotCalculateChanges`**, which is the correct degradation: a
client holding a token this server can no longer interpret is told to resync rather than
handed a wrong answer.

Everything that writes mail change rows goes through `App\Service\Mail\MailChangeRecorder`;
see [Mail ingest](mail-ingest.md) for why that layer exists on top of `StateManager` and why
`record()` deliberately does not flush.

### Why calendars cannot calculate changes

`App\Jmap\Calendar\CalendarState::FIXED` is the literal string `'fixed'`, returned by every
calendar method, and the class docblock is the argument in full.

Mail's token is trustworthy **because the log is complete**: every path that changes a
JMAP-visible mail property calls `StateManager::record*`, through `MailChangeRecorder`, which
exists exactly so five callers cannot each forget the same two things.

Calendars have no such recorder and could not be given one from inside `src/Jmap/`. An event
changes from four places — the sync engine pulling a remote calendar, extraction reading a
message, the web editor, and `CalendarEvent/set` — and only the last is in this directory.
**A log that recorded a quarter of the writes would be worse than none**: the token would sit
still while a pull replaced the whole day, and a client comparing states would conclude
nothing had changed and never refetch. A partial log is not a weaker version of a complete
one, it is a lie with a number on it.

So the state is fixed and the methods say so in the only other way the protocol offers:
`canCalculateChanges` is false, there is no `Calendar/changes` or `CalendarEvent/changes`, and
a client re-runs its query — which is what `Email/query` already asks for and is spec-legal.

The value is deliberately **not a number**. Should calendars later join the change log — a
`CalendarChangeRecorder` beside `MailChangeRecorder`, called by all four writers, and
`JmapObjectType` cases to match — tokens become sequences, and a client still holding
`'fixed'` fails the `ctype_digit` check in `changesSince()` and is told to resync. That is the
correct degradation, and it is free.

## Queries

`Email/query` compiles filters through `App\Jmap\Query\EmailFilterCompiler`, which **refuses an
unknown condition by name rather than ignoring it**. A filter quietly dropped returns too
much, and the client has no way to tell.

`CalendarEvent/query` runs `CalendarEventOccurrenceRepository::findInRange()` — the same
`tsrange &&` overlap against the GiST index every calendar view makes, rather than a second
query written for JMAP. Two deliberate refusals:

**The window is required.** An unbounded query cannot be answered from that index at all:
occurrences are materialised only to the horizon, so "everything" would come back looking
complete while stopping two years out, and a client cannot detect a truncation nobody
reported. Refusing is the only answer that does not lie.

**No `FilterOperator`.** AND/OR/NOT over a range overlap would have to be evaluated outside the
index, which is the sequential scan the index exists to avoid; and an OR of two windows is two
queries a client can make. It is refused by name, for the same reason `EmailFilterCompiler`
refuses an unknown condition.

## Writing

### `CalendarEvent/set`

`App\Jmap\Calendar\JmapEventWriter` handles the protocol — reading a JSCalendar object off the
wire, refusing what cannot be stored faithfully, and mapping a JMAP patch onto the writer's
parameter list — and **touches no column**. What an event *is* belongs to
`CalendarEventWriter`, shared with the web editor and the sync engine; a JMAP client that set
a title without `jscalendar['title']` would produce an event that looked right in the app and
exported blank.

**Unknown properties are refused, not dropped**, with `invalidProperties` naming them. A
client whose `participants` were silently discarded would believe it had invited somebody with
no way to discover otherwise. The properties left out, and why:

| Property | Why not |
|---|---|
| `participants` | an RSVP is answered through the invite flow, which sends an iTIP reply; accepting an attendee list here would record answers nobody was told about |
| `privacy` | there is no writer parameter for it, and writing the column directly is exactly what this class must not do |
| `alerts`, `links` | nowhere to project them from — the writer rebuilds the canonical object from the columns on every write, so they would survive one save and vanish on the next |

Times are parsed as strict LocalDateTime (RFC 8984 §4.1.2): no offset, no trailing `Z`.
Accepting `2026-06-02T09:00:00Z` looks like a courtesy and is not — it says UTC, the zone
beside it says Europe/Berlin, and guessing which the client meant moves a meeting by hours in
silence.

### `Thread/set` — an extension

RFC 8621's Thread is read-only, because a thread is derived from its Emails and there is
nothing on it to change. plMail's differs in exactly one way: a thread can be snoozed, and
that state belongs to the thread rather than to any message in it.

It is deliberately narrow — `create` and `destroy` are refused outright, because threads come
into being when mail arrives and go away when their last message does, and a client that could
conjure one would be describing something the rest of the system has no meaning for. `update`
accepts one property.

Setting it goes through `App\Service\Mail\ThreadSnoozeService`, the same service the web UI
uses, so a snooze means the same thing whichever client set it: the conversation leaves the
Inbox, gains the Snoozed label, and that change propagates outward to the provider. The one
deliberate difference between the callers is named at both ends — a form post gets an "in 1
day" fallback on an unparseable date where `ThreadSetMethod::snoozeDate()` refuses it.

Standard clients neither know nor need this method; `Thread/get` still answers the spec's two
properties plus one they will ignore.

### `EmailSubmission/set`

Delegates to the same `SendMessageMessage` / `SendMessageHandler` / `MessageSendService`
pipeline the web composer's send button uses. That service already performs the draft→sent
transition — adds Sent, removes Drafts, clears the `\Draft` flag, sets `sentAt`, re-points the
mailbox — so a client that omits `onSuccessUpdateEmail` still ends up correct.

**A submission has no table of its own: its id is the Email id.** That satisfies the object
model because plMail sends each draft at most once (`MessageSendService` is a no-op once
`sentAt` is set), so the mapping stays one-to-one and `EmailSubmission/get` can reconstruct
from the `Message`.

`undoStatus` is reported as `pending`: the send is queued on the bus and genuinely has not
happened when the call returns. The web composer's undo window is deliberately **not** applied
— a JMAP client asked to send now.

## Push

Two mechanisms, and both are driven by the same drained set.

`StateManager` accumulates dirty `(account, type)` pairs in memory as changes are recorded,
and `JmapPushSubscriber` drains them once at the end of the request or handler. Tokens are
read **after** the caller's flush, so they are the values a client will actually see from
`/changes` — reading them at record time would push a state that does not exist yet. A Gmail
batch importing fifty messages therefore produces one notification, not fifty.

`App\Jmap\Push\PushDispatcher` turns that into one `StateChange` per subscribed device. It
resolves accounts back to their owners first, because a subscription belongs to a user while
changes are recorded per account, and filters each subscription to the object types it asked
for.

`PushSubscription/set` (RFC 8620 §7.2.2) carries no accountId — subscriptions are per
authenticated user — and **the verification handshake is the point**. On create the server
immediately POSTs a `PushVerification` object to the client-supplied URL; the client reads the
code out of it and echoes it back via an update, and until it does the subscription receives
nothing. Without that, anyone with an account could register a stranger's URL and have plMail
POST to it on every state change. The code proves whoever registered the URL can also read
what arrives there.

`/jmap/eventsource` is the other half, for clients holding a connection rather than a Web Push
endpoint; the Session advertises it with the `types`, `closeafter` and `ping` parameters.

## Things that bite

**Emitting a label id where a binding id is meant does not fail.** Both are integers from
different tables, so the client renders a real mailbox that happens to share the number. The
same shape of bug is why occurrence ids are translated to event ids in exactly one place, and
why both have a test that round-trips every emitted id back through a filter.

**A calendar method called with the wrong accountId is `accountNotSupportedByMethod`, not
`accountNotFound`** — but only after ownership has been proved. Reversing the two checks would
turn the error into an id oracle.

**`CalendarEvent/get` is capped at 100, not 500.** It resolves one id at a time because
`findOneForUser()` scopes on the owner, which makes somebody else's event indistinguishable
from one that does not exist. The Session states the cap so a client is not surprised by
`requestTooLarge`.

**Making `CalendarState` a number without a complete recorder is the failure it was written to
prevent.** A token that moves for a quarter of the writes is worse than one that never moves,
because a client will believe it.

**`jmap_change_log` has a `pruneOlderThan()` and no caller.** The primary key is a 32-bit
integer, so the log grows for the life of the install — one row per message per sync plus one
per touched thread. The entity's own comment names the two ways out and their consequences:
switching to `bigint` means retyping the property to `?string` (Doctrine hydrates bigint as a
string), and adding a pruner means clients below the new floor get `cannotCalculateChanges`
and resync.

**A new JMAP-visible mutation that skips `MailChangeRecorder` is invisible to clients** until
something else touches the same thread. There is no test that notices a missing announcement;
that is the reason the recorder exists at all rather than each call site calling
`StateManager` twice.

**Empty JMAP maps must serialise as `{}` and not `[]`.** `SessionBuilder` and every `/set`
method substitute a `stdClass` for an empty array explicitly, because PHP's json encoder does
not distinguish them and a client parsing an array where an object is specified fails on the
Session itself.
