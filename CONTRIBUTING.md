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
docker compose up --build
```

There is nothing to fill in first: the secrets are generated on first start (see below), and the one
setting with no sensible default — the address plMail is reached at — is asked for on the setup
screen. Open the app and it offers to create the first administrator; `app:setup` does the same
thing from a terminal.

Migrations run automatically via the entrypoint. The `imap-supervisor`, `messenger-worker` and
`scheduler` services start with the stack and restart on failure.

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

## Secrets and the encryption key

plMail generates its own secrets on first start rather than shipping working ones, and one of them
is load-bearing enough to be worth understanding before you touch this area.

### Where they come from

`frankenphp/generate-secrets.sh` mints anything not already supplied, into a single file on a volume
**every** service mounts:

```
var/secrets/generated.env
```

It runs twice over: as the `secrets-init` compose service, before anything else starts — Postgres and
Mercure read their secrets at container-create time and cannot wait for the app — and again from the
app entrypoint, so a stack assembled without that service still comes up. Generation is idempotent
and takes a `flock`, so four containers starting at once mint each value once between them.

`APP_STORAGE_DIR` decides where blobs live relative to the project root — attachments, raw messages,
uploads and avatars all sit under it, and `var` by default. It exists so a deployment can put the
whole lot on one mount: `truenas.compose.yaml` sets it to `var/data` and binds a single host
directory there, which is why that file has one path to fill in rather than five. Attachment paths
are stored relative to the project root, so changing it on an install with mail in it orphans what
is already there.

`config/bootstrap_generated_secrets.php`, loaded through composer's `autoload.files`, makes those
values visible to PHP started any other way — `docker compose exec … bin/console` bypasses the
entrypoint entirely.

**Anything you set explicitly wins.** Nothing is ever generated over the top of a supplied value.

> `docker compose` reads the project's `.env` to resolve `${VAR}`, so a value put there becomes a
> real environment variable in every container and switches off generation for it. That is why
> `APP_SECRET`, `APP_ENCRYPTION_KEY`, `DATABASE_URL` and `MERCURE_JWT_SECRET` are blank in the
> committed `.env`, with a banner saying so. Putting a working secret back defeats the whole
> arrangement.

### Why APP_ENCRYPTION_KEY is different

It is the libsodium key behind the `encrypted_string` Doctrine type: every mailbox password and
OAuth refresh token in the database. Two things follow, and both have bitten:

**Every service must hold the same one.** They run the same image with no shared `var/`, so the key
lives on the `app_secrets` volume and each service mounts it. A service missing that mount mints its
own and quietly stops being able to read what the others wrote. `App\Infrastructure\Setup\EncryptionKeyProbe`
runs at container start and refuses to boot the server rather than write data half the fleet cannot
read.

**It cannot be rotated underneath a running stack.** The other services keep the old key in process
memory until they restart, so for a while half of them cannot read what the other half writes. This
is why `app:reset --full` leaves the secrets alone by default and `--rotate-secrets` is a separate,
loudly warned flag that requires restarting everything immediately afterwards.

`POSTGRES_PASSWORD` is never rotated by the reset at all: Postgres was initialised with it and keeps
its own copy, so a new one locks the app out of the database it just reset. Changing that means
wiping the database volume.

### When the keys disagree

The symptom is a decrypt failure — a worker logging
`Could not decrypt encrypted_string column`, or the server refusing to start with the probe's
message. Recovering the data means putting the original key back; there is no other way.

If the unreadable data is expendable, clear it. The probe is deliberately **fatal only when starting
the server** — a console invocation warns and continues, because refusing it would block the very
command that repairs the situation:

```bash
docker compose run --rm --entrypoint docker-php-entrypoint php php bin/console app:reset --full
```

Then `docker compose down && docker compose up -d`, so every service comes up on the one key in the
file, and create the first administrator again.

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
| `app:prune:blobs [--days=N] [--dry-run]` | Expire staged JMAP uploads and delete files orphaned by deleted rows |
| `app:user:promote <email> [--revoke]` | Grant or revoke `ROLE_ADMIN` |
| `app:monitoring:prune [--days=N]` | Prune old log entries and dead process heartbeats |
| `app:reset` | Truncate synced data — useful during development |
| `app:reset --full [--rotate-secrets]` | Back to first-run state: every table, every user, the stored files. `--rotate-secrets` also discards the generated secrets and requires restarting the whole stack — see "Secrets and the encryption key" |
| `app:secrets:init` | Generate the per-install secrets that need PHP, and verify the encryption key against stored credentials |

These run on a schedule already — see `App\Infrastructure\Scheduler\MaintenanceSchedule`
for the cadences (polling sync every 15 min, push renewal and monitoring pruning
nightly, the blob sweep weekly). They are dispatched by the `scheduler` service in
compose, which consumes the `scheduler_default` transport; without that container
running, none of them fire. `php bin/console debug:scheduler` shows the next run of
each.

## Roadmap

- [ ] Complete the label-based architecture refactor (Label as the user-facing concept; Mailbox demoted to IMAP sync infrastructure)
- [x] Sanitize rendered HTML bodies
- [x] Full-text search
- [x] Microsoft OAuth2 / Graph send support
- [ ] Gmail-native `threadId` threading (currently RFC Message-ID based)
- [ ] Incoming IMAP flag sync over the IDLE stream
- [ ] Avatar fetching (once OAuth avatar scopes are wired)
- [ ] Nested label UI
