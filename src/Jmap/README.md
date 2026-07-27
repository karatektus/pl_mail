# plMail JMAP — phases 1–5

A conformant JMAP subset: the core envelope, the state/change foundation every
`/changes` method builds on, and the Mailbox / Email / Thread / Identity /
EmailSubmission object methods on top of it.

## What's here

**Protocol (the dispatch machinery — built once)**
- `Protocol/Invocation`, `JmapRequest`, `JmapResponse` — the request/response envelope.
- `Protocol/JmapProcessor` — runs each method call in order; a failing call
  yields an inline error and does not abort the rest.
- `Protocol/ReferenceResolver` — resolves `#foo` result references against
  earlier results via a restricted JSON Pointer, including the `/*` wildcard.
- `Protocol/JmapContext` — per-request user + accumulated responses + createdIds.
- `Protocol/Capability` — advertised capability URNs (grow `SUPPORTED` as you go).

**Methods (each object type is a thin plug-in)**
- `Method/JmapMethod` — the interface; implementations are tagged and indexed
  by `name()` in `MethodRegistry`.
- `Method/Core/CoreEchoMethod` — `Core/echo`, a spec-compliant smoke test.
- `Method/Mail/Mailbox{Get,Query,Changes}` — Mailbox is plMail's `Label`.
- `Method/Mail/Email{Get,Query,Changes,Set}`, `ThreadGet`, `IdentityGet`.
- `Method/Mail/EmailSubmission{Set,Get}` — sending.

**Mail (phase 4/5 domain glue)**
- `Mapper/EmailMapper` — `Message` -> JMAP `Email`.
- `Mapper/MailboxCounts{,Provider}` — all four Mailbox counts for an account in
  two grouped queries, so Mailbox/get is not an N+1.
- `Query/EmailFilterCompiler`, `EmailQueryRunner`, `CompiledFilter`,
  `EmailQueryResult` — Email/query's filter/sort/collapse engine.
- `Mail/EmailPatchApplier` — the keyword/mailbox patch semantics shared by
  Email/set update and EmailSubmission/set onSuccessUpdateEmail.
- `Mail/JmapDraftWriter` — Email/set create, mirroring the web composer's
  draft path.

**Session**
- `Session/SessionBuilder` — the Session object. One JMAP account per connected
  mail account. **This is the only file coupled to the mail-account entity.**

**State (phase 2 — the sync engine)**
- `State/ChangeLog` (entity) — append-only log; autoincrement PK *is* the state token.
- `State/ChangeLogRepository` — scan/aggregate/prune queries.
- `State/StateManager` — the façade the app calls: `recordCreated/Updated/Destroyed`
  on the write side, `stateFor` and `changesSince` on the read side.
- `State/ChangeSet`, `JmapObjectType`, `ChangeType`.

## Integration points (resolved — see `wiring.md` for what was applied)

- **Mail-account shape** — `SessionBuilder` and `StateManager::sessionState()`
  iterate `User::getAccounts()` and call `Account::getId()` /
  `Account::getEmail()`. A JMAP `accountId` is the `App\Entity\Account` id as a
  string. These two files are the only ones coupled to the account entity.
- **User class** — controllers/`JmapContext` typehint `App\Entity\User`.
- **Auth** — `lexik/jwt-authentication-bundle` on a stateless `jmap` firewall
  ahead of `main`. Any bearer authenticator resolving `#[CurrentUser]` works.
- **Labels** — `message_label` (the `Message::$labels` M2M) is authoritative for
  `Email.mailboxIds`. `thread_label` is NOT used for it: `ThreadLabelSynchronizer`
  derives that table as the union of a thread's messages' labels, so reading it
  would report a mailbox for every message in the thread.
- **Read/flagged state** — `seen_at` / `starred_at` are authoritative, not the
  `\Seen` entry in `Message::$flags`. flags is an IMAP mirror only the plain-IMAP
  sync path populates, and is a strict subset of `seen_at`. flags remains
  authoritative for `$draft` / `$answered`, which have no column.
- **Full-text** — the `text` filter must use `websearch_to_tsquery('english', …)`
  to match how `Message::$searchVector` is generated. A mismatched config
  silently returns nothing.
- **Writes** — every mutation goes through `LabelChangePropagator`, the seam the
  web UI uses, so JMAP changes reach Gmail/IMAP/Graph identically. Its ordering
  contract (mutate, propagate, flush last) is load-bearing.

## Deliberate limitations

- `Email/set destroy` moves to Trash and keeps the row. plMail has no
  hard-delete path anywhere, so deleting would discard the local copy of mail
  the provider still holds.
- `canCalculateChanges` is `false` — there is no `Email/queryChanges` yet.
- `Email/query` has no anchor-based paging; use `position`.
- Only `$seen` / `$flagged` are settable keywords; `$draft` / `$answered` are
  owned by the sync layer.
- An `EmailSubmission` id IS the Email id — there is no submission table. Safe
  because a draft is sent at most once, but it means no submission history.
- Blob download/upload (`downloadUrl`/`uploadUrl` in the Session) is not
  implemented; `blobId` is the message or part id, ready for it.

## Smoke test

Mint a token, then run the two calls:

```bash
docker compose exec php php bin/console lexik:jwt:generate-token mail@pluetzner.de
```

```bash
curl -sk https://localhost/jmap/session -H "Authorization: Bearer $TOKEN"

curl -sk https://localhost/jmap/api \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"using":["urn:ietf:params:jmap:core"],
       "methodCalls":[["Core/echo",{"hello":"world"},"c0"]]}'
```

Expected: the session object, then
`{"methodResponses":[["Core/echo",{"hello":"world"},"c0"]],"sessionState":"..."}`.

## Next

- Wire `StateManager::recordUpdated` into the remaining flag/label mutation
  paths not yet covered (the Gmail/Graph *enrichment* branches update existing
  rows without recording).
- `Email/queryChanges` + `Mailbox/set`, then blob download/upload.
- Long-lived `ApiToken` (Bearer + Basic) so third-party clients survive past the
  1h JWT expiry — see the three-tier auth plan.
