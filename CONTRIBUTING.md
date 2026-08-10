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
touch the dev stack or the database holding your mail. Playwright runs on the host; the repo
ships an `.nvmrc` and CI reads it, so that file is the one place the version is set.

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
| `npm run test:e2e:docker:ui` | Playwright's watch UI — pick a test, step it, inspect the DOM at each step |
| `npm run test:e2e:docker:headed` | The same run with a visible browser window |
| `npm run test:e2e:docker:trace` | Headless, but recording a trace and a video of every test — `npm run test:e2e:report` to open them |
| `npm run test:env:up` | Start the test stack (migrates, builds assets, seeds the E2E user) |
| `npm run test:env:down` | Stop it, keeping the database volume |
| `npm run test:env:reset` | Stop it and delete its volumes — next run rebuilds from scratch |
| `npm run test:env:logs` | Tail the test app's logs |

Every one of these talks to the compose stack — there is no second way to serve the app any more.
The `:docker:` variants simply bring the stack up first; `test:e2e`, `test:e2e:ui` and
`test:e2e:headed` skip that and expect one to be running already, so `npm run test:env:up` once and
then `npx playwright test` as often as you like is the fast loop. Playwright never starts a server
itself: it used to be able to start a `php -S` behind a router script, and everything that server
needed to behave — its own front controller, its own copy of the environment, its own php.ini, a
restart loop for when it segfaulted — was work spent on a server nobody is ever served by.
Watching a run on WSL needs WSLg (Windows 11) or an X server; without one, use `:trace` and read the
recording after.

Running a second stack — a worktree, or a second port — means overriding two variables together:

```bash
TEST_HTTP_PORT=8006 docker compose -p my_stack -f compose.test.yaml up -d --build --wait app
E2E_BASE_URL=http://127.0.0.1:8006 \
  E2E_COMPOSE="docker compose -p my_stack -f compose.test.yaml" \
  npx playwright test
```

`E2E_COMPOSE` is the one the specs need: seeding goes through `docker compose exec`, and
`mercure.spec.ts` stops the hub container to prove the stream recovers. Without it those commands
address the default project, and `stop` against a project that does not exist reports success.

The test app is served at `http://127.0.0.1:8001` (override with `TEST_HTTP_PORT`). Individual specs
reseed their own fixtures, so tests are independent and re-runnable.

CI runs the same suites the same way — it boots this very stack from `compose.test.yaml` and then
runs `npm run test:unit:docker` and the Playwright suite against it, so "works locally, fails in CI"
no longer has a serving path to hide in. See
[.github/workflows/e2e.yml](.github/workflows/e2e.yml). It runs **only on a release tag, on a pull
request, or when you start it by hand:** it is the expensive workflow in this repository, and
running it on every commit to `main` spent most of the account's capacity proving the same thing
over and over.

The consequence is worth being explicit about: **`main` is not verified commit by commit.** A
regression pushed there is found when a version is tagged, which may be several commits later, and
the tag is then what fails. So run the suites locally before pushing anything you are unsure of —
`npm run test:unit:docker && npm run test:e2e:docker` — which is faster than waiting for a runner
anyway. `workflow_dispatch` on the Actions tab is the other way to get a full run without tagging.

A tag starts the E2E workflow and the image build **in parallel**, so a failing suite does not stop
the image being published. Tag from a tree you have already run the suites against.

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
(`App\Command\Test\TargetsTestUser`). Passing it is not optional in a spec that asserts on the
*absence* of something: `calendar-sharing.spec.ts` seeds through `app:test:seed-share-event
--email=…`, and without the option the event lands on a different user, the shared page comes back
empty, and every "must not contain" assertion passes for entirely the wrong reason. This is what makes `workers > 1` safe: `seed-mail` deletes every
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
npx playwright test screenshots.spec.ts --project=chromium --workers=1
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

## Calendar push

Google and Microsoft calendars can arrive by push rather than waiting for the
fifteen-minute sweep. Both notifications are content-free — they say "something
in this calendar changed" and nothing about what — so a webhook does exactly one
thing, dispatch `SyncCalendarMessage` for the calendar it names, and every
decision stays in the sync engine.

| | Google Calendar | Microsoft Graph |
|---|---|---|
| Mechanism | `events.watch` channel, a plain webhook | `/subscriptions` over `me/calendars/{id}/events` |
| Endpoint | `POST /webhook/google/calendar` | `POST /webhook/graph/calendar` |
| Proof | channel token in `X-Goog-Channel-Token` | `clientState` in the body |
| Lifetime | a week, whatever Google grants | just under three days |
| Renewal | re-register, then stop the old channel | `PATCH` the expiry |
| Teardown | `channels/stop` with (id, resourceId) | `DELETE /subscriptions/{id}` |

**Push is never load-bearing.** A self-hosted install may have no publicly
reachable HTTPS address at all, and registration failing means "stay on
polling", not "error" — `app:calendar:sync --stale` runs every fifteen minutes
regardless and is unchanged by any of this.

### Things that bite

**Google will not deliver to an unverified domain.** The callback host has to be
verified in the Cloud project that owns the OAuth client: verify it in Search
Console, then add it under Domain verification in the Cloud console. Until then
every `events.watch` is refused, which is at least visible — it is a warning in
the log at registration rather than a channel that silently never delivers.
Microsoft has no equivalent requirement. This is also why none of the
`GMAIL_PUBSUB_*` configuration applies here: calendar push is a plain webhook,
not the Pub/Sub path Gmail push takes, and an install with no Pub/Sub at all can
have calendar push.

**Two things register a channel, and both are needed.** Ticking a calendar to
mirror it dispatches a `RegisterCalendarPushMessage`, so a calendar connected at
ten past does not wait fifty minutes for its first channel. `app:calendar:push`,
which the scheduler runs hourly, is the retry rather than the only way in:
registration fails for deployment reasons that have nothing to do with the click
— no public HTTPS address yet, a Cloud project whose domain verification is
pending — and a subscribe that failed for one of those must not leave the
calendar polling for ever. Driven from the sweep as well, the same install
starts pushing within the hour of the address or the verification being fixed.

**The first Google notification after registering is a `sync` handshake** and
means only "the channel is open". Acting on it would put a full calendar read in
the queue for every registration and every renewal in the install.

**`APP_PUBLIC_URL` is the address both providers call back to**, and it is read
per call rather than injected, because the workers that register channels are
long-running and the address is usually saved from the setup screen after they
booted.

## Calendar alerts

An alert is stored where RFC 8984 puts it — in the event's own JSCalendar object
under `alerts`, as a map of `{ trigger, action }` — and nowhere else. There is no
column for it and nothing queries one, which is the point: an alert round-trips
to Google, Microsoft Graph and CalDAV because it is kept in the vocabulary all
three convert to, rather than in a plMail-only field the mappers would have to
learn about one at a time.

`app:calendar:alerts` is what fires them, every minute. It reads
`calendar_event_occurrence` — the same rows every calendar view reads — so a
recurring event produces one alert per occurrence for free, and an instance
somebody moved or cancelled is already moved or cancelled in that table. Nothing
in the alert path re-reads `recurrenceOverrides`, deliberately: a second reading
of it would be a second opinion about where a meeting is.

| | Where it lives |
|---|---|
| The alerts on an event | `jscalendar.alerts`, keyed by trigger and action |
| Reading and building them | `App\Service\Calendar\Alert\AlertReader` |
| What is due right now | `App\Service\Calendar\Alert\DueAlertReader` |
| Sending it, exactly once | `App\Service\Calendar\Alert\AlertDeliverer` |
| A notification | `PushAlertChannel`, over the existing JMAP Web Push stack |
| A reminder mail | `EmailAlertChannel`, through `MailSenderRegistry` |
| The record that it fired | `calendar_alert_delivery` |

### Things that bite

**An alert fires exactly once because the database says so, not because the code
remembers.** `AlertDeliverer` writes the delivery row *before* it sends
anything, in one `INSERT … ON CONFLICT DO NOTHING`, and whether that insert
happened is the same question as whether this process is the one that sends.
Both obvious alternatives lose: send-then-record leaves a window in which a
killed container re-sends on the next sweep, and read-then-insert lets two
overlapping sweeps both decide to send. The consequence is that a delivery which
*fails* is lost rather than retried — chosen deliberately, because "your meeting
starts in ten minutes" is not true fifteen minutes later.

**Turning this on must not deliver a year of history**, and the thing that stops
it is not the table — that is empty on a fresh install either way. It is
`DueAlertReader::LOOKBACK`: a trigger more than an hour old is not due and never
will be. That is also the bound on how much downtime the sweep can catch up
from. Anything that shortens the prune cutoff in `CalendarAlertsCommand` below
that hour re-introduces double firing.

**The occurrence horizon is the alert horizon.** `RecurrenceMaterialiser` only
writes occurrences within a bounded window around now, so an alert on next
decade's birthday exists and does not fire until the nightly sweep rolls the
horizon far enough forward. `MAX_LEAD` in `DueAlertReader` is the matching bound
at the other end: an alert set more than thirty-one days ahead of an event never
fires, because the alternative is a candidate query with no upper bound scanning
the whole table every minute.

**Removing every alert does not clear the reminders at Google.** Google cannot
distinguish "this event has no reminders" from "this event uses the calendar's
defaults" in a way plMail can round-trip, so `reminders` is written only when
there is at least one local alert. Graph has no such ambiguity — `isReminderOn:
false` is written on every push, so clearing an alert here clears it in Outlook.
Both decisions are argued in the mappers' own docblocks.

**A user with no subscribed device and no mail account is a normal state**, not
an error: the alert is claimed, a warning is logged, and nothing is retried.
Leaving the claim off would mean the same impossible delivery attempted sixty
times an hour for the length of the lookback window.

## Sharing a calendar, and letting people book it

Two features that share one mechanism and one settings section: a **shared link**
shows part of a calendar to somebody with no account, and a **booking page**
lets them take an hour of it. Both are gated by a token in the URL and nothing
else, and both live under Settings → Sharing.

Read `App\Service\Calendar\Sharing\PublicLinkToken` before touching either.

### The token is a credential and is not stored

`calendar_share_link.token_digest` and `booking_page.token_digest` hold a
SHA-256, never the token. The same reasoning `DevicePairingService` gives for
hashing its pairing codes, and it gets stronger here rather than weaker: a
pairing code is dead in two minutes, and a share link is alive until it is
revoked — so a database dump would otherwise be a set of working URLs into
somebody's diary for as long as they left the links up.

**The cost is that the address is shown exactly once, when it is created.** There
is no screen that can show it again, because nothing stored can reconstruct it.
The recovery is "regenerate", which mints a new token and makes the old URL 404 —
which is the right thing to do with a URL that went missing anyway. This is the
first thing somebody will try to "fix"; the second is adding a copy button to
the row, which cannot work.

### What a shared link reveals

Busy/free is the floor and has no checkbox. `ShareDetail` is the set of things a
link adds on top — title, location, description, participants — stored as a
jsonb list and edited freely afterwards, because narrowing a link must not
require re-sending it.

**The redaction is a DTO, not a set of `if`s in a template.** `ShareLinkReader`
returns `SharedOccurrence`, whose fields are already null where the link revealed
nothing, and the public templates never receive a `CalendarEvent` at all. That
is what makes "a busy/free link cannot leak a title" a property of the code
rather than a thing to remember: there is no tooltip, data attribute, JSON
payload or `.ics` that could carry one, because the object being rendered has
not got one. `SharedCalendarLeakTest` asserts it over the whole response body.

**`EventPrivacy` is the ceiling and the checkboxes are the floor.** A meeting
marked `Private` is a plain busy block whatever the link says; one marked
`Secret` does not appear at all, because its existence is the detail. The link is
a decision about an audience and the privacy is a decision about one meeting, so
the narrower wins — otherwise the wider is a way to undo it in bulk.

### How double-booking is stopped

By `uniq_calendar_booking_page_start` and by nothing else. Two people pressing
Book on the same half hour at the same instant both read the slot free, because
both read before either wrote; narrowing that window makes the bug rarer, which
is the worst property a bug of this kind can have. The database is the only
participant that sees both requests.

`BookingService` is written around the refusal rather than around a check: the
event and the booking go in **one flush**, so the constraint's rejection takes
the event with it, and the loser gets `BookingSlotTakenException`. By then
Doctrine has closed the EntityManager, which is why the controller answers with a
**redirect** — a fresh request rebuilds the slot list from what is true now.

### Things that bite

**A slot is a wall clock, not an instant.** `BookingSlotGenerator` builds each
slot from local midnight plus an offset in the owner's zone. The obvious version
— take the previous slot and add the slot length — is wrong twice a year: in
spring two adjacent local times collapse onto one instant and the page offers the
same appointment twice, and in autumn an hour repeats and the day silently gains
or loses slots. Both are covered by `BookingSlotGeneratorTest`, which uses
Europe/Berlin because its transitions are at 02:00 and a 09:00–17:00 fixture
would never touch them.

**A cancelled or moved event frees its slot with nothing to invalidate.**
`BookingAvailabilityReader` asks the occurrence table on every request, so
calling a meeting off brings its hour back on the next page load and dragging one
to Thursday moves the hole with it. There is no cache here on purpose.

**The destination calendar is always checked for busy-ness**, ticked or not —
`BookingPage::calendarsToCheck()` enforces it. A page whose destination was not
in its own busy set would double-book itself on the second request.

**The public POST is rate limited and the GET is not.** The POST creates rows and
sends mail, which is the definition of a spam vector, and the token does not help
because the abuser holds the same URL. The GET is deliberately unbounded: a limit
there would let one stranger take a published page off the internet by refreshing
it. See the essay in `config/packages/rate_limiter.yaml`.

**The public pages have their own layout and it must stay that way.**
`templates/sharing/_layout.html.twig` does not extend the app layout, because
that one renders `csrf_token('ajax')` into a meta tag — which starts a session,
on every fetch of a public URL, forever. There is consequently no CSRF token on
the booking form; `App\Controller\Sharing\BookingController` argues why that is
the right trade.

**A form POST must redirect.** Turbo is on those pages — the layout loads the
application bundle for its stylesheet — and Turbo refuses to render a 200
answered to a form post, leaving the browser sitting on the form it just
submitted. The successful booking redirects to `/book/{token}/booked`. A browser
spec found this; `BookingEndpointTest` is what keeps it found.

**A booked event is `EventSource::Booking`**, not `Manual` and not an
`ExtractionKind`. It is the only kind of event a person outside the install can
cause to appear, which is a question somebody will ask the first time a page is
abused, and a query can only answer it if it was written down at the time. The
booker's own details live on `CalendarBooking` rather than on the event — and
deliberately not as `participants`, because pushing an attendee list to a
provider is how the provider decides to mail a stranger a meeting request.

## Documentation

Four files and a directory, each with one audience, and the split is load-bearing —
`README.md` never explains internals and this file never explains what the product is.

| Where | Audience | Contains |
|---|---|---|
| `README.md` | Someone deciding whether to run it | What it is, what it can do, how to install it |
| `CONTRIBUTING.md` | Someone changing it | Setup, tests, architecture notes, the command reference, the roadmap |
| `CHANGELOG.md` | Someone upgrading | What changed per release, and what it costs them |
| `CODESTYLE.md` | Someone writing in it | The conventions, and why each exists |
| `docs/` | Someone using or operating it | The handbook: every feature, installing on each platform, registering the Google and Microsoft applications, and how the parts work underneath |

`docs/` is published twice, and both are generated on every push to `main`:

| Where | Built by | What it is for |
|---|---|---|
| [The site](https://karatektus.github.io/pl_mail/) | `bin/build-site.php`, `.github/workflows/pages.yml` | A landing page and the handbook, for somebody who has never seen plMail. The landing page **is** `README.md`; the handbook **is** `docs/` |
| [The wiki](https://github.com/karatektus/pl_mail/wiki) | `bin/mirror-wiki.php`, `.github/workflows/wiki.yml` | The same handbook, where somebody already inside GitHub looks for it |

**Both are outputs and neither is a source.** An edit made in the wiki's browser editor
survives until the next push and is then overwritten — every generated page says so in
its footer — and the site has no editor at all. Authoring lives in the repository so that
a change and the paragraph describing it are in one commit and one review; documentation
that is not versioned with the code drifts from it, and the drift is invisible until
somebody follows an instruction that stopped being true.

Build the site locally, exactly as CI does:

```bash
composer site && php -S 127.0.0.1:8080 -t build/site
```

Its two dropdowns are generated rather than configured. The themes come from
`App\Domain\Enum\Theme\Theme::swatch()`, the same source the app's own appearance
picker reads, so adding a theme to plMail adds it to the site. The language picker lists
a locale as available when `docs/<locale>/` exists and as "not translated yet" when it
does not — **so translating the handbook is creating that directory and nothing else.**
Nothing needs registering, and the enforcement test treats a translated page like any
other.

### Translating the handbook

A translation is `docs/<locale>/` with the same paths as the English tree — `docs/de/features/mail.md`
translates `docs/features/mail.md`. Any subset works: the site renders a language from the **English**
page list and falls back to the English page for anything not yet translated, saying so in a banner.
So the first page translated is useful immediately, and deleting a bad translation is always a safe fix.

Every translated file opens with a marker naming what it was made from:

```markdown
<!-- translated-from: features/mail.md sha1:0401c696430a39313d3fa6363bc227e1c41c2d8e -->
```

`DocumentationCoverageTest` re-hashes the English file and fails when the two disagree. That is the
one check here that is about truth rather than inventory, and it gets at it sideways: nobody can test
whether a German paragraph still describes the code, but a translation made from a page that has
since changed is a claim about the past, and that is checkable. A stale translation is worse than a
missing one — a missing page falls back and admits it, a stale page is fluent and wrong.

Update a translation with `sha1sum docs/features/mail.md` after re-reading it.

**What is never translated:** code blocks, commands, file paths, route paths, environment variable
names, class and method names, HTTP headers, RFC names, and the labels of buttons and settings as
they appear in the interface — those are quoted so a reader can find them on screen, and the screen
is only translated where plMail itself is. The `## Things that bite` heading has a fixed translation
per language, listed in the test, because the test looks for it.

**Register: the German handbook duzt.** "du", "dich", "dir", "dein" — never "Sie" or "Ihr", and
lowercase "du" rather than the capitalised form. Imperatives take the informal ending: *Öffne die
Einstellungen*, not *Öffnen Sie die Einstellungen*. plMail is something a person runs for themselves
on their own machine, and a handbook that sounds like enterprise procurement documentation is
describing a different product.

Two things hide the register and are worth checking for after a draft: passive voice reached for to
avoid addressing the reader at all, and "man" standing in where "du" reads better. Mixing the two
forms is worse than either consistently, so a page converted halfway is a page not yet converted.

**German glossary**, so a handbook translated by several people does not invent several words for one thing:

| English | German |
|---|---|
| mailbox / account | Postfach / Konto |
| thread, conversation | Konversation |
| label | Label |
| snooze | zurückstellen |
| draft | Entwurf |
| attachment | Anhang |
| filter, rule | Filter, Regel |
| calendar event | Termin |
| occurrence | Termininstanz |
| recurring event, series | Serientermin, Serie |
| invitation | Einladung |
| reminder, alert | Erinnerung |
| connected calendar | verbundener Kalender |
| share link | Freigabelink |
| booking page | Buchungsseite |
| busy / free | Belegt / Frei |
| push | Push |
| sweep | Durchlauf |
| handbook | Handbuch |
| Things that bite | Fallstricke |

### Keeping it true is part of the change, not a follow-up

A change that alters what a user does, what an operator sets, or what a provider must be
configured with, updates `docs/` **in the same commit**. This is not an aspiration;
`tests/Documentation/DocumentationCoverageTest.php` fails the build on the part of it a
machine can check:

- every `app:` command that is not an `app:test:` fixture appears in the table below;
- every variable assigned in `.env` appears in `docs/install/configuration.md`, which is
  the one page an operator is entitled to treat as complete;
- every relative link in `docs/` resolves;
- every page is linked from `docs/README.md`, and every page the index promises exists;
- every page carries a `## Things that bite` section.

What it cannot check is whether a paragraph is still *true*. That remains a matter of
doing the work, and the checks above exist so the reviewer's attention is spent on it
rather than on spotting a missing row.

Render the wiki locally without pushing anything:

```bash
php bin/mirror-wiki.php --check
```

## Console commands

| Command | Description |
|---|---|
| `app:setup` | Create the first admin user (interactive) |
| `app:mail:sync [account-id]` | Dispatch an account-level sync for one or all active accounts |
| `app:mail:send-draft [message-id]` | Send a draft message (picker if no ID given) |
| `app:mail:test-connection` | Probe an account's IMAP/SMTP settings |
| `app:contacts:harvest [account-id]` | Harvest contact addresses from synced messages |
| `app:calendar:sync [calendar-id] [--stale]` | Dispatch a two-way sync for connected calendars; `--stale` is the sweep the scheduler runs |
| `app:calendar:push [calendar-id] [--force] [--stop]` | Register and renew Google/Microsoft calendar push channels, so changes arrive instead of being polled for |
| `app:calendar:alerts [--dry-run]` | Deliver the event reminders that have come due, and prune the records of ones long past. Runs every minute; `--dry-run` lists what is due without sending or recording anything |
| `app:mail:wake-snoozed` | Return snoozed conversations whose time has come. Runs every minute |
| `app:calendar:materialise [--dry-run]` | Redraw the occurrences of recurring events whose horizon no longer reaches far enough. Runs nightly; without it a long-untouched series eventually runs out of dates |
| `app:backfill [task]` | Run a one-off backfill over stored data; with no argument it lists the tasks and asks. `events` re-runs calendar extraction, `proposals` re-reads mail for dates written in prose |
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
| `app:monitoring:prune [--days=N] [--push-days=N]` | Prune old log entries, push deliveries and dead process heartbeats |
| `app:backup [dir] [--skip-secrets] [--skip-storage]` | Write a restorable snapshot: `pg_dump`, the stored files, and the generated secrets. Says explicitly when `APP_ENCRYPTION_KEY` is *not* in it |
| `app:reset` | Truncate synced data — useful during development |
| `app:reset --full [--rotate-secrets]` | Back to first-run state: every table, every user, the stored files. `--rotate-secrets` also discards the generated secrets and requires restarting the whole stack — see "Secrets and the encryption key" |
| `app:secrets:init` | Generate the per-install secrets that need PHP, and verify the encryption key against stored credentials |
| `app:db:migrate` | Run pending migrations under a lock, so several containers booting together cannot collide. This is what the entrypoint calls; run it by hand only when a boot was interrupted |
| `app:device:pair <email>` | Issue a short-lived pairing code so a device can enrol itself — the way in when a client cannot complete a browser sign-in. See "Two-factor authentication" |

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
- [x] Calendar alerts — RFC 8984 `alerts` on the event, `app:calendar:alerts` every minute, delivered over the existing Web Push stack or as mail; see "Calendar alerts"
- [ ] Gmail-native `threadId` threading (the id is carried on `Message`, but threading is still RFC Message-ID based)
- [ ] Incoming IMAP flag sync over the IDLE stream — flags currently travel outward only, so marking a message read in another client does not reflect back
- [ ] Avatar fetching (partially done: `Service/User/AvatarFromIntegration.php`; still needs OAuth avatar scopes)
- [ ] Per-identity signatures — no `signature` field exists on `User`, `Account` or `EmailAlias`
- [ ] Password reset (recovery is console-only today, which is why the login form no longer offers a link)

> Keep this honest. Three items above were finished long before anyone ticked
> them, and a roadmap that under-reports is worse than none — it is the document
> a contributor reads to decide what to pick up.
