# plMail JMAP

A conformant JMAP subset (RFC 8620 core, RFC 8621 mail): the request envelope,
the state/change engine every `/changes` method builds on, the Mailbox / Email /
Thread / Identity / EmailSubmission object methods, blob upload and download,
and push over both EventSource and Web Push. Plus calendars, recipient
autocomplete and the user's appearance, all under vendor URNs rather than an
unratified draft's.

Calendars count in their own log rather than `jmap_change_log` — see
`App\Entity\Calendar\CalendarChangeLog` for why neither half of that table's
key fits one — and `App\Service\Calendar\Change\CalendarChangeReader` is the
`StateManager` equivalent behind `Calendar/changes` and `CalendarEvent/changes`.
The same log is what CalDAV's sync-collection counts in.

**29 methods**, 6 HTTP endpoints. Tested against ltt.rs (Bearer) and Sterna
Mail (Basic).

| | |
|---|---|
| `Core/` | `echo`, `PushSubscription/get\|set` |
| `Mailbox/` | `get`, `query`, `changes`, `set` |
| `Email/` | `get`, `query`, `changes`, `set` |
| `Thread/` | `get`, `changes`, `set` (plMail extension: snooze) |
| `Identity/` | `get`, `set` |
| `EmailSubmission/` | `get`, `set`, `changes` |
| `SearchSnippet/` | `get` |
| `Calendar/` | `get`, `changes` |
| `CalendarEvent/` | `get`, `query`, `changes`, `set` |
| `Appearance/` | `get`, `set` (plMail extension: the user's theme, a singleton) |
| `Contact/` | `autocomplete` (plMail extension: recipient suggestions) |

---

## Layout

**Protocol — the dispatch machinery, built once**
- `Protocol/Invocation`, `JmapRequest`, `JmapResponse` — the envelope.
- `Protocol/JmapProcessor` — runs each call in order; a failing call yields an
  inline error and does not abort the rest.
- `Protocol/ReferenceResolver` — `#foo` result references via restricted JSON
  Pointer, including the `/*` wildcard (arrays only — not object maps).
- `Protocol/JmapContext` — per-request user, accumulated responses, createdIds.
  `resolveId()` implements `#creationId` anywhere an Id is expected.
- `Protocol/StateChangeBuilder` — the `StateChange` object shared by EventSource
  and Web Push, plus the snapshot/diff used to detect what moved.
- `Protocol/Capability` — advertised URNs. `SUPPORTED` gates what a client may
  declare in `using`.

**Methods** — `Method/JmapMethod` is the interface; implementations are tagged
`app.jmap_method` and indexed by `name()` in `MethodRegistry`. Adding a method
means adding one class.

**Endpoints beyond `/jmap/api`**
- `Controller/JmapSessionController` — `/jmap/session`, `/.well-known/jmap`.
- `Controller/JmapDownloadController` — blob download.
- `Controller/JmapUploadController` — blob upload into `uploaded_blob`.
- `Controller/JmapEventSourceController` — SSE `StateChange` stream.

**Blobs** — `Blob/BlobId` namespaces every blobId by what it points at:
`m-` message (RFC822 source), `p-` attachment part, `u-` client upload. Without
the prefix a bare id is ambiguous across three tables. `Blob/BlobResolver`
scopes every lookup to the account, so a foreign blob resolves to `notFound`
rather than leaking.

**Mail glue**
- `Mapper/EmailMapper` — `Message` → JMAP `Email`.
- `Mapper/MailboxCounts{,Provider}` — all four Mailbox counts for an account in
  two grouped queries, so `Mailbox/get` is not an N+1.
- `Query/EmailFilterCompiler` + `EmailQueryRunner` — `Email/query`'s
  filter/sort/collapse engine, compiled to SQL (the method returns ids only, so
  nothing is hydrated).
- `Mail/EmailPatchApplier` — keyword/mailbox patch semantics, shared by
  `Email/set update` and `EmailSubmission/set onSuccessUpdateEmail`.
- `Mail/JmapDraftWriter` — the draft content of `Email/set create` *and*
  `update`, mirroring the web composer. Both go through one attachment path.
- `Mail/IdentityResolver` — the identities an account may send as. Read by
  `Identity/get` (which publishes them) *and* `EmailSubmission/set` (which
  spends one), so an id the server offered is exactly an id it accepts.
- `Mail/SubmissionEnvelope` — the FUTURERELEASE parameters off a submission's
  envelope, and the ceiling the session advertises. `Mail/QueuedSubmission` is
  what a passed submission carries to the dispatch after the flush.

**Calendar glue**
- `Account/CalendarAccountResolver` — which JMAP account serves calendars, and
  the refusal for every other one.
- `Mapper/CalendarMapper`, `Mapper/CalendarEventMapper` — the two objects. The
  second one carries the id-space argument, and maps one dated instance as well
  as a series.
- `Query/CalendarEventQueryRunner` — the window, the calendars, and the
  occurrence → event id translation.
- `Calendar/OccurrenceId` — the id one dated instance is named by when a query
  was asked to expand recurrences, and why it is spelled the way it is.
- `Calendar/JmapEventWriter` — reads a JSCalendar object off the wire and hands
  it to `CalendarEventWriter`; nothing here assigns a column.

**State — the sync engine**
- `State/ChangeLog` (entity) — append-only; the autoincrement PK *is* the state
  token.
- `State/StateManager` — `recordCreated/Updated/Destroyed` on the write side,
  `stateFor`/`changesSince` on the read side, plus `drainDirty()` for push.
- `State/ChangeSet`, `JmapObjectType`, `ChangeType`.

**Push** — two transports behind `App\Domain\Interface\PushSenderInterface`,
picked per subscription by `Push/PushSenderRegistry` on the row's `transport`
column.
- `Push/WebPushSender` (RFC 8030/8291/8292 via `minishlink/web-push`) — browsers,
  the installed PWA, UnifiedPush distributors.
- `Push/FcmSender` (FCM HTTP v1, data messages only) — a native Android app,
  which has no push service of its own. `Push/FcmAccessTokenProvider` mints the
  bearer token by the service-account JWT grant, `Push/FcmSettings` answers
  "configured and enabled, and with what?" for the sender, the Session and
  `PushSubscription/set` alike.
- `Push/PushDispatcher` fans out to a user's devices, per transport. Draining is
  driven by `App\Infrastructure\Event\Subscriber\JmapPushSubscriber`.

**Appearance glue**
- `Mapper/AppearanceMapper` — `App\Entity\Embeddable\Appearance` → the JMAP
  object, the compact read the Session carries, and the state token. Shared by
  both methods and the Session so one spelling per property survives.
- `Method/Settings/AppearanceGet|SetMethod` — the singleton pair.

**Contacts** — `Method/Contact/ContactAutocompleteMethod`, and nothing else.
There is no mapper and no repository of its own: it is one call into
`App\Repository\Mail\ContactRepository::findForAutocomplete()`, the same query
the web composer's autocomplete runs.

**Session** — `Session/SessionBuilder`. One JMAP account per connected mail
account; a unified inbox is a client-side concern. It also carries two things
that have no methods of their own: per-account **backfill progress**
(`urn:plmail:params:jmap:sync`) and the user's **appearance** plus the closed
vocabularies `Appearance/set` accepts (`urn:plmail:params:jmap:appearance`).

---

## Things outside this directory that belong to it

| Concern | Where |
|---|---|
| App-password auth | `App\Entity\ApiToken`, `App\Security\ApiTokenAuthenticator`, `App\Security\JwtBearerTokenExtractor` |
| Push subscriptions | `App\Entity\PushSubscription`, `App\Infrastructure\Event\Subscriber\JmapPushSubscriber` |
| Firebase credentials | `App\Entity\Push\FcmConfig` (one row, encrypted key), `App\Controller\Admin\PushSettingsController` (`/admin/push`), `App\Form\Admin\FcmConfigType`, `App\Service\Push\FcmConfigWriter` |
| Uploaded blobs | `App\Entity\UploadedBlob`, `App\Domain\Helper\UploadStorage` |
| Raw message bytes | `App\Domain\Helper\RawMessageStorage`, `App\Service\Mail\RawMessageResolver` |
| Label structure sync | `App\Service\Label\LabelStructurePropagator` |
| Appearance | `App\Entity\Embeddable\Appearance` (the validated embeddable and its clamp constants), `App\Controller\Settings\AppearanceController` (the web pane, background uploads, export/import) |
| Backfill progress | `App\Entity\Mail\Account::$backfillTarget`, `needsBackfill()` — virtual properties over the `settings` JSONB bag |
| PWA | `public/manifest.webmanifest`, `public/sw.js`, `assets/controllers/web_push_controller.js`, `App\Controller\Settings\WebPushController` |
| Settings UI | `App\Controller\ApiTokenController`, `Settings\AccountLabelSyncController` |

Commands: `app:prune:blobs` (expires staged uploads; replaced
`app:jmap:prune-uploads`), `app:push:generate-vapid-keys`.

---

## Load-bearing facts

Get these wrong and things fail quietly rather than loudly.

- **`accountId` is the `App\Entity\Account` id** as a string. `SessionBuilder`
  and `StateManager::sessionState()` are the only files coupled to that entity.
- **`message_label` is authoritative for `Email.mailboxIds`.** `thread_label` is
  NOT: `ThreadLabelSynchronizer` derives it as the union of a thread's messages'
  labels, so reading it would report a mailbox for every message in the thread.
- **A JMAP Mailbox id is a `label_binding` id everywhere** — `Mailbox.id`,
  `Email.mailboxIds`, `inMailbox`, `Email/set`. `message_label` stores
  user-scoped `label` ids, so `EmailMapper` translates through
  `LabelBindingRepository::bindingIdsByLabelId()` on the way out, exactly as
  `MailboxMapper` does for `parentId`. Both are autoincrement ints from
  different tables, so an untranslated id names an unrelated mailbox rather
  than failing — and on a single-account install the sequences tend to line up,
  which is how this went unnoticed. `Mailbox.labelId` carries the label id
  deliberately, for cross-account grouping; it is not accepted anywhere as
  input.
- **`Mailbox.color` is a plMail extension and a closed vocabulary.** RFC 8621
  gives Mailbox no colour; labels have one and every client draws chips with it.
  The value is a Tailwind token from `LabelColor` — `gray`, `red`, `orange`,
  `amber`, `green`, `teal`, `blue`, `violet`, `pink` — or `null` for "no colour
  chosen", which is distinct from grey. Not hex: a token resolves per theme,
  where `#3b82f6` stays one fixed light-mode blue on a dark background. Accepted
  on `Mailbox/set` create *and* update; anything outside the set is refused with
  `invalidProperties` rather than dropped, and `null` clears it. The web form
  reads the same enum, because two copies of this list is how a colour picked on
  the phone becomes one the web renders unstyled. Colour is the only property a
  **system** label accepts an update to — renaming or destroying Inbox breaks
  the invariants hanging off its role, recolouring its chip breaks nothing.
- **The inbox category is a plMail extension in three places, and only one of
  them is filterable.** plMail classifies inbox mail the way Gmail does —
  `primary`, `social`, `promotions`, `updates`, `forums` — and stores it twice:
  raw on the message, and resolved most-recent-wins onto the thread.
  - `Email.category` is the **raw per-message signal**, read-only.
  - `Thread.category` is the **resolved conversation value**, and the only one a
    tab may be drawn from. `null` means never classified, which is a real state
    (mail older than the classifier, a locally-composed draft) and is *not*
    Primary.
  - `Email/query`'s `threadCategory` condition filters on the **thread's** value.
    Anything outside the vocabulary is refused with `invalidArguments` naming it.

  Thread-scoped rather than message-scoped because a tab holds conversations: a
  newsletter somebody replied to has messages in two categories, and filtering
  the per-message column would put that conversation in two tabs while the web —
  which filters `message_thread.category` in
  `MessageThreadRepository::findForUnifiedInbox` — shows it in one. It has to be
  a *server-side* filter for a second reason: `Email/query` windows by
  position/limit, so a client that fetched a page and sieved it locally would
  draw a nearly-empty Promotions tab under a list that had already reached its
  end.

  A thread whose category is null matches no tab, exactly as the web query does.
  That is the deliberate choice over folding null into Primary: the two surfaces
  must contain the same conversations, and a phone that showed mail the browser
  did not is the failure this whole layer is careful about. `app:backfill
  category` is what fills those in, and re-running it after a change to
  `MessageCategorizer` needs no resync — it reads only persisted data.
- **A JMAP CalendarEvent id is a `calendar_event` id — the series.** The query
  that finds events is a `tsrange &&` overlap over `calendar_event_occurrence`,
  so the ids the database hands back are *occurrence* ids and
  `CalendarEventQueryRunner` translates them. Both are autoincrement ints from
  different tables, so an untranslated one is a valid-looking id for an
  unrelated event — the same failure mode as Email.mailboxIds above, which is
  why `CalendarEventQueryMethodTest` asserts round trips rather than shapes. The
  series is the unit because that is what a JSCalendar Event *is* (rule plus
  overrides, expanded client-side), and because occurrence rows are rewritten
  wholesale on every write, so an id naming one would go stale when somebody
  corrected a title.
- **…unless the query was asked to expand recurrences, and then an id can name
  one dated instance.** `CalendarEvent/query` takes `expandRecurrences: true`
  (draft-ietf-jmap-calendars), which switches the unit from the series to the
  occurrence: one entry per instance in the window, ordered by the instance's
  start, with `position`/`limit`/`total` counting instances. The wire shape,
  exactly:

  ```json
  ["CalendarEvent/query", {
    "accountId": "1",
    "filter": {"inCalendar": "3", "after": "2026-03-01T00:00:00Z", "before": "2026-04-01T00:00:00Z"},
    "expandRecurrences": true
  }, "q"]
  ```

  ```json
  ["CalendarEvent/query", {
    "accountId": "1", "queryState": "fixed", "canCalculateChanges": false,
    "position": 0, "limit": 500, "total": 9,
    "ids": ["42_20260302T090000Z", "17", "42_20260304T090000Z", "…"]
  }, "q"]
  ```

  An instance id is `<eventId>_<recurrenceId as YYYYMMDDTHHMMSSZ>`. **Treat it
  as opaque** — the draft's own word — and note it is `_` rather than the `;`
  other implementations use, because RFC 8620 §1.2 restricts an Id to
  `A-Za-z0-9`, `-` and `_`, so a semicolon or the colons of an ISO timestamp is
  an id a conforming client library may reject before this server's response is
  read. The timestamp half is the instance's **original** start (its
  recurrence id), never where an override moved it. A one-off event keeps its
  plain series id — its single occurrence is the event — so an account with
  nothing recurring in the window answers an expanded query exactly as it
  answers a collapsed one, and `expandRecurrences` absent or false is
  byte-for-byte the old response.

  `CalendarEvent/get` resolves both kinds, so the usual `#ids` pairing works
  unchanged. An instance object is the series with its override applied,
  plus `recurrenceId` and `recurrenceIdTimeZone`, its own `start` and
  `duration`, `recurrenceRules` and `recurrenceOverrides` **null** (the draft
  says MUST), and `seriesId` — a plMail extension, and load-bearing:
  `CalendarEvent/set` refuses an instance id by name, so `seriesId` is how a
  client gets back to an id it can write through. A moved instance is drawn and
  ordered at its new time and still named by its old; an `{"excluded": true}`
  instance has no occurrence row and so is absent from the query and `notFound`
  from the getter; a `status: cancelled` one keeps its row, leaves the query and
  still resolves.

  Expanding a window that reaches past the materialised horizon is refused with
  `cannotCalculateOccurrences` rather than answered short. Collapsed, an
  overrunning window is merely thin — the series is named and its rule comes
  with it. Expanded, the answer *is* the list of instances, so a series that
  stops at the horizon comes back as a series that ends and nothing says
  otherwise. `timeZone` is refused alongside `expandRecurrences` for the same
  reason: this server does not convert, and a client told nothing would draw a
  month in the wrong zone.
- **Calendars are served from ONE account, and it is the session's primary.**
  A Calendar is user-scoped, with no per-account binding of the sort that makes
  a Mailbox id an account-local thing. Publishing the list under every connected
  account would put one calendar under three accountIds with the same id, which
  a client — keying objects by (accountId, id) — draws three times. Every other
  account answers `accountNotSupportedByMethod`.
- **`Appearance` is per USER, and carries no accountId at all.** A user has one
  theme and any number of connected mailboxes, so the object is resolved off
  the authenticated user the way `PushSubscription` is — there is no id on the
  wire that could name somebody else's. An `accountId` sent anyway is refused
  with `invalidArguments` rather than ignored: a client that sent one believes
  something false about what it is reading, and the first call is a cheaper
  place to learn that than the first mismatch between two accounts.

  It is a **singleton**: one object, id `singleton` (RFC 8620 §5.3, the shape
  RFC 8621's VacationResponse uses). `create` and `destroy` are answered with
  the spec's own `singleton` SetError.

  **What is refused and what is clamped** is the decision to know here.
  `Appearance`'s setters are deliberately forgiving — an unknown theme keeps
  the old one, a malformed hex resets the accent to plMail's default, an
  out-of-range slider is pulled to the nearest end. That is right for the web
  pane, a closed form that cannot send anything else, and wrong over the wire,
  where the client is somebody else's code and would be told it succeeded. So
  closed vocabularies (`theme`, `layout`, `density`, `backgroundKind`,
  `backgroundPreset`) and malformed colours are **refused** with
  `invalidProperties` naming what is accepted — the `Mailbox.color` precedent —
  while numeric ranges are **clamped**, because a slider is a continuum and 1.4
  is a sloppy client rather than an impossible request. A clamp is never
  silent: it comes back in the `updated` map (RFC 8620 §5.3 — what the server
  changed beyond what was asked), and the bounds are published in the Session
  under `ranges`, read off the setters' own `Appearance::RANGE_*` constants.
- **Picking a `layout` seeds its knob preset.** That is what a layout *is* (see
  the `Layout` enum: a structural CSS class plus starting values), and what the
  web pane does client-side so its sliders stay in step. A client that sent
  only `layout` would otherwise get the new structure wearing the old layout's
  numbers, which looks like nothing happened. Explicit knobs in the same patch
  are applied after the preset and win; the seeded ones are reported in
  `updated` like any other change the client did not ask for.
- **Backfill progress is published per account and read-only.** The Session's
  `accountCapabilities` carry `urn:plmail:params:jmap:sync` beside the mail
  capability: `backfillTarget` (how far back a *completed* backfill reached —
  0 for the whole mailbox, null when none has finished) and `backfillPending`.
  It exists so a client can answer "why is a mail I know exists not in
  search?": from the phone, mail the server has not fetched yet and mail the
  phone has not caught up on are the same empty result, and they want opposite
  reactions.

  There is no retention setting behind this any more — the server intends to
  hold every message an account has, so the only gap it can honestly report is
  a backfill that has not finished walking. A **positive** `backfillTarget` is
  a stopping point left over from the retired newest-N cap, so it reports as
  unfinished rather than complete. `backfillPending` is
  `Account::needsBackfill()`, derived from that number rather than recorded
  separately, so the two cannot disagree. It is NOT "a backfill is running this
  second": nothing records that, and a client would read a running flag as
  progress.
- **The calendar state string is fixed, and that is deliberate.** Mail's token
  works because `MailChangeRecorder` makes the change log complete. An event
  changes from four places — the sync engine, extraction, the web editor and
  `CalendarEvent/set` — and only the last is in this directory, so a log written
  here would sit still while a pull replaced the whole day. `canCalculateChanges`
  is false, there is no `/changes`, and the value is non-numeric so that a client
  still holding it gets `cannotCalculateChanges` if calendars ever do join the
  log. See `Calendar/CalendarState`.
- **`CalendarEvent/set` marks a synced event for push, and the writer does not.**
  `CalendarEventWriter::write()` is also how a pull applies what it just read
  from a remote, so marking there would push the remote's own data back at it —
  hence `markLocallyCreated` / `markLocallyChanged` belong to whatever made the
  change. A JMAP client is a person making a change, exactly as the web editor
  is; without the mark an edit made on a phone never leaves the machine and the
  next pull silently reverts it.
- **`Email.attachments` is a whole value on create *and* update, never a patch.**
  The array a client sends is the complete set the draft should end up with, so
  a part left out of it is a part it removed — RFC 8620 §5.3 spells a patch as
  `attachments/0`, and this is the plain property. A part already on the draft
  is kept by the `p-` blobId `Email/get` handed out, with no second upload of
  bytes already stored and no new part id; anything else is resolved through
  `BlobResolver` and **copied** into attachment storage, because an
  `UploadedBlob` is scratch space `app:prune:blobs` reclaims on a timer. A
  blobId that will not resolve — malformed, expired, or another account's —
  fails the whole update with `invalidProperties` and writes nothing at all,
  including the subject in the same patch. Update used to accept the property
  and drop it silently while the rest of the patch applied, which left a client
  told `updated` with no idea the file was gone; `EmailSetAttachmentsTest` is
  the pin.
- **An Identity id is an `email_alias` id, not the accountId** — one identity
  per sendable alias, from `Account::$sendableAliases`, which is also what the
  web composer's From dropdown is built from. Only an account with no alias
  rows yet publishes a single synthetic identity keyed by the *account* id.
  `EmailSubmission/set` resolves `identityId` through the same
  `IdentityResolver` and writes the result to `Message::$fromAddress`, which is
  where `MessageSendService` reads the From header from. An id that resolves to
  nothing is refused with `forbiddenFrom` — never sent as the account's own
  address, which is what this did while it ignored the property.
- **A submission's send is dispatched after the flush, not where it is
  decided.** The worker reads the From off the row, so an envelope handed to
  the bus before the transaction commits races it, and the mail that loses the
  race leaves as the address the client did not pick.
- **`Contact/autocomplete` is a function call, not an object type.** It exists
  because ranking recipient suggestions is the one part of composing a client
  cannot do for itself: the order has to come from the *whole* address book —
  a correspondent written to twice a year outranks a list seen once — and a
  freshly-paired phone has no local mail to build one from. It takes
  `accountId`, a non-empty `query` and an optional `limit`, and answers with
  `{accountId, query, limit, list}` where each entry is a JMAP `EmailAddress`
  (`name`, `email` — the shape `EmailMapper` already emits, so it goes straight
  into an `Email/set` create) plus `frequency`, `lastSeenAt` and
  `isCorrespondent`. The list is ordered `frequency DESC, last_seen_at DESC`
  by the same repository method the web composer uses, so both surfaces rank
  identically.

  **No id, and that is the point.** A `contact` row's key is reachable from no
  other method, so publishing it would invite a client to cache and re-fetch by
  something with no getter. `uniq_contact_user_email` makes (user, email)
  unique, so the address is the stable key. `initials`, which the HTML route at
  `GET /contacts/autocomplete` returns, is deliberately absent: it is derived
  from the two fields above it and exists there only because a Turbo response
  fed a chip renderer.

  It is served from **every** account, unlike calendars — the one place that
  rule is deliberately not followed. Calendars are restricted because a client
  keys objects by (accountId, id) and would draw one calendar per connected
  account; nothing here has an id, so there is nothing to draw twice, and
  refusing would fail a client composing from whichever account it is composing
  from. `primaryAccounts` still names one for a client that just wants
  somewhere to ask.
- **`seen_at` / `starred_at` are authoritative** for `$seen` / `$flagged`, not
  the `\Seen` entry in `Message::$flags`. flags is an IMAP mirror only the
  plain-IMAP path populates and is a strict subset of `seen_at`. flags *is*
  authoritative for `$draft` / `$answered`, which have no column.
- **Full-text must use `websearch_to_tsquery('english', …)`** to match how
  `Message::$searchVector` is generated. A mismatched config returns nothing at
  all, silently.
- **The change log's PK is one sequence shared by every (account, objectType)**,
  so the first row of a type can sit at any number. `changesSince()` must treat
  `sinceState: "0"` as always answerable — otherwise every freshly-connected
  client is told to resync.
- **Writes go through `LabelChangePropagator`**, the same seam the web UI uses,
  so a JMAP change reaches Gmail/IMAP/Graph identically. Its ordering contract —
  mutate, propagate, flush last — is load-bearing: `detachLabel` reads
  `getMailbox()` before it is re-pointed.
- **Web Push silently drops the payload without encryption keys**
  (`WebPush.php` guards on `!empty($userPublicKey) && !empty($userAuthToken)`),
  which is why `keys.p256dh` and `keys.auth` are required even though RFC 8620
  marks them optional — a bodiless push cannot carry the verification code.
- **One FCM collapse key would eat the handshake.** Collapsing is wanted, so a
  phone that was off is woken by the newest state rather than nine stale ones —
  but a `StateChange` sharing a key with an undelivered `PushVerification`
  replaces it, and the subscription waits forever for a code FCM discarded.
  `FcmSender` keys by payload `@type` for that reason alone.
- **`PushSubscription::$url` is nullable now.** An FCM subscription has no
  endpoint. `PushSenderRegistry` routes by transport so nothing should meet a
  null, and `WebPushSender` guards anyway — a mis-routed row must be a logged
  refusal rather than a TypeError inside the push library.
- **`var/attachments`, `var/raw` and `var/uploads` must be shared storage.** The
  workers write them, the web container serves them. `php` overlays `/app/var`
  with a private volume for cache/logs, so those three paths are bound
  explicitly in every service — see `compose.override.yaml` and
  `truenas.compose.yaml`. The Dockerfile deliberately no longer declares
  `VOLUME /app/var/`; it gave each container its own anonymous volume there,
  which made downloads 404 and lost the data on every container recreate.
- **Every path that changes a JMAP-visible property must call
  `StateManager::record*`** — including the ones that mutate an *existing* row
  rather than creating one: `GraphApiSyncer::attach/detachFolderLabel` (a folder
  move in Outlook), `SyncGmailMessageBatchHandler::enrichExisting`, and the
  Gmailify claim in `MessageSyncer`. Internal-only writes are deliberately not
  recorded — re-pointing `graphId` changes no Email property, and pushing for it
  would wake every client for nothing.
- **Restart `messenger-worker` and `imap-supervisor` after any DI change.**
  Long-running workers hold a stale compiled container and fail with "Too few
  arguments" or skip new event subscribers entirely.

---

## Sending

`EmailSubmission/set` takes three decisions from the client, and refuses each by
name rather than quietly doing something else.

**Which address it leaves as** — `identityId`, an id from `Identity/get`:

```json
["EmailSubmission/set", {"accountId": "1", "create": {"s1": {
  "emailId": "42", "identityId": "7"
}}}, "c1"]
```

Answered with `created.s1 = {id, sendAt, undoStatus: "pending"}`, the id being
the Email id. An `identityId` that is not a sendable alias of that account comes
back in `notCreated` as `forbiddenFrom`. Omitting it leaves the draft's own From
untouched, which is what a draft saved by the web composer already carries.

**When it leaves** — RFC 8621 §7 has no scheduling property; it carries the SMTP
FUTURERELEASE extension (RFC 4865) as envelope parameters:

```json
{"emailId": "42", "envelope": {"mailFrom": {"parameters": {"HOLDFOR": "3600"}}}}
```

`HOLDFOR` (seconds) or `HOLDUNTIL` (a date-time), not both; parameter names are
ESMTP keywords and case-insensitive. The hold becomes a `DelayStamp` on the
`SendMessageMessage`, `sendAt` in the answer is the real release time, and the
ceiling is `maxDelayedSend` in the submission accountCapabilities — 30 days,
`SubmissionEnvelope::MAX_HOLD_SECONDS`, enforced rather than clamped. A hold
that has already elapsed sends immediately (RFC 4865), and no hold at all is the
old path exactly: dispatched with no stamp.

The rest of the envelope is **checked, not applied**: the pipeline sends the
From and the recipients that are on the row, so a `mailFrom.email` or a `rcptTo`
set naming anything else describes mail this server will not send and is refused
(`forbiddenFrom` / `invalidRecipients`). Unknown parameters are refused naming
the two that work.

**Whether it still leaves** — an update of `undoStatus` to `canceled`:

```json
["EmailSubmission/set", {"accountId": "1", "update": {"42": {"undoStatus": "canceled"}}}, "c2"]
```

This sets `Message::$cancelled`, the same flag the web composer's undo button
sets and `SendMessageHandler` reads when the envelope comes due — nothing is
pulled out of the queue; the send declines to happen. It is therefore reliable
for a held submission and a race for an immediate one, and refused with
`cannotUnsend` once `sentAt` is set. A cancel of an Email that was never
submitted is refused with `notFound`: it used to be accepted, which armed a flag
that the user's *next* send from the web composer then walked into.

### What a submission looks like afterwards

`EmailSubmission/get` reconstructs from the message row, and two columns exist
for no other reader — `submission_send_at` (written for every accepted
submission) and `submission_cancelled_at`:

| On the row | `undoStatus` | `sendAt` |
|---|---|---|
| `sent_at` set | `final` | when it left |
| `submission_cancelled_at` set | `canceled` | when it would have left |
| `submission_send_at` only | `pending` | when it is due |
| none of them | `notFound` | — |

Both columns were added because a held submission used to be **invisible**:
anything without a `sentAt` was skipped, so a scheduled send answered `notFound`
for the whole hold and then `final`, and its release time existed only in the
create response. A client that lost that response could not ask again, which
forced every client to keep its schedules device-local — the gap that turned up
during client adoption.

The cancel needs a column of its own rather than reusing `Message::$cancelled`,
which is a one-shot flag: `SendMessageHandler` clears it when the envelope comes
due, so minutes later the flag no longer says anything happened. Reading
`undoStatus` off it would report `pending` forever for mail that is never going
out.

`EmailSubmission/changes` keeps up with all three transitions: the submit
records `created`, an accepted cancel records `updated`, and `MessageSendService`
records `updated` when submitted mail actually leaves. The last two were
deliberately absent while the submission was ungettable — announcing a change to
an id that answers `notFound` wakes every client for nothing.

---

## Auth

Two credential types share the stateless `jmap` firewall:

- **App passwords** (`plmail_…`) — long-lived, per-device, created in
  Settings → App passwords. Accepted as `Authorization: Bearer` *and*
  `Basic email:password`, because ltt.rs sends the first and Sterna the second.
  Only a SHA-256 hash is stored.
- **JWT** (lexik) — short-lived, for the future first-party app.

Both arrive as a Bearer token, and Symfony runs *every* authenticator that
supports a request — so `JwtBearerTokenExtractor` hides prefixed app passwords
from the JWT authenticator. Without it an app password authenticates correctly
and is then overwritten by the JWT authenticator's failure response.

---

## Push

JMAP never pushes mail content. It pushes a `StateChange`:

```json
{"@type":"StateChange","changed":{"4":{"Email":"199","Thread":"200"}}}
```

The client then calls `Email/changes`. Tokens come from
`StateManager::stateFor()`, so a push and a subsequent `/changes` cannot
disagree.

**EventSource** (`/jmap/eventsource`) is the foreground path. Each connection
holds a PHP worker for its lifetime — a hard capacity limit under FrankenPHP —
so it is capped at 5 minutes and clients reconnect.

**PushSubscription** is the background path, over one of two transports:

| Platform | How |
|---|---|
| Android, no Google | UnifiedPush distributor (ntfy, Conversations…) — its endpoint is Web Push compatible. |
| Android, native app | FCM, `transport: "fcm"` — Android has no other push service, and a plain Android app cannot speak Web Push. |
| iOS | The PWA, **added to the Home Screen** (16.4+). Safari tabs do not get push. |
| Desktop | Browser push service, via the same PWA flow. |

APNs is still not an option, for the reason FCM used to share: pushing through
it needs *the client app's* credentials. FCM stopped sharing it because the
credentials are now the *instance's* — an admin pastes their own Firebase
project's key, and the Session hands the app the public half so a single Play
Store build can initialise Firebase against whichever install it is signed in to.

The **verification handshake is mandatory** (RFC 8620 §7.2.2) and is what stops
this being an open relay: on create the server sends a `PushVerification` to the
address supplied — POSTed for Web Push, a data message for FCM — and the
subscription receives nothing until the client echoes the code back. plMail's own
PWA is not exempt: `public/sw.js` posts the code to `/settings/push/verify`.

VAPID keys come from `VAPID_SUBJECT` / `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY`
(`app:push:generate-vapid-keys` mints a pair). Locally they go in `.env.local`;
in a container there is no such file, so they are set as environment variables —
a real env var overrides the `.env.local.php` baked in by `composer dump-env
prod`. Blank keys disable Web Push cleanly: `WebPushSender::isConfigured()`
returns false, the Session advertises an empty `vapidPublicKey`, and the settings
UI hides the toggle.

Firebase is configured at runtime instead, under `/admin/push` — the key is
pasted out of a console rather than generated, is rotated when it leaks, and
belongs to a project that may not exist when the image is built, so an env var
would put the setup step behind the deployment mechanism. `FcmSettings` answers
the same "is it usable?" question for the sender, the Session's `fcm` flag and
`PushSubscription/set`'s refusal, so those three cannot disagree.

`StateManager` collapses dirty `(account, type)` pairs in memory and
`JmapPushSubscriber` drains them once per request (`kernel.terminate`) or per
worker message, so a sync importing 50 messages sends **one** notification.

### The delivery log

Every attempt by either transport writes a `push_delivery` row — including the
`PushVerification` of the handshake, and including the attempts that send
nothing. It holds the device (`deviceClientId` plus transport), the user, the
payload's `@type`, an outcome of `accepted` / `failed` / `subscription-destroyed`
/ `skipped`, the HTTP status or FCM error name, the latency and the time. Read
at `/admin/push` (filterable by user, transport and outcome) and, per device, in
the user's own **Settings → Notifications**. Retention is
`app:monitoring:prune --push-days=N`, 30 days by default.

**It records the payload's `@type` and nothing else of the payload**, which is a
promise `FcmSenderTest` asserts rather than a docblock. A `StateChange` carries
the account ids and state tokens that moved; keeping it would make this table a
retained, admin-readable index of when each user's mail arrives.

`PushDeliveryRecorder` is called **from inside the senders**, not as a decorator
over `PushSenderInterface`, and the interface is why. It collapses "refused",
"unreachable" and "just destroyed" into one bool — correctly, since no caller
distinguishes them — so a decorator sees `false` and could record neither the
status code nor `UNREGISTERED` versus `QUOTA_EXCEEDED`, which is the difference
between a dead phone and a Firebase outage. There is deliberately **no foreign
key to `jmap_push_subscription`**: the most useful row is the one written as a
subscription is destroyed, and a cascade would delete the evidence in the same
statement that creates the need for it.

---

## Deliberate limitations

- `Email/set destroy` moves to Trash and keeps the row; deleting would discard
  the local copy of mail the provider still holds. The web composer's discard
  button is the one genuine hard delete in the app — an unsent draft exists
  nowhere else — and it records a real `destroyed`, so the two cases are
  distinguishable to a client.
- `Mailbox/set` mirrors to Gmail/Microsoft unconditionally
  (`Account::supportsLabelSync`). There was a per-account toggle, off by
  default; it is gone, because an account whose labels exist only in plMail
  loses its organisation the moment the user opens the provider's own client.
  Plain IMAP is still excluded, since there a label is a physical folder and
  create/delete would move real mail. Graph folder *deletion* is refused
  outright — Graph deletes the messages inside the folder with it.
- `Identity/set` stores a name and address only. replyTo, bcc and signatures are
  rejected rather than silently dropped, since there is nowhere to keep them.
- `canCalculateChanges` is `false`; there is no `queryChanges`. Clients re-run
  the query, which is spec-legal.
- `Email/query` has no anchor paging; use `position`.
- Only `$seen` / `$flagged` are settable keywords.
- An `EmailSubmission` id IS the Email id — no submission table, so no history.
  Safe because a draft sends at most once. What that still costs is history
  rather than state: the *current* state of a submission is reconstructible
  (`pending` / `canceled` / `final`, see Sending above), but a draft submitted,
  cancelled and submitted again has one id and one story, not two.
- `EmailSubmission/set destroy` is not implemented; there is nothing to destroy.
- Only `Email/set` and the web composer choose a From. `EmailSubmission/set`
  writes `fromAddress` but never `fromName`: `MessageSendService` builds the
  display name from the account, so an alias's own display name is not used by
  either surface.
- An `m-` blob is the original RFC822 bytes when available (IMAP stores them at
  sync time; Gmail/Graph fetch on first access) and a **reconstruction**
  otherwise. `MessageSourceBuilder` is the fallback, not the primary path, and
  its output will not verify a DKIM signature.
- `SearchSnippet/get` highlights through the same `ts_headline` /
  `websearch_to_tsquery('english', …)` pair the query ran on, so two things
  follow from Postgres rather than from us. A term that is an English stopword
  (`the`, `is`, `a`) produces an empty tsquery and therefore no `<mark>`, and
  the snippet comes back with `subject` and `preview` both null — the spec's
  answer for "nothing to highlight", not a missing message. And matching is
  **stemmed**: a search for `running` marks `run`, and a term whose stem
  differs from the literal text still highlights. Re-implementing either in PHP
  would let a snippet highlight something the search did not match on.
- **Calendars are advertised as `urn:plmail:params:jmap:calendars`, not
  `urn:ietf:params:jmap:calendars`.** JMAP for Calendars is an unratified draft
  whose object shape is still moving; claiming its URN would promise a contract
  no client could rely on. Same call `urn:plmail:params:jmap:push` already made.
- `CalendarEvent/query` **requires** `after` and `before`. Occurrences are
  materialised only to `RecurrenceMaterialiser`'s horizon, so an unbounded query
  would answer from a partial index and look complete. `inCalendar` is the only
  other condition; a FilterOperator and a `sort` are refused rather than ignored.
  With `expandRecurrences`, a window past the horizon and a `timeZone` argument
  are refused too — see the load-bearing fact above.
- `CalendarEvent/set` cannot write ONE instance of a series. An instance id from
  an expanded query is refused by name (`invalidArguments`, naming `seriesId`)
  rather than answered `notFound`; the draft expects `/set` to resolve those ids
  into a `recurrenceOverrides` patch, and `EventInstanceEditor` is the service
  that would do it. The web editor already writes per-instance patches, so this
  is a wiring job, not a design one.
- `CalendarEvent/get` caps at 100 ids, not the session's 500, and says so in the
  calendars accountCapabilities. Ids are resolved one at a time through
  `CalendarEventRepository::findOneForUser()`, which is the only lookup there
  that scopes on the owner; a `findByIdsForUser()` would make this one query. An
  instance id costs a second lookup on top, for the occurrence row.
- `Calendar/set` is not implemented. The two provisioned roles come from
  `CalendarProvisioner` and a mirrored one from the subscribe flow, neither of
  which a JMAP create could stand in for.
- `CalendarEvent/set` accepts a fixed JSCalendar vocabulary and refuses the rest
  by name — `participants` (an RSVP goes through `InviteResponder`, which sends
  an iTIP reply), `privacy`, `alerts`, `links`. It also refuses PatchObject
  paths (`locations/1/name`): the writer takes whole values.
- **There is no `Appearance/changes`, and the state token is a hash of the
  object.** Appearance is not in the change log and cannot be: the log is keyed
  by (mail account, object type) and this belongs to the user, so there is no
  account to file it under. The hash has the one property a client needs — it
  differs exactly when the object differs — and no monotonicity, so `/changes`
  would have nothing to answer from. A client that sees the token move
  re-fetches the one object, which is a single call. `ifInState` is honoured on
  `Appearance/set`, so two devices editing the theme do not silently overwrite
  each other.
- **`Appearance.backgroundFile` is reported and not settable.** It names a file
  uploaded through the web settings pane and served from a route behind the
  session firewall, so a JMAP client can neither upload one nor fetch it, and
  accepting the property would store a filename resolving to nothing. It is
  still reported, because `backgroundKind: "custom"` with no way to see what
  the background *is* leaves a client unable to tell "the user chose a photo I
  cannot draw" from "this value is broken". Echoing the current value back is
  accepted — get → edit one field → set is how a client is supposed to work —
  and a different one is refused. `id` is treated the same way.
- **`Appearance.logoStyle` is reported, derived, and not settable.** What goes
  over the wire is `Appearance::effectiveLogoStyle()` — the colourway the "pl"
  mark actually wears — not the stored column, because the mark follows the
  theme unless the user has unlinked it (`logoLinked`), and every colourway is
  also a theme name. Publishing the column instead would have a client draw a
  different mark from the web for the same account, which is the disagreement
  this object exists to end. It is derived from three stored fields, two of
  which are not on the wire at all, so a patch naming it says nothing this
  server could act on: a *different* value is refused like `backgroundFile`'s,
  and an *echo* is accepted — and then **dropped before it reaches
  `applyArray()`**, which is the one place the two read-only properties differ.
  `applyArray()` knows the key `logoStyle` and would write the echoed effective
  value onto the stored colourway, silently replacing an unlinked user's choice
  with a copy of their theme's name. Setting `theme` moves the mark, and the
  new colourway comes back in that call's `updated` map.
- **The appearance in the Session is a hint, not the read.** The Session's
  `state` is a hash of the user's account ids and does not move when a theme
  changes, so a client holding an old Session holds an old theme.
  `Appearance/get` is authoritative; the compact copy exists so the chrome can
  be painted in the right palette on the first frame instead of flashing the
  wrong one. `logoStyle` is deliberately kept OUT of that compact copy: the
  Android client turns it into a launcher icon, and a hint that can be stale
  for the life of a cached Session is the wrong source for something committed
  to outside the app. The *vocabulary* `logoStyles` is published there, because
  a list of enum cases cannot go stale — and because a read-only vocabulary,
  unlike a settable one, can never be discovered by being refused.
- `urn:plmail:params:jmap:sync` has no methods, and is in `Capability::SUPPORTED`
  anyway. A client that lists a capability it depends on in `using` — the
  obvious thing to do — would otherwise have its whole request refused with
  `unknownCapability` rather than merely losing the extension.
- **Contacts are advertised as `urn:plmail:params:jmap:contacts`, and there is
  no `Contact/get`, `Contact/query` or `Contact/set`.** RFC 8621 has no Contacts
  section, and the JMAP Contacts draft describes an AddressBook of ContactCards
  a client may create, update and destroy. plMail's `contact` table is written
  only by the header harvest (`HarvestContactsService`) and read by two
  features; there is no address book to write to. The method is called
  `autocomplete` rather than `query` for the same honesty: a `/query` returns
  ids for a `/get` to resolve, and this returns objects.
- **`Contact/autocomplete` ranks by `frequency DESC, last_seen_at DESC`**, and
  the order is entirely the database's — nothing sorts the page in PHP, which
  would produce a list ordered only within the window the client asked for.
  `ContactRepository::findForAutocomplete()` owns both keys and is shared with
  the web composer, so the two surfaces rank identically; changing the ranking
  changes both at once, deliberately. Recency is a **tie-break, not a
  competitor**: somebody written to twenty times last year still outranks
  somebody seen once this morning. It exists because ties are the common case
  — most of an address book is people seen exactly once — and frequency alone
  left those to whatever order Postgres returned, so the list reshuffled
  between keystrokes. `isCorrespondent` is *not* a sort key; it is returned so
  a client can mark somebody the user has actually written to.

  The `ORDER BY` carries an explicit nulls-last `CASE`. DQL cannot say
  `NULLS LAST` (the ORM 3.6 parser rejects it) and Postgres orders NULLs
  **first** on a DESC sort, so the default would put never-seen contacts at the
  top of every list. It cannot fire today — `last_seen_at` is NOT NULL and both
  write paths stamp it — and is there so that making the column nullable later
  does not silently invert the ranking.
- **An empty `Contact/autocomplete` query is `invalidArguments`, where the HTML
  route returns `[]`.** A blank query LIKEs everything and would answer with the
  eight most frequent addresses to somebody who has typed nothing. Returning an
  empty list is right for a keystroke handler and wrong for an API: "no matches"
  and "you sent no query" are different facts and a client can act on only one.
  Arguments the method does not have (`filter`, `sort`, `position` — reasonable
  guesses, since every other query-shaped method here takes them) are refused by
  name rather than ignored. `limit` is the exception and is capped at
  `maxSuggestions` rather than refused, with the applied value echoed back.
- Not implemented: `Email/copy|import|parse`, `VacationResponse/*`, `Blob/copy`.

---

## Smoke test

```bash
docker compose exec php php bin/console lexik:jwt:generate-token you@example.com
```

```bash
curl -sk https://localhost/jmap/session -H "Authorization: Bearer $TOKEN"
```

```bash
curl -sk https://localhost/jmap/api -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{"using":["urn:ietf:params:jmap:core","urn:ietf:params:jmap:mail"],"methodCalls":[["Email/query",{"accountId":"1","limit":2},"q"],["Email/get",{"accountId":"1","#ids":{"resultOf":"q","name":"Email/query","path":"/ids/*"},"properties":["subject","from","keywords"]},"g"]]}'
```

```bash
curl -sk https://localhost/jmap/api -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{"using":["urn:ietf:params:jmap:core","urn:plmail:params:jmap:appearance"],"methodCalls":[["Appearance/set",{"update":{"singleton":{"theme":"nord","paneAlpha":1.4}}},"s"],["Appearance/get",{},"g"]]}'
```

The `updated` map in that response carries `paneAlpha: 1.0` — the clamp, stated
rather than applied behind the client's back.

```bash
curl -sk https://localhost/jmap/api -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{"using":["urn:ietf:params:jmap:core","urn:plmail:params:jmap:contacts"],"methodCalls":[["Contact/autocomplete",{"accountId":"1","query":"an","limit":5},"c"]]}'
```

An app password works in place of the JWT, as `Bearer plmail_…` or
`-u you@example.com:plmail_…`.

---

## Next

- `Email/queryChanges` and `Mailbox/queryChanges` — they need the previous query
  result to diff against, which the change log does not store.
- `Email/copy|import`, `Email/parse`, `VacationResponse/*`.
- OAuth-as-provider (tier 3 of the auth plan).
- Mailbox counts change whenever an Email moves, but only Mailbox
  create/rename/destroy is recorded. `Mailbox/changes` returns
  `updatedProperties: null`, so a client that cares re-fetches; a count-only
  change does not currently wake anyone.
