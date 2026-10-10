# Gmail quota and sync warnings

Admin → Integrations → Gmail contains the per-user/per-project quota budget and
safety headroom. Defaults are 6000 units/minute and 20%. This is a local plMail
limit; it does not read or increase Google Cloud quotas or request IAM permissions.
The supported budget range is 3000–15000, with 0–50% headroom. Use the older 15000
budget only when the installation's actual Google quota has been confirmed.

Google's current [quota reference](https://developers.google.com/workspace/gmail/api/reference/quota)
prices messages.get (including raw and attachment reads) at 20 units. Every Gmail
API operation implemented in plMail is accounted for, including labels, history,
profile, watch/stop, bulk modifications and sending. A multipart fetch charges each
inner request. The [batch guide](https://developers.google.com/workspace/gmail/api/guides/batch)
recommends no more than 50 requests; new jobs and previously queued larger jobs
are split accordingly. No HTTP retries are hidden inside the client.

The shared `gmail_quota_state` table is updated in short row-lock transactions.
All web and worker processes using this database coordinate a bucket whose initial
1000-unit burst plus one minute of refill fit the budget after headroom. Locks
are released before pacing sleeps. Other applications using the same Google
account are outside this budget: headroom reduces risk but cannot guarantee that
Google will never throttle. Google's independent project-wide quota is not queried
or raised by these settings.

Bucket identity uses the lowercased account address and OAuth client project
number when present in the standard client ID. Multiple app clients in one project
and duplicate local accounts for the same address share state. Nonstandard client
IDs use the full ID; missing IDs use a common unknown-project group. Gmail's
stable Google subject is not currently stored, so aliases that have different
stored addresses cannot safely be inferred to be one identity. No credentials
are read to form these keys. Key/configuration queries are fresh on every call;
workers need no restart after a settings edit. Settings are part of the existing
provider configuration backup.

Quota 403 and 429 set a shared cooldown, including per-part batch responses.
Delta and HTTP-date Retry-After are honored. Whole-job retries use bounded
exponential backoff with positive jitter; the existing finite Messenger retry and
throttling deferral limits still apply. Partial retries carry their own attempt
counter, honor cooldown and stop after five retries. Successful parts are ingested;
an exhausted partial job is retained as a failed job, with a persistent incomplete
sync warning. Permanent permission/daily-limit 403 responses are not treated as
transient quota errors. Malformed or missing successful parts are retried rather
than considered deleted. No existing failed jobs are changed by installation.

Pending message IDs are registered before a fetch. A successful unrelated fetch
cannot clear a warning while those messages remain unavailable. Recovery removes
only IDs that were successfully fetched or demonstrably gone. Mail views (including
an empty Inbox) show warnings for the current user's active accounts, and Settings
Health reports the condition immediately. Stored mail remains accessible. A known
cooldown is shown as the earliest allowed retry time, never as a promise of when
mail will appear. `lastSyncedAt` records successful discovery/sync planning; it does
not claim that every queued message has already downloaded. Gmail quota does not
block the separate Calendar API or imply a host, port or password error.

## Things that bite

IMAP is not switched on automatically. Gmail supports [IMAP OAuth](https://developers.google.com/workspace/gmail/imap/xoauth2-protocol) with the existing
mail scope, but IMAP has separate [bandwidth limits](https://knowledge.workspace.google.com/admin/gmail/gmail-bandwidth-limits) and a folder model
that would need explicit mapping and duplicate handling alongside Gmail labels.
A deliberate fallback, switching criteria, and return to Gmail API are a separate
migration/design task, not a way to raise the API quota.

The migration preserves live quota/cooldown state on rollback rather than silently
resetting credit; stop workers and wait for budgets/cooldowns to expire before
manually removing its table. Tests use MockClock, MockHttpClient and a disposable
Postgres database; no real Gmail calls or mail sends are required.
