# Platform notes

plMail runs the same containers everywhere, so most of this page is about the two things that
genuinely differ between a Linux server, a Mac, Windows via WSL2 and a NAS: **who owns the files
behind a bind mount**, and **how much memory the Docker host is actually willing to give the
stack**. Start from [Docker Compose](docker.md); this covers only the deltas.

Images are published for `linux/amd64` and `linux/arm64` as one manifest, so every platform below
runs plMail natively. There is no QEMU step to plan around, on an Apple Silicon Mac or on an ARM
NAS.

## What the stack asks of the host

The stock file starts eight long-running containers — the web server, the IMAP supervisor, four
Messenger workers, Postgres and the Mercure hub — plus `secrets-init`, which exits. Under the
optional `push` profile there is a ninth, ntfy.

The numbers that are actually written down: PHP's `memory_limit` is `2G` in
`frankenphp/conf.d/10-app.ini`, each Messenger worker is bounded by `--memory-limit=256M` and
recycles at `--time-limit=3600`, and `upload_max_filesize` is `25M` with `post_max_size` at `60M`.
Postgres runs with `shared_preload_libraries=pg_stat_statements` and whatever the image's own
defaults give it.

**The failure mode is a host that overcommits and then OOM-kills.** A worker killed mid-handler
leaves its message to be redelivered, which is survivable; the container that gets killed holding
the migration advisory lock is not — every other container waits out the five-minute lock timeout
and then refuses to migrate.

## Linux server

The straightforward case, and the one the stock `compose.yaml` is written for: every durable path
is a **named volume**, which Docker creates and owns, so there is nothing to `chown` and no
ownership question to answer.

The containers run as root internally. The entrypoint tries to set POSIX ACLs on `var/`
(`setfacl -R -m u:www-data:rwX …`) and treats failure as a note rather than an error — running as
root, it can already write everything under `var/` regardless.

Two things change the moment you swap a named volume for a **bind mount**, which you will do if you
want the mail on a specific disk:

- **Postgres runs as uid 999** in `postgres:18-alpine` — it was uid 70 in older majors. It cannot
  create its own data directory inside a root-owned parent, so the parent has to exist with the
  right ownership before the container starts. `truenas.compose.yaml` solves this by having
  `secrets-init` lay out and `chown -R 999:999` the Postgres subdirectory before anything else
  runs; that pattern transplants to any bind-mounted deployment.
- **ACLs may not be supported.** On ZFS, NFS or anything using NFSv4 ACLs, `setfacl` fails with
  "Operation not supported". The entrypoint prints a note and carries on — it used to abort the
  boot, under `set -e`, which is why the failure is handled explicitly now.

**The failure mode is a bind mount whose parent Docker created as root.** Postgres exits during
initialisation with a permissions error that names a path, and the app containers then spend their
60 database attempts waiting for something that will never come up.

## Windows, via WSL2

Docker Desktop with the WSL2 backend runs the same Linux containers, so nothing about the stack
changes. Two host-side details do.

**Keep the project inside the WSL2 filesystem.** A path under `/home/...` in the distribution is a
native Linux filesystem; a path under `/mnt/c` is Windows' filesystem reached through a translation
layer, with different performance and different semantics for file locking and ownership. Named
volumes sidestep the question entirely, which is another argument for leaving the stock file's
volumes alone.

**Memory belongs to the WSL2 VM, not to Windows.** The figures in "What the stack asks of the host"
above come out of whatever WSL2 has been allowed to take, which is configured in `.wslconfig` and
not in Docker. A stack that behaves as if the machine were much smaller than it is usually means
that file.

If you also intend to run the browser test suite from Windows, watching a run needs WSLg on
Windows 11 or an X server; without one, use the trace-recording variant and read the recording
afterwards. That is a development concern only —
[CONTRIBUTING](../../CONTRIBUTING.md#tests) covers it.

**The failure mode is bind-mounting a Windows path into the containers.** It appears to work, and
then the file-locking behaviour underneath differs from what the entrypoint expects — the same
class of problem that made `flock` unusable as the dependency-install mutex on macOS.

## macOS

Apple Silicon pulls the `linux/arm64` image and runs it natively.

The relevant difference is again the filesystem behind a bind mount. Docker Desktop shares host
directories over VirtioFS, and two things there are documented in this repository because they were
measured rather than assumed:

- `setfacl` fails on a VirtioFS share, so the entrypoint's ACL step is skipped with a note.
- `flock` is advisory and does not reliably exclude *across containers* on such a mount. The dev
  entrypoint's dependency install uses `mkdir` as its mutex instead, because it is a single atomic
  operation that fails with `EEXIST`. Two containers were observed entering a `flock`-guarded block
  17ms apart before that changed.

Neither affects the published image, which bakes `vendor/` in and uses named volumes — they matter
if you bind-mount a source tree, which is the development setup.

As on Windows, the memory available is the VM's, configured in Docker Desktop, not the Mac's total.

**The failure mode is assuming a bind mount behaves like a local disk.** It does for reading and
writing; it does not for locks, ACLs or ownership, and every one of those has already broken
something here.

## NAS boxes

A NAS is squarely the kind of machine plMail is meant to run on, and the repository ships
`truenas.compose.yaml` as a worked example — read it even if your NAS is not a TrueNAS, because
every decision in it is one you will face.

**Ports.** A NAS whose own management UI already holds 80 and 443 needs plMail moved. Set
`HTTP_PORT` and `HTTPS_PORT` (and `HTTP3_PORT` if you serve HTTP/3 directly), or do what the
TrueNAS file does: give the `php` service `SERVER_NAME: ":80"` so FrankenPHP serves plain HTTP, map
that to a high port — `30080` there — and terminate TLS in front of it. See
[Behind a reverse proxy](reverse-proxy.md).

**One directory instead of six.** `truenas.compose.yaml` sets `APP_STORAGE_DIR=var/data` and points
`APP_SECRETS_DIR` and `APP_SECRETS_FILE` inside the same tree, then binds one host path at
`/app/var/data` on every service. The result is a single directory holding the secrets,
attachments, raw messages, uploads and the Postgres cluster — one thing to snapshot, one thing to
back up, and every service demonstrably seeing the same encryption key. Point it at a dataset
rather than a plain directory if you want snapshots of your mail.

**Ownership, again.** That file's `secrets-init` creates the subdirectories, `chown -R 999:999`s the
Postgres one, and then does something worth copying:

```sh
chmod o+x /app/var/data || setfacl -m u:999:--x /app/var/data || true
```

A dataset created with the TrueNAS "Apps" preset is `770 apps:apps`, which gives uid 999 no way to
traverse into the directory its own data lives in. Execute-only grants traversal without granting
the ability to list or read anything.

**Interpolation.** TrueNAS's YAML installer does not support `${VAR}`, which is why that file
hoists every setting into an `x-config` block and aliases it with YAML anchors. If your NAS's app
UI has the same limitation, the same trick applies; if it runs real `docker compose`, the stock
file and a `.env` beside it are simpler.

**Image tags matter more here**, because a NAS app page usually has a "redeploy" button and no
obvious version. `truenas.compose.yaml` sets `pull_policy: always`, so a redeploy picks up a newer
build of whichever tag is set. Choose the tag deliberately — see [Upgrading](upgrading.md).

**Android push without Google** is the one optional container most worth starting on a NAS:
`docker compose --profile push up -d` brings up ntfy, which owns the endpoint URL a UnifiedPush
distributor on the phone talks to. Its base URL is derived from `SERVER_NAME` and is baked into
every endpoint handed out, so getting `NTFY_BASE_URL` right before handing the address to phones
saves re-registering every device later.

**The failure mode is a NAS app UI that hides which containers exist.** The one to check for is
`scheduler`. An install missing it looks completely healthy and quietly never polls, never wakes a
snoozed thread, never syncs a calendar and never fires a reminder.

## Things that bite

**Only some of these platforms give you named volumes for free.** The stock `compose.yaml` uses
them, and its blob directories are not among them — see
[the storage section of the Docker page](docker.md#storage-and-what-the-stock-file-does-not-persist).
On a NAS you will almost certainly be bind-mounting instead, and then ownership is yours to get
right.

**Postgres uid 999 is not the container's user in every image.** It was uid 70 before the 18 series.
A `chown` copied from an older guide leaves the cluster unable to write.

**ACL failures are notes, not errors — but only since they were made so.** If you see "POSIX ACLs
are not supported on this filesystem; skipping setfacl for var/" in the logs, that is the expected
output on ZFS, NFS and Docker Desktop shares, and nothing is wrong.

**ARM is a first-class target and must stay that way.** Both architectures are built on native
runners and merged into one manifest, so anything you add to a self-built image has to resolve an
arm64 asset too.

**A stack that is fine for months and then stops on a first sync is a memory problem.** The first
sync of a large mailbox is the busiest plMail ever gets: it queues thousands of jobs, four workers
run concurrently against them, and each is allowed 256M before it recycles.
