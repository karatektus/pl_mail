# Installing with Docker Compose

The supported path, start to finish: what you need, what `docker compose up` actually does, how the
first administrator gets created, and what each of the containers is for.

Everything below assumes the stock `compose.yaml` from the repository, which pulls the published
image. Building from source is a development setup and belongs in
[CONTRIBUTING](../../CONTRIBUTING.md#development-setup).

## What you need

- A machine running Docker and Docker Compose.
- Nothing else. There is no configuration to fill in before the first start.

Images are published for `linux/amd64` and `linux/arm64`, each built on a native runner and merged
into one manifest, so an Apple Silicon Mac, an ARM NAS or a 64-bit Raspberry Pi runs plMail without
emulation. Which machine you are on changes a few details — see [Platform notes](platforms.md).

Sizing is worth a thought rather than a rule. PHP's `memory_limit` is `2G` in the image, each of the
four Messenger workers is capped by `--memory-limit=256M` and recycles on `--time-limit=3600`, and
Postgres, the Mercure hub and the IMAP supervisor each want their own share on top. A first sync of
a large mailbox is the busiest this ever gets.

That is enough for IMAP mailboxes. Gmail and Outlook additionally need OAuth credentials — see
[Google](../providers/google.md) and [Microsoft](../providers/microsoft.md).

**The failure mode is expecting a mail server.** plMail is a client. It does not receive mail from
the outside world, does not host your domain, and needs no port 25 anywhere.

## Getting it

```bash
git clone https://github.com/karatektus/pl_mail.git
cd pl_mail
```

The clone is for `compose.yaml` and nothing else — the application code lives in the image. If you
would rather not clone, copying `compose.yaml` on its own is enough, as long as you also create a
`.env` beside it or accept every default in the [configuration reference](configuration.md).

**The failure mode is a stray `compose.override.yaml`.** Compose loads that file automatically
whenever it exists, and the one in this repository switches every service from the published image
to a locally built dev image with the source bind-mounted. It is untracked for exactly that reason;
only `compose.override.yaml.dist` is committed. Do not copy it unless you are developing.

## Starting it

```bash
docker compose up -d
```

Then open [https://localhost](https://localhost).

The first boot takes longer than later ones, and it does a specific sequence of things worth
knowing about:

1. **`secrets-init` runs and exits.** It mints `APP_SECRET`, `APP_ENCRYPTION_KEY`,
   `POSTGRES_PASSWORD` and `MERCURE_JWT_SECRET` into `var/secrets/generated.env` on the shared
   `app_secrets` volume, plus a bare `postgres_password` file. It runs before everything else
   because Postgres and Mercure read their secrets when their containers are created and cannot
   wait for the app to hand them one. On every later start it finds the file and does nothing.
2. **`database` starts** and reads its password through `POSTGRES_PASSWORD_FILE`. Every app service
   waits on its healthcheck, which allows a 60-second start period.
3. **Each app container runs the same entrypoint.** It loads the generated secrets, assembles
   `DATABASE_URL` from `POSTGRES_PASSWORD` unless you supplied a DSN with a password of your own,
   waits up to 60 attempts for the database, then runs `app:db:migrate` — Doctrine's migrate under a
   Postgres advisory lock, so the six containers booting together cannot collide.
4. **`app:secrets:init` runs**, after migrations. It verifies that the encryption key in force can
   decrypt the credentials already stored, then generates a VAPID keypair and the JMAP JWT keypair
   if they are missing.
5. **Caddy serves.** The `php` container publishes 80/tcp, 443/tcp and 443/udp — remappable with
   `HTTP_PORT`, `HTTPS_PORT` and `HTTP3_PORT` — and serves the names in `SERVER_NAME`, which
   `compose.yaml` sets to `localhost, php:80`. It also proxies `/.well-known/mercure*` to the hub
   container, so the browser reaches the hub same-origin and needs no CORS.

`docker compose up -d --wait` blocks until the healthchecks pass, which is the better form in a
script.

**The failure mode is reading the logs of the wrong container.** Six services run the same image and
all six migrate on boot; the ones that lost the race for the advisory lock print "another container
is running migrations" and are not the ones to debug.

## Creating the first administrator

Open the app and it offers to create one. The `/install` page asks for a name, an address, a
password and the public URL plMail will be reached at — prefilled with the address you opened it
on, which is right whenever you are reaching it the same way its users will.

The same thing from a terminal:

```bash
docker compose exec php php bin/console app:setup
```

`/install` is unauthenticated because there is nobody to authenticate. It is guarded by exactly one
thing — the install having no users — checked when the page renders, when the form is submitted,
and a third time inside the locked write. The moment a user exists the page 404s for good.

The public URL you give is written to `var/secrets/generated.env` rather than to a config file,
because a long-running worker building a push subscription has no request to infer a hostname from.
Saving it also asks every worker to restart so they pick it up rather than holding the old value
until they recycle. An `APP_PUBLIC_URL` supplied through the environment wins over what the screen
stored, so a deployment that sets it is untouched by this.

**The failure mode is answering the public-URL question with `https://localhost`.** It is accepted,
and it is what the prefill offers when you set plMail up on the machine it runs on — but Google and
Microsoft will never reach a loopback address, so calendar and mail push stay off. Fix it later
from the environment, or reach the setup page through the address you actually intend to use.

## What each container is for

| Service | What it runs | Why it is its own container |
|---|---|---|
| `secrets-init` | `generate-secrets`, then exits | Postgres and Mercure need their secrets at container-create time, before the app exists |
| `php` | FrankenPHP serving the app | The only app service with an HTTP server, and therefore the only one whose image healthcheck is left enabled — the others disable it and report liveness through heartbeats instead |
| `database` | `postgres:18-alpine` with `pg_stat_statements` preloaded | — |
| `mercure` | The Mercure hub | Live updates — the mail list refreshing by itself |
| `imap-supervisor` | `app:imap:supervise` | Spawns and watches one `app:imap:idle` process per IDLE-enabled mailbox, so standard IMAP mail arrives the moment it lands |
| `worker-export` | `messenger:consume export` | Anything leaving plMail, and the only queue somebody is watching. On its own process so a send is never behind a sync |
| `worker-ingest` | `messenger:consume ingest` | Mail arriving and the work that immediately follows it |
| `worker-maintenance` | `messenger:consume maintenance async` | Backfills, rule runs over existing mail, admin sweeps. Also drains the retired `async` queue |
| `scheduler` | `messenger:consume scheduler_default` | Fires everything recurring. **Nothing schedules itself without this container** |
| `ntfy` | ntfy, under the `push` profile | Optional. Android push without Google — start it with `docker compose --profile push up -d` |

Three processes rather than three transports on one worker, because a worker already inside a long
handler cannot pick up anything else however the queues are prioritised. That was the original
problem: pressing Send behind a Gmail batch waited for the batch.

**The failure mode is dropping the `scheduler` service.** Without it nothing recurring fires at all
— no polling sync, no snooze wake, no calendar sync, no reminders, no pruning — and there is no
error anywhere, because nothing failed. `php bin/console debug:scheduler` lists what should be
running.

## Storage, and what the stock file does not persist

The compose file declares six named volumes:

| Volume | Holds |
|---|---|
| `app_secrets` | `generated.env`, `postgres_password`, the JWT keypair. Mounted by **every** app service, and read-only into `database` and `mercure` |
| `database_data` | The PostgreSQL cluster |
| `caddy_data`, `caddy_config` | Caddy's TLS material and state |
| `mercure_data`, `mercure_config` | Hub state |

That list has a gap worth knowing about before you put real mail in it: **`var/attachments`,
`var/raw` and `var/uploads` are not on any volume in the stock `compose.yaml`.** The Dockerfile
deliberately has no `VOLUME /app/var/` — an anonymous volume there gave every container its own
copy, so attachments written by a sync worker were invisible to the web container that serves them
— and the durable paths are instead expected to be declared per service, which
`compose.override.yaml.dist` and `truenas.compose.yaml` both do. Add them to the `php` service and
to all four workers if you want attachment downloads to survive a container being recreated:

```yaml
services:
  php: &blobs
    volumes:
      - app_attachments:/app/var/attachments
      - app_raw:/app/var/raw
      - app_uploads:/app/var/uploads
  worker-ingest: *blobs
  worker-export: *blobs
  worker-maintenance: *blobs
  imap-supervisor: *blobs

volumes:
  app_attachments:
  app_raw:
  app_uploads:
```

`truenas.compose.yaml` takes the other approach: it sets `APP_STORAGE_DIR=var/data` and binds one
host directory at `/app/var/data`, so attachments, raw messages, uploads and the secrets all land
under a single path — one thing to snapshot and one thing to back up.

**The failure mode is silent.** Nothing errors: mail syncs, the list renders, and an attachment
download 404s because the file is in a container the web server cannot see — or was in a container
that has since been replaced.

## Everyday operation

```bash
docker compose ps                  # what is up
docker compose logs -f php         # the web container
docker compose logs -f scheduler   # the recurring jobs
docker compose exec php php bin/console list
```

Signed in as an administrator, **Admin** is where the numbers live: which workers are alive, queue
depth, push state per account, database size and a searchable log. See
[Administration](../features/admin.md).

The console commands are listed in
[CONTRIBUTING](../../CONTRIBUTING.md#console-commands); the ones an operator reaches for most are
`app:backup`, `app:user:promote`, `app:user:2fa-disable` and `app:mail:test-connection`.

**The failure mode is running a console command in a container that cannot see the secrets.**
`docker compose exec php …` is fine — `config/bootstrap_generated_secrets.php` loads the generated
file for exactly that case. `docker run` against the image on its own is not.

## Where to go next

- Adding mailboxes: [IMAP and SMTP](../providers/imap-smtp.md), [Google](../providers/google.md),
  [Microsoft](../providers/microsoft.md), [CalDAV](../providers/caldav.md)
- Reaching it from outside: [Behind a reverse proxy](reverse-proxy.md)
- Everything configurable: [Configuration reference](configuration.md)
- Before you have real mail in it: [Backup and restore](backup-restore.md)

## Things that bite

**`docker compose up` picks up `compose.override.yaml` whether you meant it or not.** A copy left
behind from a development experiment silently switches the whole stack to a locally built image
with no application code baked in — the entrypoint then falls into its `symfony/skeleton` branch and
the container crash-loops.

**Every app service must mount `app_secrets`.** A service missing that mount mints its own
`APP_ENCRYPTION_KEY` and stops being able to read what the others wrote. `EncryptionKeyProbe`
catches it at boot and refuses to start the server rather than save accounts nothing else can read.

**`compose.prod.yaml` builds from source and demands `APP_SECRET`.** It sets
`target: frankenphp_prod` and `APP_SECRET: ${APP_SECRET}` with no default, so it fails outright when
that variable is unset — and setting it switches off generation for that value. It exists for
people who build their own image, not as the normal path.

**A first sync queues thousands of jobs, and that is not a fault.** `/healthz` only calls the queue
backed up past 5000 pending messages, precisely so a legitimate first sync does not report an
outage. See [Troubleshooting](troubleshooting.md).

**Migrations run automatically on every boot.** That is deliberate, and it has consequences for
rolling back — see [Upgrading](upgrading.md) before you pull a new image.
