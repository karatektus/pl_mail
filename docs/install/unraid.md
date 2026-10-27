# Unraid

On Unraid, plMail runs as **one container** next to a **Postgres container**, the way Unraid apps
usually come: Community Applications installs one container per template, and a database is its
own app. Everything else plMail runs (the web server, the queue workers, the scheduler, the IMAP
supervisor and the Mercure hub) lives in the plMail container. That is the image's all-in-one
mode, switched on with `APP_ALL_IN_ONE=1`. The image and the tags are the same as for
[Docker Compose](docker.md); only how it is started differs.

## Install

1. **Postgres first.** Install a PostgreSQL 18 container from Community Applications (or the
   official `postgres:18` image by hand). Set a database, a user and a password, and put it on the
   same Docker network as plMail. A custom bridge network lets plMail reach it by container name;
   on the default bridge, use the Unraid server's IP address and the Postgres port instead.
2. **plMail.** Install plMail from Community Applications and fill in `DATABASE_URL`:

   ```
   postgresql://plmail:<password>@<postgres container or host>:5432/plmail?serverVersion=18&charset=utf8
   ```

   The password is the only secret you type. Everything else (`APP_SECRET`, the encryption key,
   the Mercure key, the push and token keypairs) is generated on first start into
   `/mnt/user/appdata/plmail/secrets`.
3. Open `http://<server>:30519/` and go through the setup.

Without the template, the same container from the command line:

```sh
docker run -d --name plmail \
  --init --restart=unless-stopped --stop-timeout=35 \
  -p 30519:80 \
  -v /mnt/user/appdata/plmail:/app/var/data \
  -e APP_ALL_IN_ONE=1 \
  -e APP_STORAGE_DIR=/app/var/data \
  -e APP_SECRETS_DIR=/app/var/data/secrets \
  -e APP_SECRETS_FILE=/app/var/data/secrets/generated.env \
  -e DATABASE_URL='postgresql://plmail:<password>@postgres:5432/plmail?serverVersion=18&charset=utf8' \
  ghcr.io/karatektus/pl_mail:latest
```

## What runs in the container

`app:work` is the container's main process and keeps everything else running: the web server
(FrankenPHP, plain HTTP on port 80 inside the container), the queue workers, the scheduler, the
IMAP supervisor and the Mercure hub. A child that exits is started again: at once after a clean
exit, after a backoff of one second doubling to thirty after a crash. If `app:work` itself ends,
so does the container, and Docker's restart policy starts it again.

The hub listens on port 3000 inside the container, which nothing publishes; the web server proxies
`/.well-known/mercure` to it, so the browser only ever talks to port 30519.

**"Restart workers"** in the admin panel works as everywhere: the workers finish what they are
doing, exit, and are started again. **"Restart app"** stops the whole container, because that is
the only way a rotated secret reaches the web server, and the restart policy brings it back.

## The three flags in the template

The template adds `--init --restart=unless-stopped --stop-timeout=35` under Extra Parameters.
Each of them matters:

- `--init` puts a minimal init in front of `app:work`, which cleans up the processes the IMAP
  supervisor leaves behind. Compose does the same with `init: true`.
- `--restart=unless-stopped` is what brings the container back after "Restart app" or a crash.
- `--stop-timeout=35` gives the workers time to finish: `app:work` waits up to 25 seconds for its
  children when stopped. Docker's own default is 10 seconds, after which it kills them in the
  middle of whatever they are doing.

## Data and backup

Everything lives under one path, `/mnt/user/appdata/plmail` on the host (`/app/var/data` in the
container): `secrets/`, attachments, raw messages and uploads. The database is in the Postgres
container's own appdata. Back up both. `secrets/` holds the encryption key, and without it every
stored mail password is unreadable. See [Backup and restore](backup-restore.md).

The container runs as root by default, as the compose stack does. Running it as `nobody:users`
(`--user 99:100`) also works, as long as the appdata folder belongs to that user.

## Behind a reverse proxy

plMail serves plain HTTP on 30519. For HTTPS, put it behind the reverse proxy you already use on
Unraid (SWAG, Nginx Proxy Manager) and set the public address during setup. Live updates go through
the same address, so the proxy needs nothing beyond forwarding everything to port 30519. See
[Behind a reverse proxy](reverse-proxy.md) for `TRUSTED_PROXIES` and the rest.

## Updates

Unraid shows an update for plMail when a new image is published under the tag the template uses.
Applying it pulls the image and starts a new container, and migrations run on start as they do
everywhere. See [Upgrading](upgrading.md).

## Things that bite

**Without `--restart`, "Restart app" stops plMail for good.** The button ends the container and
counts on Docker to start it again. A container created without a restart policy stays stopped
until somebody starts it from the Docker tab.

**Postgres on the default bridge cannot be reached by name.** Container names only resolve on a
user-defined network. On the default bridge, `DATABASE_URL` has to name the server's IP address
and the port Postgres is published on.

**A `DATABASE_URL` without a password is ignored.** plMail reads a URL without a password as "no
database configured" and builds its own from `POSTGRES_HOST`, `POSTGRES_USER` and a generated
password, which the Postgres container knows nothing about. The connection is then refused.

**Moving an existing compose install to Unraid is a backup and restore**, not copying volumes
across: the compose stack's Postgres is the Alpine image, and a data directory written by it must
not be opened by a Debian-based one. See [Backup and restore](backup-restore.md).
