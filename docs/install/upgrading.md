# Upgrading

plMail migrates its own database on boot. That single decision shapes everything on this page: it
is why upgrading is two commands, why the image tag you pull matters more than it would otherwise,
why the admin header shows a build number at all, and why rolling back means restoring a backup
rather than pulling the previous tag.

## Migrations run automatically, on every boot

`frankenphp/docker-entrypoint.sh` waits for the database and then runs `app:db:migrate` in every
app container — the web server, the IMAP supervisor, all four workers. That command is
`doctrine:migrations:migrate --all-or-nothing --no-interaction` with one addition: it holds a
Postgres advisory lock for the whole run.

The lock is not decoration. Six containers start within milliseconds of each other against one
database, all six read the migration ledger before any of them has written to it, and all six
decide the same migration is pending. Without the lock one wins, the rest block on its table lock,
are released when it commits, and die on a schema that has already moved —
`SQLSTATE[42701]: Duplicate column`. Under `set -e` that is five services that never start. With
it, the losers wait, read a ledger that is already current, and exit having found nothing to do.

Three consequences follow, and they are the reason this page exists:

- **There is no window in which you can inspect the migration before it runs.** Starting the new
  image *is* running it.
- **A migration that fails takes the container with it**, because the entrypoint stops on error.
  That is the intended behaviour: a container serving requests against a half-migrated schema is
  worse than a container that did not start.
- **Waiting for the lock has a deadline.** Five minutes by default, overridable with
  `--lock-timeout`. Past it the command refuses to migrate rather than assume the holder is dead —
  whatever holds the lock may still be mid-migration, and a second migrate on top of that is the
  thing the lock exists to prevent.

The CHANGELOG names the schema change in every release that has one, at the top of the entry, with
whether it is additive. Read that before pulling; it is the only preview you get.

**The failure mode is a stack where one container is stuck holding the lock.** Everything else waits
five minutes, reports "timed out waiting for another container to finish running migrations", and
exits. Find the stuck one before restarting the rest.

## Image tags

The release workflow publishes one multi-architecture manifest and points several tags at it:

| Tag | What it follows |
|---|---|
| `latest` | the most recent **release** — it moves only when a `v*` tag is pushed |
| `main` | the tip of the default branch, released or not |
| `0.0.16` | one release, pinned. No leading `v`: the git tag is `v0.0.16`, the image tag is not |
| `0.0` | the newest patch of one minor line |
| `sha-1a2b3c` | one commit, pinned exactly |

`latest` used to follow the default branch, which meant every merge went straight to the tag the
README tells people to pull — including work in progress, and migrations that run on boot. It was
changed for exactly that reason.

Pick deliberately. `latest` is right for most installs. `main` is for running what is being worked
on, and the changelog will not describe it yet. A pinned version is right when you would rather
schedule upgrades than receive them.

**The failure mode is `pull_policy: always` on a moving tag.** `truenas.compose.yaml` sets it, so a
redeploy from the NAS app page picks up whatever that tag now points at — which is convenient on
`latest` and surprising on `main`.

## Upgrading

```bash
docker compose pull
docker compose up -d --wait
```

Every app service runs the same image, so all of them are recreated and all of them come up on the
new code. `--wait` blocks until the healthchecks pass, which is worth having in a script: the `php`
container's healthcheck is `/healthz`, which does not answer until PHP can reach the database.

Then check two things:

1. **The admin header**, for which build is actually running.
2. **`/healthz`**, for whether the workers came back — see [Troubleshooting](troubleshooting.md).

If you ever migrate by some route other than a container restart, restart the workers afterwards
from **Admin → System**. A worker caches Doctrine metadata for its whole lifetime, so after a
migration it can keep querying columns that no longer exist until something restarts it. That
button writes a timestamp into a cache pool shared across containers; every Messenger worker
compares it against its own start time on each loop and exits, and `restart: unless-stopped` brings
it back. The IMAP supervisor is not a Messenger worker and reads the same signal itself.

**The failure mode is upgrading one service.** All of them share the image and all of them migrate;
a stack where only `php` was recreated has workers running yesterday's code against today's schema.

## Telling which build is running

Signed in as an administrator, the header of every **Admin** page carries a chip with the ref the
image was built from and the first seven characters of the commit. It is in the header rather than
in a panel because the question it answers is asked while looking at something else.

It comes from two build arguments, `APP_VERSION` and `APP_COMMIT`, stamped in by the release
workflow from the metadata action and the commit SHA. The image has no `.git` to ask — the source is
copied in and the history stays behind — so an image nobody stamped honestly does not know what it
is. In that case `AppVersion` falls back to `git describe --tags --always --dirty` if it is running
from a checkout, and otherwise reports **`development`**, at which point the chip is not rendered at
all rather than showing furniture on every page.

So: a chip means a built, tagged image. No chip means either a locally built image with no build
arguments, or a checkout. Both are legitimate; neither is what `docker compose pull` gives you.

**The failure mode is trusting the tag instead of the chip.** Two images can both call themselves
`main`. The commit in the chip is the only thing that distinguishes them, and it is the reason the
label alone is not enough.

## Rolling back

There is no downgrade path, and the reason is the first section of this page. Migrations ran on
boot, so by the time you know you want the previous version, the schema has already moved.

Every migration in this repository does ship a `down()`, so a deliberate reversal with
`doctrine:migrations:migrate` naming an earlier version is *possible*. It is rarely what you want:
`down()` drops what `up()` added, which means dropping the data in it, and the next boot of a
newer image migrates straight back up.

The supported rollback is:

1. Take the stack down.
2. Restore the database from the backup taken **before** the upgrade — see
   [Backup and restore](backup-restore.md).
3. Pin the image to the tag you were on: `ghcr.io/karatektus/pl_mail:0.0.15`, not `latest`.
4. Bring it up and confirm the chip shows the version you pinned.

Everything that happened between the backup and the rollback is gone, which is the honest cost and
the reason to take a backup immediately before an upgrade rather than nightly.

**The failure mode is pulling the old tag and nothing else.** The old code boots against a database
that is ahead of it: Doctrine reports migration versions it has never heard of, and the application
queries a schema shaped for a version it is not. Nothing about that is diagnosable from the symptom.

## Things that bite

**Starting a new image is applying its migrations.** There is no separate step, no confirmation and
no dry run. Back up first; the whole of [Backup and restore](backup-restore.md) is the prerequisite
for this page.

**`latest` moves only on releases, `main` moves on every merge.** Choosing `main` to "stay current"
opts into unreleased schema changes on a stack that migrates automatically.

**A failed migration is a container that does not start, not a broken app.** That is by design.
Read the logs of the container that *held* the lock; the others only say they were waiting.

**The version chip is absent, not "unknown", on an unstamped build.** A checkout that was never
built from a tag has no version, and saying so plainly beats a word that reads like something went
wrong. Do not read a missing chip as a broken deployment.

**Workers cache Doctrine metadata for their whole lifetime.** Anything that changes the schema
without recreating the containers needs Admin → System → restart, or the workers keep asking for
columns that are gone.

**`POSTGRES_VERSION` is not something to bump casually during an upgrade.** The image ships
`postgresql-client-18`, and `pg_dump` refuses to dump a server newer than itself — so a Postgres
raised past the client version breaks `app:backup` at the moment you next need it.
