# Contributing to plMail

Development setup, test suites and console reference. For installing and running plMail, see the
[README](README.md).

## Stack

| Layer | Technology |
|---|---|
| Backend | Symfony 8, Doctrine ORM, PHP 8.4+ |
| Database | PostgreSQL 18 |
| IMAP | webklex/php-imap |
| Gmail | Gmail REST + Batch API, OAuth2 (league/oauth2-client) |
| Microsoft | Microsoft Graph API, OAuth2 |
| Async | Symfony Messenger, Doctrine transport |
| Push | Mercure hub, Gmail Cloud Pub/Sub watch, Graph subscriptions, Web Push (VAPID) |
| Interop | JMAP server (`/jmap`), authenticated with per-user app passwords |
| Frontend | AssetMapper, Tailwind v4, Hotwire Turbo, Stimulus |
| Runtime | FrankenPHP |
| Credentials | libsodium secretbox via the `encrypted_string` Doctrine type |
| Dev tooling | Docker Compose, Adminer, Mailpit |

## Development setup

```bash
cp .env .env.local
```

```bash
docker compose up --build
```

Migrations run automatically via the entrypoint. Create the first user with `app:setup`, or register
through the UI. The `imap-supervisor` and `messenger-worker` services start with the stack and
restart on failure.

## Tests

Two suites: PHPUnit for unit tests (`tests/`, mirroring `src/`) and Playwright for browser end-to-end
tests (`tests/e2e/`).

Both run against `compose.test.yaml` — a separate compose project with its own Postgres, so they never
touch the dev stack or the database holding your mail. Playwright runs on the host (Node 18+; the repo
ships an `.nvmrc`).

```bash
npm ci
```

```bash
npx playwright install chromium
```

```bash
npm run test:unit:docker
```

```bash
npm run test:e2e:docker
```

| Command | Description |
|---|---|
| `npm run test:e2e:docker:ui` | Playwright's watch UI |
| `npm run test:env:up` | Start the test stack (migrates, builds assets, seeds the E2E user) |
| `npm run test:env:down` | Stop it, keeping the database volume |
| `npm run test:env:reset` | Stop it and delete its volumes — next run rebuilds from scratch |
| `npm run test:env:logs` | Tail the test app's logs |

The test app is served at `http://127.0.0.1:8001` (override with `TEST_HTTP_PORT`). Individual specs
reseed their own fixtures, so tests are independent and re-runnable.

CI runs the same suites without Docker — see [.github/workflows/e2e.yml](.github/workflows/e2e.yml).

## README screenshots

`tests/e2e/screenshots.spec.ts` regenerates the images in `docs/screenshots/`. It asserts nothing and
is not part of the regression suite — run it deliberately, against a stack seeded with demo data:

```bash
E2E_DOCKER=1 npx playwright test screenshots.spec.ts --project=chromium --workers=1
```

Never point it at a stack holding real mail.

## Console commands

| Command | Description |
|---|---|
| `app:setup` | Create the first admin user (interactive) |
| `app:mail:sync [account-id]` | Dispatch an account-level sync for one or all active accounts |
| `app:mail:send-draft [message-id]` | Send a draft message (picker if no ID given) |
| `app:mail:test-connection` | Probe an account's IMAP/SMTP settings |
| `app:contacts:harvest [account-id]` | Harvest contact addresses from synced messages |
| `app:label:backfill [--account=ID]` | Create labels from existing mailboxes and backfill assignments |
| `app:imap:idle <mailbox-id>` | Hold an IMAP IDLE connection for a single mailbox |
| `app:imap:supervise` | Spawn and watch one `app:imap:idle` process per IDLE-enabled mailbox |
| `app:imap:test [--account=ID]` | Test an IMAP connection and folder listing |
| `app:push:renew` | Renew Gmail watches and Graph subscriptions nearing expiry |
| `app:push:generate-vapid-keys` | Generate a VAPID keypair for Web Push |
| `app:graph:diagnose` | Probe Microsoft Graph access for one account and report what works |
| `app:attachments:reclassify` | Recompute inline/attachment classification for stored parts |
| `app:jmap:prune-uploads` | Delete unreferenced JMAP blob uploads past the retention window |
| `app:user:promote <email> [--revoke]` | Grant or revoke `ROLE_ADMIN` |
| `app:monitoring:prune [--days=N]` | Prune old log entries and dead process heartbeats |
| `app:reset` | Truncate synced data — useful during development |

Schedule `app:mail:sync`, `app:push:renew` and `app:monitoring:prune` via cron or the Symfony
Scheduler as needed.

## Roadmap

- [ ] Complete the label-based architecture refactor (Label as the user-facing concept; Mailbox demoted to IMAP sync infrastructure)
- [x] Sanitize rendered HTML bodies
- [x] Full-text search
- [x] Microsoft OAuth2 / Graph send support
- [ ] Gmail-native `threadId` threading (currently RFC Message-ID based)
- [ ] Incoming IMAP flag sync over the IDLE stream
- [ ] Avatar fetching (once OAuth avatar scopes are wired)
- [ ] Nested label UI
