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
| Auth | Session login, TOTP two-factor (`scheb/2fa`), database-backed trusted devices |
| Dev tooling | Docker Compose, Adminer, Mailpit |
| Architectures | `linux/amd64` and `linux/arm64` |

## Development setup

Create your local compose override first — this is the step that switches you from the published
image to a dev stack built from source:

```bash
cp compose.override.yaml.dist compose.override.yaml
```

Then:

```bash
docker compose up --build
```

`compose.override.yaml` is deliberately **not** tracked, and `.gitignore` keeps it that way. Compose
auto-loads it whenever it exists, so a committed one would silently opt every *user* into building
from source instead of pulling the release image — which is exactly the thing they should never have
to do. Its `.dist` is the tracked template.

> **Changing the override? Change the `.dist` too.** They are meant to stay identical, and
> `diff compose.override.yaml.dist compose.override.yaml` should come back empty. The two drifted
> apart once before and it cost real data: the `.dist` was missing the `app_attachments`, `app_raw`
> and `app_uploads` mounts, so anyone starting from a fresh clone got attachment downloads that
> 404'd and blob data that vanished on container recreate. The `Dockerfile` comment about the
> removed `VOLUME /app/var/` explains why those mounts have to be declared per service.

There is nothing else to fill in: dependencies install themselves on first boot, the secrets are
generated on first start (see below), and the one setting with no sensible default — the address
plMail is reached at — is asked for on the setup screen. Open the app and it offers to create the
first administrator; `app:setup` does the same thing from a terminal.

That first boot takes a few minutes. The dev stack bind-mounts the source tree over `/app`, so the
`vendor/` baked into the image is hidden and a fresh clone has none; the entrypoint runs
`composer install` once to fill it, under a lock so the five services sharing the mount cannot race.
Later boots skip it.

Migrations run automatically via the entrypoint. The `imap-supervisor`, `messenger-worker` and
`scheduler` services start with the stack and restart on failure.

`.github/workflows/docker.yml` builds the published image for both `linux/amd64` and `linux/arm64`,
each on a native runner, then merges the two into one manifest. A change to the `Dockerfile` must
therefore hold on both — nothing may assume x86, and any binary fetched during the build has to
resolve an arm64 asset too. ARM is not a niche here: a NAS or a Raspberry Pi is squarely the kind of
machine plMail is meant to run on.

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

### How the browser suite stays fast

Three things carry almost all of it, and each is easy to undo by accident.

**Assets are compiled, not served through PHP.** `app-init` runs `asset-map:compile`, and the output
lives in the `app_public_assets_test` volume rather than on the host — the same overlay trick as
`app_var_test`, and for the same reason: the dev stack bind-mounts this directory, and a compiled
`public/assets/` there would be served stale after every source edit. Without the compile, Caddy's
`try_files {path} index.php` sends every asset request into a full Symfony kernel boot. `/login`
alone pulls 54 module preloads plus 2 stylesheets, measured at ~35ms each against ~1.6ms for a real
static file, and Playwright hands every test a cold cache. That one change took the suite from 255s
to 138s.

**Every worker owns its own user.** `tests/e2e/support/config.ts` derives `e2e-w{N}@plmail.test` from
Playwright's `TEST_PARALLEL_INDEX`, and the `app:test:*` commands take a matching `--email`
(`App\Command\Test\TargetsTestUser`). This is what makes `workers > 1` safe: `seed-mail` deletes every
thread on the account it seeds, which is fine per-user and catastrophic shared. Signing in is a
worker-scoped fixture in `tests/e2e/support/test.ts`, which is why specs import `test` from there and
not from `@playwright/test`.

`fullyParallel` is deliberately **false**. Files run in parallel, tests inside a file run in order —
which is what lets the integration describes and the two-factor spec keep depending on their own
ordering without declaring anything.

**`integrations.spec.ts` runs alone, last.** `IntegrationProviderConfig` and `MailProviderConfig` are
unique on `provider` with no user column, so that state is install-wide and no amount of per-worker
users isolates it. It has its own `chromium-exclusive` project with `dependencies` on the main one.

Two smaller things: `video` is off (the trace is the useful artifact and costs nothing on a passing
run), and nothing waits for a TOTP window to roll over — otphp accepts a code minted in window `W`
for any submission in `[30W-15, 30W+45)`, so the wait was superstition.

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

## Two-factor authentication

TOTP, through `scheb/2fa`, on the `main` firewall only. Opt-in per user, from Settings → Security or
the step the setup wizard offers.

`/jmap` is deliberately not covered. It authenticates third-party mail clients with app passwords,
which exist precisely because an IMAP or JMAP client cannot present a six-digit code; the way to
withdraw access there is the app password list, not this.

### Why "remember this device" is a table

scheb ships a trusted-device feature already, and plMail replaces its manager
(`App\Security\TwoFactor\DatabaseTrustedDeviceManager`, wired through `trusted_device.manager` in
`config/packages/scheb_2fa.yaml`). The stock one puts the whole grant in the cookie: a JWT holding a
username and a version, signed with `APP_SECRET`. That is stateless and fast, and it **cannot be
taken back** — a stolen cookie stays valid for its full lifetime, and the only revocation on offer is
bumping the version, which drops every device the user owns at once.

Here the cookie is an opaque 32-byte secret and the grant is a row in `trusted_device`, so the
settings page can list what is trusted and revoking one takes effect on that device's very next
request. The cost is one indexed lookup per request on a 2FA-enabled account.

Only a SHA-256 of the cookie secret is stored, on the same reasoning as `ApiToken`: CSPRNG output has
nothing to brute-force, and a digest can be matched by an indexed equality test instead of scanning
every row. The TOTP secret itself goes through the `encrypted_string` type, like every mailbox
password.

### Things that bite

**The TOTP parameters are not yours to tune.** Google Authenticator ignores the `algorithm` and
`digits` parameters in the `otpauth://` URI and assumes SHA-1 and 6 digits regardless. `User` hard-codes
those, and `TwoFactorEnrolmentTest` generates real codes with otphp to prove the configuration written
into the QR and the one validated against have not drifted apart. Get that wrong and enrolment scans
cleanly, then rejects every code, with nothing on either side saying why.

**`leeway` must stay below the 30-second period.** otphp throws
`The leeway must be lower than the TOTP period` for anything `>= 30` — not a narrower window, an
exception on *every* verification. It is 15.

**Adding an onboarding step needs `OnboardingController::STEP_PATTERN` updating.** A route requirement
is an attribute argument, so it cannot be derived from the enum. A missing case does not just 404: the
progress rail generates a URL for every applicable step, so one omission takes down every page of the
wizard. `OnboardingStepCoverageTest` checks both that and the handler.

**Programmatic login must name its authenticator.** The `main` firewall has two now, and
`Security::login()` refuses to guess — see `InstallController`.

### Getting back in

Losing the phone and the recovery codes is recoverable, because plMail runs on hardware its user owns:

```bash
docker compose exec php php bin/console app:user:2fa-disable you@example.com
```

This is not exposed to administrators through the web UI on purpose. An admin who could strip another
user's second factor from a browser would be a second way into every mailbox on the install, reachable
with nothing but a stolen admin session.

## Health

`GET /healthz` — unauthenticated, because Docker healthchecks and uptime
monitors hold no session. It reports whether the database is reachable, whether
the queue is draining, and whether every process that has ever reported a
heartbeat is still beating.

503 only when the database is down, since that is the one failure where serving
is impossible. A backed-up queue stays 200 on purpose: mail is late, not gone,
and restarting the container would not help.

It answers with verdicts and nothing else — no counts, no addresses, no
version. Anyone who can reach the port can read it, so it must never become a
place to learn about the instance; `/admin` is where the numbers live, behind
`ROLE_ADMIN`. `HealthTest` asserts that shape, so an addition that leaks
something fails the suite.

The image's `HEALTHCHECK` points here. It used to probe Caddy's metrics port,
which answers as soon as the web server is listening — well before PHP can
reach the database — so a stack with an unreachable database reported itself
healthy and `depends_on: service_healthy` waited for nothing. The worker
services have no HTTP server and disable the healthcheck; their liveness
reaches this endpoint through heartbeats instead.

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
| `app:user:2fa-disable <email> [--force]` | Turn off 2FA for someone locked out — see "Two-factor authentication" |
| `app:monitoring:prune [--days=N]` | Prune old log entries and dead process heartbeats |
| `app:backup [dir] [--skip-secrets] [--skip-storage]` | Write a restorable snapshot: `pg_dump`, the stored files, and the generated secrets. Says explicitly when `APP_ENCRYPTION_KEY` is *not* in it |
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

- [x] Complete the label-based architecture refactor (Label as the user-facing concept; Mailbox demoted to IMAP sync infrastructure)
- [x] Sanitize rendered HTML bodies
- [x] Full-text search
- [x] Microsoft OAuth2 / Graph send support
- [x] Nested label UI — `Label::$parent`, `LabelRepository::findVisibleTreeForUser()`, and the recursive tree in `_partials/_sidebar.html.twig`
- [x] Snooze — `ThreadSnoozeService`, `app:mail:wake-snoozed`, and the `Thread/set` JMAP extension
- [ ] Gmail-native `threadId` threading (the id is carried on `Message`, but threading is still RFC Message-ID based)
- [ ] Incoming IMAP flag sync over the IDLE stream — flags currently travel outward only, so marking a message read in another client does not reflect back
- [ ] Avatar fetching (partially done: `Service/User/AvatarFromIntegration.php`; still needs OAuth avatar scopes)
- [ ] Per-identity signatures — no `signature` field exists on `User`, `Account` or `EmailAlias`
- [ ] Password reset (recovery is console-only today, which is why the login form no longer offers a link)

> Keep this honest. Three items above were finished long before anyone ticked
> them, and a roadmap that under-reports is worse than none — it is the document
> a contributor reads to decide what to pick up.
