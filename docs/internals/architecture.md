# Architecture

The layers, what lives where, and the rules that keep it that way. This page is about the
shape of the codebase rather than about any one feature; the feature pages —
[Mail ingest](mail-ingest.md), [The calendar model](calendar-model.md),
[The sync engine](calendar-sync-engine.md), [Event extraction](event-extraction.md),
[JMAP](jmap.md) and the [Security model](security-model.md) — assume it.

`CODESTYLE.md` §5 states these rules as rules. What follows is why each one is worth the
friction it costs, and where the codebase enforces it rather than merely asking.

## The tree

```
src/
  Command/        Console commands, grouped by area (Mail/, Imap/, Push/, Calendar/, Maintenance/…)
  Controller/     HTTP actions, grouped by area (Mail/, Admin/, Settings/, Sharing/, Webhook/…)
  Domain/         The vocabulary: Enum/, DTO/, Interface/, Exception/, Model/, Trait/, Helper/, Filter/
  Entity/         Doctrine entities, grouped by area (Mail/, Label/, User/, Calendar/, Monitoring/)
  Form/           Symfony form types
  Infrastructure/ Framework-facing wiring: Doctrine/, Messaging/, Event/, Scheduler/, Setup/, Encryption/
  Jmap/           A protocol implementation, self-contained, with its own Method/, Mapper/, Protocol/
  Repository/     One per entity, mirroring Entity/'s grouping
  Security/       Authenticators, user provider, two-factor
  Service/        Everything that decides something. The largest layer, by design.
  Twig/           Extensions and runtime helpers (Vendor/ for lifted upstream code)
```

Two boundaries carry most of the weight.

`Domain/` holds no framework types. It is the vocabulary the rest of the application argues
in: the enums that own their per-case rules, the `final readonly` DTOs that cross layers, the
interfaces for the axes that vary, and the exception hierarchies. Because it depends on
nothing, everything can depend on it — a driver under `Service/Calendar/Sync/Google/` and a
JMAP method under `Jmap/Method/Calendar/` both speak `CalendarSource`, `RemoteEvent` and
`EventStatus` without either learning about the other.

`Infrastructure/` holds the glue that only exists because Symfony and Doctrine exist:
`src/Infrastructure/Doctrine/Type/EncryptedStringType.php`, the Messenger message/handler
pairs, the event subscribers, `src/Infrastructure/Scheduler/MaintenanceSchedule.php`, and the
boot-time probes under `src/Infrastructure/Setup/`. Nothing here decides what an operation
means.

`Service/` is deliberately the biggest directory. Grouping is by domain area first and kind
second — `src/Service/Mail/`, `src/Service/Gmail/`, `src/Service/Calendar/Sync/CalDav/` —
which is what makes "add a provider" a new directory rather than eleven files dropped into a
flat one.

## Controllers resolve, authorise, delegate, render

A controller does not decide what an operation means. The commit that established this is
titled *"Leave controllers with their actions and little else"*, and the rule shows up as
three habits: route attributes on the class for the shared prefix and on the method for the
rest, `#[IsGranted]` at class level with per-action overrides only where they genuinely
differ, and ownership assertion in a single private resolver so no action can forget it.

The rule has teeth because the alternative has bitten. `Thread/set` in JMAP and the web
snooze button both go through `App\Service\Mail\ThreadSnoozeService`; the web endpoint used
to write `MessageThread::$snoozedUntil` directly and nothing else, which left the
conversation sitting in the Inbox — locally and at the provider — while the row vanished
from the list, until the sweep "woke" a thread that had never left. Where two callers must
genuinely differ, the difference is named at both ends: a form post gets an "in 1 day"
fallback on an unparseable date where `ThreadSetMethod::snoozeDate()` refuses it, and each
side's comment points at the other.

## Every query lives in a repository

There is no `createQueryBuilder` outside `src/Repository`. A query used twice is a named
repository method; a query used once but with a reason is a named repository method whose
docblock gives the reason. `App\Repository\Calendar\CalendarEventRepository` is the clearest
example of the payoff — `findOneByRemoteId()`, `findOneByUid()`, `findPendingSync()`,
`findRemoteRowsNotIn()` and `findRowsTheRemoteNeverGave()` each carry the paragraph
explaining what population it selects, and the sync engine reads as a sequence of decisions
rather than as a sequence of queries.

Raw DBAL is allowed and says why it had to be raw. `CalendarEventRepository::findOneByRemoteInstanceId()`
tests jsonb key existence, which has no DQL operator and no registered function; it is
written `jsonb_exists()` rather than the `?` operator that means the same thing, because DBAL
reads a bare `?` as a positional placeholder and refuses the query. `CalendarAlertDeliveryRepository::claim()`
is raw because the whole point is one `INSERT … ON CONFLICT DO NOTHING` statement, which the
ORM has no way to express.

## Interfaces for the axis that varies

`src/Domain/Interface/` holds one interface per pluggable axis, and the list is short on
purpose:

| Interface | The axis |
|---|---|
| `AccountSyncerInterface` | how mail is fetched for one account |
| `MailSenderInterface` | how mail leaves |
| `PushSubscriptionManagerInterface` | push registration for a mail **account** |
| `CalendarPushSubscriptionManagerInterface` | push registration for one mirrored **calendar** |
| `CalendarSyncDriverInterface` | one kind of remote calendar |
| `IntegrationDriverInterface` (+ `VerifiableDriverInterface`, `SearchableDriverInterface`, `TimelineDriverInterface`) | one external file or photo service |
| `EventExtractorInterface` | one way of finding events in a message |
| `ProposalDetectorInterface` | one way of reading a date out of prose |
| `PostIngestStepInterface` | something that wants to react to newly ingested mail |
| `AlertChannelInterface` | one way an alert reaches a person |

Implementations live under the provider's own directory and are resolved by a registry —
`MailSenderRegistry`, `IntegrationDriverRegistry`, `CalendarSyncDriverRegistry`,
`CalendarPushRegistry` — each of which takes a tagged iterator and returns the first
implementation that claims the subject. Adding a provider is a directory, never an edit to a
`switch`.

Two of the splits above are worth reading as arguments rather than as a table.

**Push is two interfaces, not one widened one.** `CalendarPushSubscriptionManagerInterface`
exists beside `PushSubscriptionManagerInterface` because the subject is different, not
merely narrower: every method over there takes an `Account` and reads columns on `Account`,
while Graph subscribes to `me/calendars/{id}/events` and one Microsoft mail account can
mirror six calendars, each needing its own subscription, secret and expiry. Widening to
`Account|Calendar` would open every method in `GmailPushSubscriptionManager` and
`GraphSubscriptionManager` with an `instanceof` — a compile-time contract turned into a
runtime cascade. The calendar interface also deliberately has no `messageKey()`: the mail
contract has one because the accounts settings page renders per-provider copy for a control
the user operates, and calendar push has no control.

**Search and timeline are separate from the file driver.** `SearchableDriverInterface` and
`TimelineDriverInterface` are not folded into `IntegrationDriverInterface` because a WebDAV
share has nothing to offer either, and folding them in would force five drivers to carry a
method that throws. `VerifiableDriverInterface` was split out the other way — `verify()` is
the one thing every connection owes whatever it connects to, and a CalDAV calendar driver has
to answer it without pretending to hold files.

`config/services.yaml` carries the tagging, and its `_instanceof` block is where the
boundaries are stated in enforceable form. It says in as many words that a calendar sync
driver is **not** an integration driver and must not be tagged as one, that a calendar push
manager is neither, and that an alert channel is none of the three.

## DTOs cross boundaries

Anything passing between layers with more than two fields is a `final readonly` class under
`src/Domain/DTO/`, not an array. The docblock says what it carries that its members do not
obviously imply — `App\Domain\DTO\Mail\IngestedMessage` carries the owning account rather
than letting the pipeline read it off the message, because under Gmailify a Gmail account
fetches mail addressed to a sibling and the two are not the same one.

The strongest instance is `App\Domain\DTO\Calendar\SharedOccurrence`, where the DTO **is**
the security control rather than a convenience: a public template never receives a
`CalendarEvent`, so a busy/free link cannot leak a title through a tooltip, a data attribute,
a JSON payload or an `.ics`, because the object being rendered has not got one. See the
[Security model](security-model.md).

## Messenger: three transports and three workers

`config/packages/messenger.yaml` declares three live transports, and the split is about who
is waiting:

| Transport | Carries | Retry |
|---|---|---|
| `export` | anything leaving plMail — sends, flag/label propagation, attachment uploads, mail and notifier messages | 2s base, ×3, 5 attempts, 60s cap |
| `ingest` | mail coming in and the work that immediately follows it, plus calendar sync | 5s base, ×3, 5 attempts, 300s cap |
| `maintenance` | backfills, rule runs over existing mail, `RunCommandMessage`, calendar push registration | as `ingest` |

`export` is tighter on purpose: its failures are a relay refusing a connection or a provider
blinking, which clear in seconds, and somebody is watching the outcome. `ingest`'s window
used to be the Symfony defaults — 1s/2s/4s — a seven-second span that exhausted every attempt
before a rate limit had any chance to end, so a recoverable failure dead-lettered as reliably
as an unrecoverable one. `max_delay` is kept inside the worker's `--time-limit=3600` so a
delayed retry is never left waiting on a restart.

Separate transports are not enough on their own. A worker already inside a long handler
cannot pick up a send however it is prioritised, so each transport has its own process:
`worker-export`, `worker-ingest` and `worker-maintenance` in `compose.yaml`. A fourth
transport, `async`, is kept routing nothing, so envelopes queued before the split still have
somewhere to land; the maintenance worker drains it.

Two routing decisions are load-bearing rather than tidy:

- **`SyncCalendarMessage` goes to `ingest`, not `export`,** although a calendar sync writes
  outward as well as reading. Nobody waits on it — a local edit is saved and on screen before
  the push is dispatched — so putting it on the send queue would weaken that queue's one
  promise.
- **`RegisterCalendarPushMessage` goes to `maintenance`,** beside the `SyncCalendarMessage`
  dispatched for the same calendar. On `ingest` it would queue behind that first full
  calendar read, so the push channel would open minutes after the subscribe on exactly the
  large calendars where push matters most. Routing it *at all* is the point: an unrouted
  Messenger message is handled in the process that dispatched it, which would put a call to
  Google or Microsoft inside the HTTP request that ticked the calendar.

`ApplyGmailLabelsMessage` was unrouted until recently, which meant every Gmail label change —
archive, trash, star, mark read — made a live Google API call inside the HTTP request the
user was waiting on, while its IMAP and Graph counterparts were queued. The same click
behaved differently depending on which account it landed on.

Messages themselves are `readonly` and carry **ids and scalars only**, never entities, and
pair 1:1 by name with their handler (`SyncAccountMessage` / `SyncAccountMessageHandler`).
That is not style: handlers run on long-lived workers that clear the entity manager between
envelopes, so a serialised entity is a reference to a manager that no longer exists.

## The scheduler

`App\Infrastructure\Scheduler\MaintenanceSchedule` is the one place recurring work is
declared. It is consumed by `messenger:consume scheduler_default` — the `scheduler` service
in `compose.yaml` — and **nothing runs these otherwise**, which is the state the project was
in before, with logs and orphaned blobs growing without bound.

| Cron | Command | Why that cadence |
|---|---|---|
| `*/15 * * * *` | `app:mail:sync` | Neither Gmail push nor Graph subscriptions guarantee delivery and IDLE connections drop; polling is the backstop |
| `7-59/15 * * * *` | `app:calendar:sync --stale` | The mechanism for CalDAV and ICS feeds, the backstop for Google and Graph. Offset off the quarter hour so it does not stack on the mail sweep — they share one worker |
| `20 * * * *` | `app:calendar:push` | Hourly, not because anything expires that fast, but because registration fails for *deployment* reasons that have nothing to do with the click that connected the calendar |
| `* * * * *` | `app:mail:wake-snoozed` | A minute is the unit people pick a wake time in |
| `* * * * *` | `app:calendar:alerts` | Same argument, plus the interval is the bound on how late a reminder can be |
| `0 4 * * *` | `app:push:renew --repair` | Gmail watches last 7 days, Graph subscriptions ~3 |
| `50 3 * * *` | `app:calendar:materialise` | Rolls the occurrence horizon forward, so a repeating event does not quietly run out of dates |
| `30 4 * * *` | `app:monitoring:prune` | Log entries and dead heartbeats |
| `0 5 * * 0` | `app:prune:blobs` | Weekly; it walks three directory trees and a week of orphans is a rounding error |

The schedule is `stateful()` against the cache and `processOnlyLastMissedRun(true)`, so a
worker that was down over a scheduled run catches up rather than silently skipping the day —
but only once, because these are all idempotent sweeps and replaying a backlog five times
over is pure waste. Times are spread across the small hours rather than stacked on midnight,
for the same reason the calendar sweep is offset: one worker, and a long prune should not
hold up a sync.

Every one of these commands is also runnable by hand, and every command in the tree is listed
in `CONTRIBUTING.md` with a one-line description. That table is part of the definition of
done.

## Entities, and the invariants that are structural

Attributes only, `Types::` constants, `enumType:` for enums, and `onDelete` declared at the
join column so the database enforces what the code assumes. State is public, with
`public private(set)` where the outside must not write; there are eight `public function get…`
methods in the entire `src/Entity` tree and each is doing something a property cannot.

Two habits are worth calling out because they are where correctness actually lives.

**Every index and unique constraint carries a comment saying what it protects and why the
columns are in that order.** `uniq_calendar_booking_page_start` on `calendar_booking` is not
an optimisation — it is the only thing that stops two strangers taking the same half hour,
and the page id leads because the constraint is about one page's slots.
`uniq_calendar_event_calendar_uid` scopes UID uniqueness to one calendar, which is what makes
a copy of a meeting on a second calendar legal rather than merely tolerated.

**Timestamps come from `App\Domain\Trait\TimestampableTrait`, on every entity, with no
exceptions** — including the tables written once. One rule for every entity is worth more
than the bytes an exception saves, and nothing has to decide which kind of entity it is
looking at. The trait needs `#[ORM\HasLifecycleCallbacks]` on the adopting class and Doctrine
silently does nothing without it, so `TimestampableTest` checks every adopting entity for the
attribute — the requirement the trait cannot enforce itself.

## Things that bite

**A new Messenger message with no routing entry runs synchronously.** Symfony handles an
unrouted message in the dispatching process, so a job written to get work out of an HTTP
request silently stays in it. `config/packages/messenger.yaml` names this for
`RegisterCalendarPushMessage` and `CalendarSubscriberTest` asserts the routing; a new message
gets neither for free.

**A new command in `MaintenanceSchedule` does nothing until the `scheduler` service is
running.** It is a separate container consuming `scheduler_default`. `php bin/console
debug:scheduler` shows the next run of each, and is the fastest way to find out that the
answer is "never".

**A new tagged implementation inherits its registry's ordering rules.** The registries take
the *first* implementation that claims the subject, so a `supports()` written too broadly
silently steals another driver's work rather than failing. `MailSenderRegistry` orders by
explicit tag priority (`GmailApiSender` at 10, `SmtpMailSender` at 0) precisely because
declaration order is not a contract.

**A `_instanceof` tag is inherited by anything implementing the interface.** That is why
`VerifiableDriverInterface` is tagged `app.integration_driver` and
`CalendarSyncDriverInterface` deliberately is not: a calendar driver that also implements
`VerifiableDriverInterface` — `CalDavCalendarDriver` and `IcsUrlCalendarDriver` both do —
must be reachable by the connect and test paths without appearing in the file-picker
registry.

**A repository method used once is still a repository method.** The temptation to inline a
`findBy()` in a service is what erased the reasoning from the sync engine's predecessors:
`findRemoteRowsNotIn()` and `findRowsTheRemoteNeverGave()` are exact complements, and which
one a caller wants is a decision with a paragraph behind it, not a filter to retype.

**Adding an enum case is only safe where the `match` is exhaustive.** The pattern in this
codebase is `match ($this)` over every case with no `default`, so a new case is a
compile-time-ish error. Where somebody wrote a predicate as a comparison instead — the
`self::Llm !== $this` that `EventSource::isTrusted()` used to be — the next case silently
inherited the permission the method existed to withhold.
