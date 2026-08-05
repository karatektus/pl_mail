# Troubleshooting

What `/healthz` means, how to tell a stuck queue from an empty one, where the logs actually are, and
the failures that have already happened to somebody here.

## `/healthz`

```
GET /healthz
```

```json
{
  "status": "ok",
  "checks": { "database": true, "queue": true, "workers": true }
}
```

Unauthenticated, because Docker healthchecks and uptime monitors hold no session — and therefore
deliberately vague. It answers only what such a caller could infer by trying the app anyway: no
counts, no addresses, no account names, no version. `/admin` is where the numbers live, behind
`ROLE_ADMIN`, and a test asserts this shape so an addition that leaks something fails the suite.

| Field | Meaning |
|---|---|
| `status` | `ok` with 200, or `error` with 503 |
| `database` | whether the database is reachable. **The only thing that sets the status code** |
| `queue` | `true` while fewer than 5000 messages are pending across all queues, `false` past that, `null` when it could not be determined |
| `workers` | `true` when every process that has ever reported a heartbeat is still beating, `false` when one is stale, `null` when there are no heartbeats at all |

**503 only when the database is down**, because that is the one failure where serving is impossible.
A backed-up queue stays 200 on purpose: mail is late, not gone, and restarting the container would
not help — it would take a working instance out of rotation and lose the in-flight work as well.
The 5000 threshold is generous for the same reason a first sync of a large mailbox legitimately
queues thousands of jobs and must not read as an outage.

`null` is not `false`. A monitor should be able to tell "the queue is backed up" from "I could not
look", and an install that has never run a worker has no heartbeats — nothing has failed, there is
simply nothing to report yet.

The image's `HEALTHCHECK` points here, with a 60-second start period. It used to probe Caddy's
metrics port, which answers as soon as the web server is listening — well before PHP can reach the
database — so a stack with an unreachable database reported itself healthy and
`depends_on: service_healthy` waited for nothing. The worker services have no HTTP server and
disable the healthcheck entirely; their liveness reaches this same endpoint through heartbeats.

**The failure mode is alerting on the status code alone.** A dead scheduler and a stalled queue both
answer 200. Watch the `checks` object, not just the HTTP status.

## The queue

Four transports on the Doctrine transport, each with its own worker process:

| Queue | Container | What is on it |
|---|---|---|
| `export` | `worker-export` | Anything leaving plMail — sends, flag pushes, Gmail label changes, mail from the notifier. The only queue somebody is waiting on |
| `ingest` | `worker-ingest` | Mail arriving, Gmail and Graph message batches, calendar syncs, event extraction |
| `maintenance` | `worker-maintenance` | Backfills, rule runs over existing mail, admin "run now" buttons, calendar push registration |
| `async` | `worker-maintenance` | Retired. Drained so envelopes queued before the split still have a consumer; nothing routes here any more |

Three processes rather than three transports on one worker, because a worker already inside a long
handler cannot pick up anything else however the queues are prioritised — which is why pressing Send
used to wait for a Gmail batch to finish.

From a terminal:

```bash
docker compose exec php php bin/console messenger:stats
docker compose exec php php bin/console messenger:failed:show
```

The **queue panel under Admin** is the better view when mail stops arriving, because it distinguishes
the two states a count cannot: it names the messages a worker is holding *right now* — the handler,
its payload and how long it has been held — above the list of everything still waiting. A queue that
is stuck therefore looks different from a queue that is merely deep. See
[Administration](../features/admin.md).

Retries are bounded and differ per queue: `export` gives five attempts over roughly a minute and a
half, because its failures are a relay refusing a connection and somebody is watching; `ingest` and
`maintenance` give five attempts over about eight and a half minutes, because theirs are third-party
rate limits that take minutes to clear. What exhausts them lands in the `failed` transport, which the
admin panel can retry or purge.

**The failure mode is a queue with no consumer.** Nothing errors — messages accumulate and the app
looks fine. This has happened: a dev stack whose override still defined the pre-split
`messenger-worker` ran a worker consuming only the retired `async` queue, so sends and syncs piled
up with no error anywhere.

## The scheduler

The recurring jobs live in `App\Infrastructure\Scheduler\MaintenanceSchedule` and are dispatched by
one container consuming `scheduler_default`. **Without that container, none of them fire.**

| Cadence | Command |
|---|---|
| every minute | `app:mail:wake-snoozed` |
| every minute | `app:calendar:alerts` |
| `*/15` | `app:mail:sync` |
| `7-59/15` | `app:calendar:sync --stale` |
| hourly at :20 | `app:calendar:push` |
| daily 03:50 | `app:calendar:materialise` |
| daily 04:00 | `app:push:renew --repair` |
| daily 04:30 | `app:monitoring:prune` |
| Sundays 05:00 | `app:prune:blobs` |

```bash
docker compose exec php php bin/console debug:scheduler
```

The schedule is stateful, so a worker that was down over a scheduled run catches up when it comes
back rather than skipping the day — and only the last missed run is replayed, because these are all
idempotent sweeps and running yesterday's backlog five times over would be waste. Times are spread
across the small hours rather than stacked on midnight because they share one worker.

**The failure mode is the symptom list, not an error.** No mail poll, no snooze ever waking, no
calendar sync, no reminders, no log pruning, no blob cleanup — all at once, and all silent. This is
the state the project was in before the scheduler existed, with logs and orphaned blobs growing
without bound. If several unrelated things "stopped working", check for this container first.

## Workers and heartbeats

Liveness is heartbeat-based rather than process-based, because the web container cannot see into the
others. Each long-running process writes a row keyed by `APP_CONTAINER_NAME`, and staleness is
per type:

| Process type | Stale after |
|---|---|
| `imap-idle` | 2100s — just over the 29-minute IDLE reissue |
| `imap-supervise` | 300s |
| `messenger-worker` | 120s — the listener beats every 30s |
| anything else | 600s |

A row four times past its threshold is reaped entirely, which is wide enough that a briefly wedged
process shows up red on the dashboard before it disappears.

Workers exit on `--time-limit=3600` and are restarted by Compose, so a heartbeat gap of a second or
two around the hour is normal. **Admin → System → restart workers** asks all of them to exit at the
end of their current message; the app has no Docker socket and does not need one, because
`restart: unless-stopped` means exiting *is* restarting.

**The failure mode is `APP_CONTAINER_NAME` being unset.** The key falls back to the hostname, which
changes every time a container is recreated, so the dashboard fills with dead workers that were never
really dead.

## Where the logs are

**Container output.** In `prod`, Monolog writes JSON to `php://stderr`, so everything is in
`docker compose logs`:

```bash
docker compose logs -f php
docker compose logs -f worker-ingest
docker compose logs -f scheduler
```

The main handler is `fingers_crossed` at `error` with a 50-message buffer, which means routine info
lines are discarded *until* something errors — and then the 50 preceding messages are flushed with
it. That is why an error in the log usually arrives with its own context attached, and why you will
not find a quiet trace of an operation that succeeded. Deprecations go to stderr on their own
channel. In `dev` the same output goes to `var/log/dev.log`.

**The admin log browser.** A second handler writes into the database, and **Admin → Logs** searches
it: 100 entries a page, filterable by minimum level from info to critical, with the container name on
each row. The minimum level kept is `APP_DB_LOG_LEVEL`, `warning` by default. Anything at warning or
worse that nobody has read outlines the user menu — amber or red — on every page, for administrators
only. Opening the browser is what marks them seen.

`app:monitoring:prune` keeps 14 days of log entries and 30 days of heartbeats by default, nightly.

**The failure mode is looking for an info-level trace in production.** `fingers_crossed` means it was
never written unless an error followed it. Lower `APP_DB_LOG_LEVEL` to `info` temporarily if you need
the database side to keep more, and put it back — at `debug` the table grows quickly.

## Diagnosing a specific account

```bash
docker compose exec php php bin/console app:mail:test-connection      # IMAP/SMTP probe
docker compose exec php php bin/console app:imap:test --account=ID    # connection and folder listing
docker compose exec php php bin/console app:graph:diagnose            # what Graph actually permits
docker compose exec php php bin/console app:mail:sync ACCOUNT_ID      # dispatch a sync by hand
```

For Gmail push specifically, the admin area's **Gmail webhooks** panel shows the topic, the exact
endpoint Pub/Sub must call, and why any notification was refused.

**The failure mode is testing from the wrong container.** These commands are fine through
`docker compose exec php`, which loads the generated secrets through composer's autoload files. A
bare `docker run` against the image is not — it has no secrets volume, so it holds a different
encryption key and cannot read a single stored credential.

## Failures that have actually happened

| Symptom | Cause |
|---|---|
| Attachment downloads 404, blob data vanishes when a container is recreated | The blob directories are not on a shared volume. See [Docker Compose](docker.md#storage-and-what-the-stock-file-does-not-persist) |
| Three of six containers never start, logs show `column … already exists` | Concurrent boot migrations. Fixed by the advisory lock in `app:db:migrate`; if you see it now, something is running `doctrine:migrations:migrate` directly |
| A container refuses to start: "APP_ENCRYPTION_KEY cannot decrypt the credentials already stored" | A service missing the `app_secrets` mount, or a key that changed under a running stack |
| The entrypoint aborts before starting anything, after a message about ACLs | Was a `setfacl` failure on ZFS, NFS or a Docker Desktop share under `set -e`. It is a note now, not an error — if you see the note, nothing is wrong |
| A stack reports itself healthy while the database is unreachable | The old healthcheck probed Caddy's metrics port. `/healthz` replaced it |
| Everything works but nothing ever updates by itself | `MERCURE_PUBLIC_URL` pointing somewhere the browser cannot reach. See [Behind a reverse proxy](reverse-proxy.md) |
| Gmail push returns 403 for every notification | `GMAIL_PUBSUB_VERIFICATION_TOKEN` unset or not matching the `?token=` on the Pub/Sub subscription. It fails closed |
| Calendar push never registers, warning at registration time | `APP_PUBLIC_URL` not HTTPS, or a loopback host, or a Google Cloud project whose domain verification is pending |
| **No reminders at all, for anyone, once a minute** | One account whose display address was an IMAP username rather than an address threw while the alert mail was being built, ending the whole sweep and losing the alerts already claimed in that batch. Fixed at both ends — the message is built inside the channel's own `try`, and the deliverer survives a channel that throws anyway |
| Sends and syncs queue and nothing drains them, no error anywhere | A worker consuming a queue nothing routes to. Check `messenger:stats` against the four transports above |

**The failure mode common to most of that table is silence.** Almost none of these produce an error
on a page; they produce an absence. That is why the admin panel reports queue depth and heartbeats
rather than only errors.

## Things that bite

**A 200 from `/healthz` means "keep serving", not "everything works".** Only the database decides
the status code. Every worker can be dead behind a 200 — deliberately, because the app still serves
mail that is already synced.

**`workers: null` on a fresh install is correct.** Nothing has beaten yet. It becomes meaningful
only after the workers have run once.

**The queue threshold is 5000 for a reason.** Lowering it to catch problems earlier means a first
sync of a large mailbox reports itself as an outage and an orchestrator restarts a healthy
container.

**A missing `scheduler` container is invisible in every panel that shows errors.** Nothing fails;
things merely never happen. `debug:scheduler` and the heartbeat list are where it shows.

**`app:reset --full` is not a troubleshooting step.** It goes back to first-run state: every table,
every user, the stored files. It exists so an install whose unreadable data is genuinely expendable
can start over, and the encryption-key probe deliberately allows it to run — see
[CONTRIBUTING](../../CONTRIBUTING.md#when-the-keys-disagree).

**Logs from `docker compose logs php` are only the web container's.** Six containers run the same
image, and the interesting line is usually in whichever worker owns the queue the work was on.
