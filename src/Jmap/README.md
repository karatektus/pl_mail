# plMail JMAP

A conformant JMAP subset (RFC 8620 core, RFC 8621 mail): the request envelope,
the state/change engine every `/changes` method builds on, the Mailbox / Email /
Thread / Identity / EmailSubmission object methods, blob upload and download,
and push over both EventSource and Web Push. Plus calendars, under a vendor URN
rather than the unratified draft's.

**24 methods**, 6 HTTP endpoints. Tested against ltt.rs (Bearer) and Sterna
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
| `Calendar/` | `get` |
| `CalendarEvent/` | `get`, `query`, `set` |

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
- `Mail/JmapDraftWriter` — `Email/set create`, mirroring the web composer.
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
  second one carries the id-space argument.
- `Query/CalendarEventQueryRunner` — the window, the calendars, and the
  occurrence → event id translation.
- `Calendar/JmapEventWriter` — reads a JSCalendar object off the wire and hands
  it to `CalendarEventWriter`; nothing here assigns a column.
- `Calendar/CalendarState` — why the calendar state string is a constant.

**State — the sync engine**
- `State/ChangeLog` (entity) — append-only; the autoincrement PK *is* the state
  token.
- `State/StateManager` — `recordCreated/Updated/Destroyed` on the write side,
  `stateFor`/`changesSince` on the read side, plus `drainDirty()` for push.
- `State/ChangeSet`, `JmapObjectType`, `ChangeType`.

**Push** — `Push/WebPushSender` (RFC 8030/8291/8292 via `minishlink/web-push`),
`Push/PushDispatcher` (fan out to a user's devices). Draining is driven by
`App\Infrastructure\Event\Subscriber\JmapPushSubscriber`.

**Session** — `Session/SessionBuilder`. One JMAP account per connected mail
account; a unified inbox is a client-side concern.

---

## Things outside this directory that belong to it

| Concern | Where |
|---|---|
| App-password auth | `App\Entity\ApiToken`, `App\Security\ApiTokenAuthenticator`, `App\Security\JwtBearerTokenExtractor` |
| Push subscriptions | `App\Entity\PushSubscription`, `App\Infrastructure\Event\Subscriber\JmapPushSubscriber` |
| Uploaded blobs | `App\Entity\UploadedBlob`, `App\Domain\Helper\UploadStorage` |
| Raw message bytes | `App\Domain\Helper\RawMessageStorage`, `App\Service\Mail\RawMessageResolver` |
| Label structure sync | `App\Service\Label\LabelStructurePropagator` |
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
- **Calendars are served from ONE account, and it is the session's primary.**
  A Calendar is user-scoped, with no per-account binding of the sort that makes
  a Mailbox id an account-local thing. Publishing the list under every connected
  account would put one calendar under three accountIds with the same id, which
  a client — keying objects by (accountId, id) — draws three times. Every other
  account answers `accountNotSupportedByMethod`.
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
for a held submission and a race for an immediate one, refused with
`cannotUnsend` once `sentAt` is set, and **not visible afterwards**: there is no
submission row to hold "canceled", so `EmailSubmission/get` answers `notFound`
for what is once again an unsent draft.

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

**PushSubscription + Web Push** is the background path, and the same
implementation serves every platform:

| Platform | How |
|---|---|
| Android | UnifiedPush distributor (ntfy, Conversations…) — its endpoint is Web Push compatible. No FCM, no Google. |
| iOS | The PWA, **added to the Home Screen** (16.4+). Safari tabs do not get push. |
| Desktop | Browser push service, via the same PWA flow. |

FCM/APNs directly are not options for third-party clients: pushing through them
requires *the client app's* credentials, which a self-hosted server does not
have.

The **verification handshake is mandatory** (RFC 8620 §7.2.2) and is what stops
this being an open relay: on create the server POSTs a `PushVerification` to the
supplied URL, and the subscription receives nothing until the client echoes the
code back. plMail's own PWA is not exempt — `public/sw.js` posts the code to
`/settings/push/verify`.

VAPID keys come from `VAPID_SUBJECT` / `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY`
(`app:push:generate-vapid-keys` mints a pair). Locally they go in `.env.local`;
in a container there is no such file, so they are set as environment variables —
a real env var overrides the `.env.local.php` baked in by `composer dump-env
prod`. Blank keys disable push cleanly: `WebPushSender::isConfigured()` returns
false, the Session advertises an empty `vapidPublicKey`, and the settings UI
hides the toggle.

`StateManager` collapses dirty `(account, type)` pairs in memory and
`JmapPushSubscriber` drains them once per request (`kernel.terminate`) or per
worker message, so a sync importing 50 messages sends **one** notification.

---

## Deliberate limitations

- `Email/set destroy` moves to Trash and keeps the row; deleting would discard
  the local copy of mail the provider still holds. The web composer's discard
  button is the one genuine hard delete in the app — an unsent draft exists
  nowhere else — and it records a real `destroyed`, so the two cases are
  distinguishable to a client.
- `Mailbox/set` mirrors to Gmail/Microsoft only when the per-account toggle is
  on (`Account::isLabelSyncEnabled`, off by default). Graph folder *deletion* is
  refused outright — Graph deletes the messages inside the folder with it.
- `Identity/set` stores a name and address only. replyTo, bcc and signatures are
  rejected rather than silently dropped, since there is nowhere to keep them.
- `canCalculateChanges` is `false`; there is no `queryChanges`. Clients re-run
  the query, which is spec-legal.
- `Email/query` has no anchor paging; use `position`.
- Only `$seen` / `$flagged` are settable keywords.
- An `EmailSubmission` id IS the Email id — no submission table, so no history.
  Safe because a draft sends at most once. The cost is `undoStatus`: a pending
  or canceled submission has no row to be found in, so `EmailSubmission/get`
  answers `notFound` until the mail is sent and then reports `final`. A client
  polls the Email, not the submission.
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
- `CalendarEvent/get` caps at 100 ids, not the session's 500, and says so in the
  calendars accountCapabilities. Ids are resolved one at a time through
  `CalendarEventRepository::findOneForUser()`, which is the only lookup there
  that scopes on the owner; a `findByIdsForUser()` would make this one query.
- `Calendar/set` is not implemented. The two provisioned roles come from
  `CalendarProvisioner` and a mirrored one from the subscribe flow, neither of
  which a JMAP create could stand in for.
- `CalendarEvent/set` accepts a fixed JSCalendar vocabulary and refuses the rest
  by name — `participants` (an RSVP goes through `InviteResponder`, which sends
  an iTIP reply), `privacy`, `alerts`, `links`. It also refuses PatchObject
  paths (`locations/1/name`): the writer takes whole values.
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
