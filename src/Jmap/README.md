# plMail JMAP — base set (phase 1 + 2)

A compliant JMAP core envelope plus the state/change-tracking foundation that
every `/changes` method builds on. This is the skeleton; object methods
(`Mailbox/*`, `Email/*`, `EmailSubmission/set`) plug into it next.

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

## Next (phase 3+)

`Mailbox/get|query|changes` first (your `Label` maps onto JMAP `Mailbox`),
then `Email/*`, then `EmailSubmission/set`, then blob download (reuses
`AttachmentController` + the `gmail://` resolver). Add the abstract
`Get/Changes/Query/Set` base methods when the second object type makes the
shared shape worth extracting.
