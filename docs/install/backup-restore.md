# Backup and restore

A plMail install is three things, and a backup that has two of them restores nothing useful. This
page says what the three are, what `app:backup` does about them, and how to bring an install up on
a different machine.

## The three things

| What | Where it lives | Why losing it hurts |
|---|---|---|
| **The database** | the `database_data` volume | Every message, thread, label, filter, calendar, user and app password |
| **The encryption key** | `APP_ENCRYPTION_KEY` in `var/secrets/generated.env`, on the `app_secrets` volume | Without it every mailbox password and OAuth token in the database is permanently unreadable |
| **The blobs** | `attachments/`, `raw/` and `uploads/` under `APP_STORAGE_DIR` | Attachment paths are stored in the database relative to the project root, so without the files every attachment 404s after a restore |

The secrets file holds more than the key — `APP_SECRET`, `POSTGRES_PASSWORD`, `MERCURE_JWT_SECRET`,
the VAPID keypair and the `APP_PUBLIC_URL` saved during setup — and the JWT keypair sits beside it
in `var/secrets/jwt/`. All of those are regenerable; the encryption key is not.

Two volumes need no backup at all: `caddy_data`/`caddy_config` and `mercure_data`/`mercure_config`
hold TLS material and hub state that are recreated on their own.

**The failure mode is backing the database up and nothing else.** It restores every mailbox with
credentials that nothing can decrypt, and every attachment link pointing at a file that is not
there. Neither announces itself until somebody tries to sync or open something.

## `app:backup`

One command for all three:

```bash
docker compose exec php php bin/console app:backup /path/inside/the/container
```

With no argument it writes to `var/backups/<Y-m-d_His>/` inside the project directory. The
destination is created `0700`, and it contains:

```
database.sql   pg_dump of everything, --no-owner --no-privileges, mode 0600
attachments/   copied with cp -a
raw/
uploads/       includes avatars, under uploads/avatars/
secrets.env    a copy of var/secrets/generated.env, mode 0600
```

Two options: `--skip-secrets` when the key is already backed up elsewhere, and `--skip-storage` for
a database-only snapshot.

`pg_dump` is run against the parsed `DATABASE_URL`, so a connection the app can use is a connection
the dump can use, and the password is passed through `PGPASSWORD` in the environment rather than on
the command line where `ps` would show it to every user on the host. The image ships
`postgresql-client-18` from PGDG on purpose: pg_dump refuses outright to dump a server newer than
itself, and the Debian package trails.

The command finishes by saying one of two things, and both are worth reading:

- **`secrets.env` holds `APP_ENCRYPTION_KEY`** — move it somewhere the database dump is not.
  Encrypting mailbox passwords is pointless if the backup staples the key to them; kept together,
  the pair is worth exactly as much to a thief as an unencrypted backup.
- **`APP_ENCRYPTION_KEY` is *not* in this backup** — this install supplies it from the environment,
  so there was nothing to copy. The backup looks complete and is not. Back the key up from wherever
  you configure it.

`cp -a` rather than a flattened copy is deliberate: the storage paths in the database point into the
directory bucketing, so a flattened copy restores files nothing can find.

**The failure mode is the `php` container not being able to see the blobs.** `app:backup` copies
`APP_STORAGE_DIR` as that container sees it, and on the stock `compose.yaml` the blob directories
are not on any shared volume — so the web container's copy is not the one the ingest worker wrote
into. Fix the mounts before trusting the backup; see
[the storage section of the Docker page](docker.md#storage-and-what-the-stock-file-does-not-persist).

## Nothing schedules this

`app:backup` is deliberately absent from `MaintenanceSchedule`. A backup that runs itself onto the
same disk as the thing it is backing up is a false sense of security, and where else it should go is
a decision only you can make. Drive it from cron on the host, or from whatever your NAS already
uses:

```bash
docker compose exec -T php php bin/console app:backup /app/var/backups/nightly
```

Then move `database.sql` and `secrets.env` to **different destinations**. That is the whole point of
encryption at rest.

**The failure mode is a backup nobody has ever restored.** The first restore is not the moment to
discover that the blob directories were empty.

## Restoring onto a new host

The order matters, because the secrets have to be in place before anything writes to the database.

**1. Put the compose file on the new host and do not start the stack.** If it has already been
started, take it down and remove its volumes — an install that has generated its own
`APP_ENCRYPTION_KEY` will refuse to boot against a restored database anyway.

**2. Put `secrets.env` back as `generated.env`.** Find the volume name (`docker volume ls`; it is
your project name plus `_app_secrets`) and write the file into it:

```bash
docker run --rm -v pl_mail_app_secrets:/secrets -v "$PWD":/backup:ro alpine \
  sh -c 'cp /backup/secrets.env /secrets/generated.env && chmod 600 /secrets/generated.env'
```

The bare `postgres_password` file that the Postgres image reads does not need restoring: the
generator rewrites it from the `POSTGRES_PASSWORD=` line in `generated.env` every time it runs. The
JWT keypair does not need restoring either — `app:secrets:init` regenerates it when it is missing,
at the cost of invalidating any JMAP JWT already issued. App passwords are database rows and are
unaffected.

**3. Bring up only the database and wait for it.**

```bash
docker compose up -d database
```

`secrets-init` runs first, finds every value already present in the file you restored, and generates
nothing.

**4. Load the dump.**

```bash
docker compose exec -T database psql -U app -d app < database.sql
```

**5. Put the blobs back** into whatever holds them on this host — the named volumes if you added
them, or the bind-mounted directory if you followed the `truenas.compose.yaml` pattern. What matters
is that the paths under `APP_STORAGE_DIR` match what they were, because the database stores them
relative to the project root.

**6. Start everything.**

```bash
docker compose up -d
```

Migrations run on boot, so a restore onto a **newer** image brings the schema forward as part of
starting. A restore onto an older image does not go backwards — see [Upgrading](upgrading.md).

**7. Check three things.** `/healthz` should answer 200 with `database: true`; the admin header
should show the build you expect; and one mail account should sync. That last one is the real test,
because it is the first thing that decrypts a stored credential.

**The failure mode is a container that refuses to start with "APP_ENCRYPTION_KEY cannot decrypt the
credentials already stored in this database".** That message means the restore worked and the key
did not come with it. Nothing has been changed — the probe refuses rather than overwriting data the
right key could still read. Putting the original key back is the only way to recover those
credentials.

## Changing the address afterwards

An install restored onto a new host is usually reached at a new address. `APP_PUBLIC_URL` lives in
the restored `generated.env`, so it still holds the old one. Set it in the environment, or edit that
file, then restart the stack — and re-check the redirect URIs registered with
[Google](../providers/google.md) and [Microsoft](../providers/microsoft.md), which are matched
exactly.

**The failure mode is push that never resumes.** The channels registered by the old install point
at the old address; `app:calendar:push` re-registers hourly once the address is right, and
`app:push:renew --repair` runs nightly for mail.

## Things that bite

**Copying the `database_data` volume is not a backup.** A running Postgres cluster copied file by
file is not consistent. `pg_dump` — which is what `app:backup` runs — is.

**Storing `database.sql` and `secrets.env` together undoes encryption at rest.** The command says so
loudly, once, at the end of a run nobody reads twice.

**An install that supplies `APP_ENCRYPTION_KEY` from the environment gets a backup with no key in
it.** That is correct behaviour and it is the most dangerous case in this whole page, which is why
the command checks for the key's presence in the copied file rather than assuming it.

**`POSTGRES_PASSWORD` cannot be rotated by restoring a different secrets file.** Postgres was
initialised with the old one and keeps its own copy, so the app is locked out of a cluster it can
otherwise see. Either restore the matching password or start from an empty database volume.

**Backups age out of usefulness with the image.** A dump taken from an install running a much older
tag restores fine and then migrates forward on first boot; a dump from a *newer* install does not
migrate backwards. Note the version chip in the admin header alongside the backup.
