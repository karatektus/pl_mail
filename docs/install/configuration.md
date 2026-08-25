# Configuration reference

Every environment variable plMail reads, what it does, what it defaults to, and what goes wrong
when it is set to something else. The other pages in this section link here rather than repeating
values, so this is the one to keep open while editing a compose file.

## Where a value comes from

Four sources, highest precedence first:

1. **A real environment variable.** Anything on the container's environment wins over everything
   below it, always. Nothing plMail generates is ever written over the top of a value somebody
   supplied.
2. **The generated secrets file**, `var/secrets/generated.env`. Minted on first start and loaded
   both by the container entrypoint and by `config/bootstrap_generated_secrets.php`, which is
   pulled in through composer's `autoload.files` so that `docker compose exec php php bin/console …`
   — which bypasses the entrypoint entirely — sees the same values.
3. **`.env.local`**, untracked. Useful when plMail runs from a checkout; there is no such file
   inside the published image.
4. **`.env`**, committed, which is where the defaults in the tables below come from.

An **empty value counts as absent** at every level. That matters because `docker compose` passes
`${APP_ENCRYPTION_KEY:-}` through as an empty string when nobody set it, and treating that as
"already configured" would defeat first-run generation.

The trap in that arrangement is `docker compose`'s own use of `.env`: the file beside `compose.yaml`
is what Compose reads to resolve `${VAR}`, so **a value put there becomes a real environment
variable inside every container** and switches off generation for it. That is why `APP_SECRET`,
`APP_ENCRYPTION_KEY`, `MERCURE_JWT_SECRET` and `APP_PUBLIC_URL` are blank in the committed `.env`,
under a banner saying so. Set them there only if you mean to manage them yourself.

## Application variables

These are the variables in `.env`, in the order they appear there.

| Variable | What it does | Default | Required | If it is wrong |
|---|---|---|---|---|
| `APP_ENV` | Symfony environment. | `dev` in `.env`; `compose.yaml` overrides it with `${APP_ENV:-prod}` | Yes | `dev` on a real install boots the debug kernel and profiler, and switches off `DefaultSecretsGuard`, which only refuses shipped placeholder secrets in `prod`. |
| `APP_SECRET` | Symfony's application secret. Signs remember-me cookies, among other things. | blank — 32 random bytes, hex, generated on first start | Yes, but generated | Changing it invalidates every remember-me cookie, so everyone is logged out. |
| `APP_SHARE_DIR` | Nothing. No code in this repository reads it. | `var/share` | No | Nothing. Left in place because removing a variable is a change to `.env`; treat it as inert. |
| `APP_SECRETS_FILE` | Path to the generated secrets file, relative to the project root unless it starts with `/`. | `var/secrets/generated.env` | Yes | Point one service somewhere the others cannot see and it mints its own encryption key. `EncryptionKeyProbe` catches that at boot and refuses to start the server. |
| `APP_STORAGE_DIR` | Root for everything plMail writes as a file, relative to the project root: `attachments/`, `raw/`, `uploads/` (avatars live under `uploads/avatars/`). | `var` | Yes | Attachment paths are stored in the database relative to the project root, so changing this on an install that already holds mail orphans every existing file. |
| `APP_PUBLIC_URL` | The address plMail is reached at from outside — what Google and Microsoft call back to. | blank; asked for on the setup screen and written to the generated file | For push, yes | Blank, `http://`, or a loopback host and calendar push and Graph mail push refuse to register at all. See [Behind a reverse proxy](reverse-proxy.md). |
| `APP_DEFAULT_TIMEZONE` | The clock a user who has never picked one in Settings is shown. | `Europe/Berlin` (also the `app.fallback_timezone` parameter, used when the variable is absent) | No | An install left on the wrong zone shows every such user times that are plausibly wrong by their own offset. Timestamps are stored in UTC and only converted for display, so fixing it re-renders rather than rewrites. Not the container's PHP timezone, which is pinned to `UTC` in `frankenphp/conf.d/10-app.ini`. |
| `DEFAULT_URI` | Base URI the router uses when generating URLs outside an HTTP request. | `http://localhost` | No | Links in mail generated from a console command point at localhost. Push callbacks do not use this — they use `APP_PUBLIC_URL`. |
| `DATABASE_URL` | Doctrine connection. | `postgresql://app@database:5432/app?serverVersion=18&charset=utf8` — deliberately credential-less | Yes, but assembled | A DSN that carries **no** password is treated as "nobody configured a database", and the generated `POSTGRES_PASSWORD` is spliced in. A DSN that carries one is taken as intent and left alone — so a wrong password here is used verbatim and Postgres refuses the connection. It is not blank because Doctrine reads the driver out of the scheme, and a blank DSN fails the image build's cache warmup with "could not find driver". |
| `MESSENGER_TRANSPORT_DSN` | Transport behind the `export`, `ingest`, `maintenance` and `async` queues. | `doctrine://default?auto_setup=0` | Yes | `auto_setup=0` is deliberate: the table is owned by a migration. Pointing this elsewhere without a consumer for each queue means mail queues and nothing drains it, with no error anywhere. |
| `MAILER_DSN` | Symfony's mailer transport. | `null://null` | No | Nothing today. plMail's own outgoing mail — reminder mails, iTIP replies, booking confirmations — deliberately does **not** go through it: it leaves through the user's own account via `MailSenderRegistry`, because plMail is a mail client and not a service with an outbound relay of its own. The setting is wired up and reached by nothing: `MailerInterface` is injected in no service in `src/`, so no code path uses this transport. The dev stack used to run a Mailpit container for it, which therefore never received a message and has been removed. |
| `APP_ENCRYPTION_KEY` | Base64 32-byte libsodium secretbox key behind the `encrypted_string` Doctrine type — every mailbox password and OAuth token. | blank — generated on first start | Yes, but generated | Lose it and the stored credentials are unrecoverable. A key that does not match stored data stops the web server from booting. See [below](#the-encryption-key). |
| `APP_DEV_USER_EMAIL` | Default answer offered by `app:setup`'s first prompt. | blank | No | Nothing; the prompt is answerable by hand. The test stack sets it to seed a known user. |
| `APP_DEV_USER_PASSWORD` | Default answer offered by `app:setup`'s second prompt. | blank | No | As above. Never set this on an install reachable by anyone else. |
| `APP_DEMO_MODE` | Turns the whole instance into a public demo: every visitor to `/demo` is handed a throwaway user with a seeded mailbox, sending is swallowed and answered by a script, and a bar at the foot of the page delivers scripted mail on demand. Also switches off mail sync, push renewal, calendar sync and the forms that attach a real mailbox. | `0` (and `false` when the variable is absent entirely) | No | On by accident on a real install and mail stops syncing, sending silently goes nowhere, and `/demo` hands a stranger a working session. **Never set this on an instance holding real mail.** See [Demo mode](demo-mode.md). |
| `APP_DEMO_TTL` | How long a demo visitor's throwaway user lives before `app:demo:reap` deletes it. An ISO 8601 duration. | `PT2H` | No | An unparseable value falls back to two hours rather than throwing, so a typo shortens nothing and breaks nothing. Set it very long and the reaper stops keeping up with the visitors. Read only when `APP_DEMO_MODE` is on. |
| `APP_DEMO_IMPRESSUM_NAME` | Operator named on the demo's legal notice (`/impressum`). | blank | For a public demo in Germany, yes | Blank and the page renders a visible warning instead of a name. That is deliberate: § 5 TMG requires a real operator, and a notice naming nobody looks compliant from a distance without being so. Read only when `APP_DEMO_MODE` is on. |
| `APP_DEMO_IMPRESSUM_ADDRESS` | Postal address on the same page. Newlines are preserved. | blank | As above | As above. |
| `APP_DEMO_IMPRESSUM_EMAIL` | Contact address on the same page, rendered as a `mailto:` link. | blank | As above | As above. |
| `MERCURE_URL` | The hub address the **app** publishes to, inside the Docker network. | `http://mercure/.well-known/mercure` | Yes | Wrong and nothing publishes: no live updates, no visible error on the page. |
| `MERCURE_PUBLIC_URL` | The hub address the **browser** subscribes to. | `https://localhost/.well-known/mercure` in both `.env` and `compose.yaml` | Yes | Wrong and the browser opens a stream to somewhere it cannot reach — mail lists stop updating by themselves while the rest of the app works. Derived from `APP_PUBLIC_URL` only when this is unset or empty, which the stock `compose.yaml` prevents. See [Behind a reverse proxy](reverse-proxy.md). |
| `MERCURE_JWT_SECRET` | Signs the publisher and subscriber JWTs. | blank — 32 random bytes, hex, generated on first start | Yes, but generated | The app and the hub must hold the same value. They disagree and the hub rejects every subscriber, silently, from the browser's point of view. |
| `GOOGLE_OAUTH_CLIENT_ID` | Google OAuth client. | blank | For Gmail | Without it, "Sign in with Google" cannot start. A value stored under **Admin → Integrations** wins over this one. See [Google](../providers/google.md). |
| `GOOGLE_OAUTH_CLIENT_SECRET` | Google OAuth client secret. | blank | For Gmail | As above. |
| `GMAIL_PUBSUB_TOPIC` | Full topic name Gmail publishes watch notifications to. | `projects/your-project-id/topics/gmail-push` — a placeholder, not a working value | For instant Gmail | The placeholder is not a topic you own, so every `watch` call fails. The project id is lowercase and often differs from the display name. |
| `GMAIL_PUBSUB_VERIFICATION_TOKEN` | Shared secret appended to the Pub/Sub push endpoint as `?token=…`. | blank | For instant Gmail | **Fails closed.** With no token configured, `POST /gmail/push` refuses every notification with 403 rather than accepting unverified ones. A mismatch does the same. |
| `MICROSOFT_OAUTH_CLIENT_ID` | Azure app registration client id. | blank | For Outlook | Without it, "Sign in with Microsoft" cannot start. Also overridable under Admin → Integrations. |
| `MICROSOFT_OAUTH_CLIENT_SECRET` | Azure client secret. | blank | For Outlook | As above. |
| `MICROSOFT_OAUTH_TENANT` | Which Microsoft accounts may sign in: `common`, `organizations`, `consumers`, or a tenant GUID. | `common` | No | Must match the app registration's supported account types, or consent fails with `AADSTS50194` — at consent time, not at setup. See [Microsoft](../providers/microsoft.md). |
| `APP_DB_LOG_LEVEL` | Minimum Monolog level kept in the database for the admin log browser. | `warning` | No | Lowering it to `info` or `debug` fills the `log_entry` table quickly; `app:monitoring:prune` keeps 14 days by default, which is a lot of rows at debug. |
| `APP_CONTAINER_NAME` | Which container a log row and a worker heartbeat are attributed to. | `web` in `.env`; set per service in `compose.yaml` | No | Unset, the heartbeat key falls back to the hostname — which changes every time a container is recreated, so the admin panel accumulates dead workers until the stale sweep reaps them. |
| `TRUSTED_PROXIES` | Proxies whose `X-Forwarded-*` headers Symfony believes. | `127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16` | Behind a proxy, yes | Too narrow and Symfony sees the proxy's address and `http`; too wide and a client can forge its own address. Both consequences are spelled out in [Behind a reverse proxy](reverse-proxy.md). |
| `JWT_SECRET_KEY` | Path to the private key the JMAP firewall's JWTs are signed with. | `%kernel.project_dir%/var/secrets/jwt/private.pem` | Yes | It lives beside the generated secrets on purpose: the keys are not in the image, so a service with its own copy would reject tokens the others signed. Generated by `app:secrets:init`. |
| `JWT_PUBLIC_KEY` | Matching public key. | `%kernel.project_dir%/var/secrets/jwt/public.pem` | Yes | As above. |
| `JWT_PASSPHRASE` | Passphrase for that keypair. | blank | No | Must match the keypair on disk. Changing it without regenerating the keys makes every JWT unverifiable. |
| `VAPID_SUBJECT` | Contact identifier sent with Web Push, per RFC 8292. Must be a `mailto:` or `https:` URL. | `mailto:admin@example.com` | For Web Push | Some push services refuse a request whose subject is not a URL they accept. |
| `VAPID_PUBLIC_KEY` | Web Push / JMAP `PushSubscription` public key. | blank — generated by `app:secrets:init` | Yes, but generated | Browsers bind a subscription to the public key it was created with. Rotate it and every device silently stops receiving notifications until it re-subscribes. |
| `VAPID_PRIVATE_KEY` | The matching private key. | blank — generated | Yes, but generated | As above. |
| `INTEGRATIONS_ALLOW_HTTP` | Whether a user may name a `http://` server for a self-hosted integration. | `false` | No | See [the SSRF guard](#the-ssrf-guard) below. |
| `INTEGRATIONS_ALLOWED_HOSTS` | Comma-separated hosts exempt from the private-range block. | blank | No | See [the SSRF guard](#the-ssrf-guard) below. |

**The failure mode of this table is treating a blank as a missing value.** Four of the blanks
above are load-bearing: they are blank so that first-run generation happens, and filling one in
with a value copied from somewhere else is how two services end up disagreeing about the
encryption key.

## Variables only the compose file and the entrypoint read

These never appear in `.env` — Compose substitutes them into `compose.yaml`, or the entrypoint
reads them directly when it assembles `DATABASE_URL`.

| Variable | What it does | Default | If it is wrong |
|---|---|---|---|
| `SERVER_NAME` | The hostname Caddy serves, inside the `php` container. | `localhost, php:80` | Caddy decides whether to terminate TLS from this. `:80` serves plain HTTP, which is what you want behind a reverse proxy. A hostname makes Caddy try to obtain a certificate for it. |
| `HTTP_PORT` | Host port mapped to container port 80/tcp. | `80` | A port already in use stops the `php` container from starting. |
| `HTTPS_PORT` | Host port mapped to container port 443/tcp. | `443` | As above. |
| `HTTP3_PORT` | Host port mapped to container port 443/udp. | `443` | Only matters if you serve HTTP/3 directly. |
| `POSTGRES_USER` | Database role, on both the `database` service and the assembled DSN. | `app` | Changing it after the database volume exists points the app at a role Postgres never created. |
| `POSTGRES_DB` | Database name, same two places. | `app` | As above. |
| `POSTGRES_VERSION` | Tag of the `postgres` image and `serverVersion` in the assembled DSN. | `18` | The image ships `postgresql-client-18` for `pg_dump`, and pg_dump refuses to dump a server newer than itself — so a newer server breaks `app:backup`. |
| `POSTGRES_PASSWORD` | Database password. | blank — 24 random bytes, hex, generated on first start | Never rotated by `app:reset`: Postgres was initialised with it and keeps its own copy, so a new one locks the app out of the database it just reset. |
| `POSTGRES_HOST` | Host in the assembled DSN. Read by the entrypoint only. | `database` | Only relevant when running plMail outside this compose file. |
| `POSTGRES_CHARSET` | `charset` in the assembled DSN. Entrypoint only. | `utf8` | — |
| `APP_SECRETS_DIR` | Directory `generate-secrets` writes into. | `/app/var/secrets` | Must agree with `APP_SECRETS_FILE`. `truenas.compose.yaml` moves both under one bind mount. |
| `NTFY_PORT` | Host port for the optional ntfy container. | `8090` | Only used under the `push` profile. |
| `NTFY_BASE_URL` | Base URL ntfy hands out in push endpoints. | `http://${SERVER_NAME:-localhost}:${NTFY_PORT:-8090}` | Baked into every endpoint handed out, so changing it later invalidates every existing subscription and every device must re-register. |
| `NTFY_AUTH_DEFAULT_ACCESS` | ntfy's default access policy. | `read-write` | Open by design — UnifiedPush topics are unguessable and payloads are encrypted to the device — but set it to `deny-all` and create a user if this is exposed to the internet rather than reached over a VPN. |
| `TEST_HTTP_PORT` | Host port for the test stack in `compose.test.yaml`. | `8001` | Development only. |

**The failure mode here is editing one half of a pair.** `POSTGRES_USER`, `POSTGRES_DB` and
`POSTGRES_PASSWORD` are read both by the database container at initialisation and by the app when
it assembles its DSN, and the database only reads them the first time its volume is created.

## What is generated, and where

`frankenphp/generate-secrets.sh` mints these into `var/secrets/generated.env` on first start, under
a lock, only for names that are not already set:

| Name | How it is generated |
|---|---|
| `APP_SECRET` | 32 bytes from `/dev/urandom`, hex |
| `APP_ENCRYPTION_KEY` | 32 bytes, base64 — the size libsodium secretbox requires |
| `POSTGRES_PASSWORD` | 24 bytes, hex, also written as the bare file `postgres_password` that the Postgres image reads through `POSTGRES_PASSWORD_FILE` |
| `MERCURE_JWT_SECRET` | 32 bytes, hex |

`app:secrets:init` adds the two that need PHP — a VAPID keypair and the lexik JWT keypair — after
migrations have run, so there is one file to back up rather than several. `APP_PUBLIC_URL` is
written into the same file by the setup screen.

Hex rather than base64 for three of the four is deliberate: `POSTGRES_PASSWORD` is spliced into a
DSN and `MERCURE_JWT_SECRET` into a Caddy config, and neither wants `+`, `/` or `=`.

**The failure mode is a service that cannot see the file.** It mints its own copies, and from then
on writes credentials the rest of the fleet cannot read.

## The encryption key

`APP_ENCRYPTION_KEY` is the one value where a mistake is not recoverable. It is the libsodium key
behind the `encrypted_string` Doctrine type, so every mailbox password and OAuth refresh token in
the database is unreadable without it.

`App\Infrastructure\Setup\EncryptionKeyProbe` runs on every container start and tries to hydrate one
account with stored credentials. When that fails it **refuses to start the web server** rather than
let the container save accounts under a key half the fleet cannot read. Under a console invocation
it warns and continues instead — because refusing there would block the very command that repairs
the situation.

CONTRIBUTING has the full account of [why the key is different](../../CONTRIBUTING.md#why-app_encryption_key-is-different)
and what to do [when the keys disagree](../../CONTRIBUTING.md#when-the-keys-disagree); the
[security model](../internals/security-model.md) covers what the encryption does and does not
protect. Backing it up is [Backup and restore](backup-restore.md).

**The failure mode is rotation under a running stack.** The other services keep the old key in
process memory until they restart, so for a while half of them cannot read what the other half
writes.

## The SSRF guard

Two variables relax one check, and it is worth being exact about what the check is for.

Self-hosted integrations — Nextcloud, Immich — let a signed-in user type their own server address.
That aims plMail's outbound HTTP client wherever the user likes, from inside a container network
that also holds Postgres and the Mercure hub. `App\Service\Integration\IntegrationUrlValidator`
refuses any address resolving into `127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`,
`169.254.0.0/16`, `100.64.0.0/10` or `0.0.0.0/8`, and refuses `http://` outright.

- **`INTEGRATIONS_ALLOW_HTTP=true`** lets a user send an app password over plaintext HTTP. On a
  LAN that is often what people want, and it is off by default so that it stays a deliberate
  decision rather than an accident.
- **`INTEGRATIONS_ALLOWED_HOSTS=nextcloud.lan,10.0.0.5`** exempts named hosts from the private-range
  block. Every name you add is a name a user can point plMail at — so listing a whole range, or the
  host the database is on, hands an authenticated user a request from inside your network.

An administrator who pins `baseUrl` on the provider config under **Admin → Integrations** removes
the surface entirely, and that is the better answer where it is available: the user's value is
ignored, including a stale one saved before the pin.

**The failure mode is that this is not a DNS-rebinding defence.** A hostname that resolves to a
private address at connect time still gets through, because pinning the resolved IP into the HTTP
client is not something Symfony's client exposes.

## Values configured in the database instead

Some settings are not environment variables at all, and a stored value **wins** over the
environment when it is non-empty:

| Setting | Where |
|---|---|
| Google and Microsoft client id and secret | Admin → Integrations |
| Microsoft tenant | Admin → Integrations |
| Gmail Pub/Sub topic and verification token | Admin → Integrations |
| Which file integrations are offered at all | Admin → Integrations |
| Integration provider base URLs (the SSRF pin above) | Admin → Integrations |

This is the path the [admin page](../features/admin.md) documents, and the one `truenas.compose.yaml`
points people at, because it shows the exact redirect URI to paste into the provider's console.

**The failure mode is a half-filled row.** A stored value only shadows the environment when it is
actually set, so a row holding a client id and no secret does not silently disable a working `.env`
— but a row holding both, entered once and forgotten, means editing the environment changes
nothing.

## Things that bite

**A secret in `.env` is a secret in every container.** Compose reads that file to resolve `${VAR}`,
which makes anything in it a real environment variable everywhere — and a real environment variable
is exactly what tells the entrypoint "the operator supplied this, do not generate one". A value
committed there would be shared by every plMail install in existence.

**`DATABASE_URL` is not empty, and must not be.** The committed value is a DSN with no credentials,
because Doctrine reads the driver out of the scheme and a blank DSN has none — which fails the prod
cache warmup during the image build with "could not find driver". The entrypoint's test is
therefore "does this DSN carry a password", not "is it set". A DSN you supply *with* a password is
taken as intent and left completely alone.

**`MERCURE_PUBLIC_URL` has a default that is not derived.** `config/bootstrap_generated_secrets.php`
builds it from `APP_PUBLIC_URL` — but only when it is unset or empty, and `compose.yaml` sets it to
`https://localhost/.well-known/mercure` unconditionally. On any install not reached at
`https://localhost`, set it explicitly.

**`GMAIL_PUBSUB_VERIFICATION_TOKEN` blank means "reject everything", not "accept everything".**
That is the right default for an endpoint reachable from the internet, but it means an operator who
sets up Pub/Sub and forgets the token sees notifications rejected with 403 and no mail arriving
instantly — which looks exactly like a topic that was never created.

**`APP_STORAGE_DIR` cannot be changed on a populated install.** Attachment paths in the database
are relative to the project root and include this prefix, so moving it orphans everything already
stored. Move the *contents* to the new location at the same time, or leave it alone.

**`POSTGRES_VERSION` is coupled to the image's `pg_dump`.** The Dockerfile installs
`postgresql-client-18` from PGDG specifically because pg_dump refuses to dump a server newer than
itself. Raise the server past 18 without rebuilding and `app:backup` fails at the moment somebody
needs a backup.
