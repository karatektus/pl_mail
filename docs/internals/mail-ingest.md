# Mail ingest

Provider to database: how bytes become a `Message` row, what happens to it once it exists,
and why every organising concept in plMail is a label. For what the result looks like to a
user, see [Mail](../features/mail.md); for connecting the accounts, see
[Accounts and aliases](../features/accounts.md).

## Three syncers behind one interface

`App\Domain\Interface\AccountSyncerInterface` has two methods, `supports(Account)` and
`sync(Account): list<int>`, and the return value is the list of mailbox ids that were
touched so the caller can fire per-mailbox follow-up work. `SyncAccountMessageHandler` takes
the tagged iterator, picks the first syncer that claims the account, and knows nothing about
any provider.

| Syncer | Claims | Shape of the sync |
|---|---|---|
| `App\Service\Imap\ImapAccountSyncer` | anything not Gmail and not Microsoft | folder discovery first, then per-mailbox message sync |
| `App\Service\Gmail\GmailAccountSyncer` | `Account::isGmail()` | label list first, then `historyId` delta plus a resumable backfill |
| `App\Service\Graph\GraphAccountSyncer` | `Account::isMicrosoft()` | folder discovery, then a per-folder `delta` chain |

The three differ in more than protocol, and the differences are where the bugs live.

**IMAP syncs structure and content in one entry point.** `ImapAccountSyncer` runs
`MailboxSyncer` before `MessageSyncer` so folders created by another client appear and get
their label chains before any message tries to resolve into one. `MessageSyncer` fetches in
batches of 50 and hands the raw RFC822 bytes forward, because webklex keeps them after
parsing — which is why IMAP is the only path that stores the original for free.

**Gmail has no `Mailbox` rows at all.** `GmailApiSyncer` plans work directly on the account
and fans out `SyncGmailMessageBatchMessage` jobs of 100 ids each, listing at the API's page
size of 500. The account's `gmailHistoryId` is snapshotted *before* any message is fetched,
and it means only "where incremental sync resumes" — never "the backlog is in", which is
`backfill()`'s own state with its own `BACKFILL_COOLDOWN` of an hour and
`BACKFILL_MAX_ATTEMPTS` of 24. Treating a present `historyId` as "fully synced" is what used
to strand an account on whatever the first run happened to fetch.

**Graph has no account-level cursor.** There is no equivalent of a single `historyId`, so
delta state is per folder, stored as a `folderId => deltaLink` map on the `Account`. A delta
query with no stored link enumerates the folder and hands back one; the same call with a link
returns only changes, so one code path covers both the initial and the incremental case.

Graph's folder moves are the sharp edge. Moving a message out of a folder appears as
`@removed` on the source folder's delta and as an addition on the destination's, and the two
were assumed to reconcile. They only do where both carry the same id — and immutable ids are
exactly what a personal outlook.com mailbox does not reliably give. The detach half regularly
matched nothing, and old location labels accumulated: deleted drafts sat in Drafts and Trash
at once. So the attach is **exclusive on its own** and does not wait for its partner: the
last folder a message was seen in is the folder it is in, which is what Exchange means
anyway.

Batch handlers deduplicate in PHP before inserting, but that check is a read on stale data.
The guard that actually holds when batches overlap across runs or retries is
`uniq_message_gmail_id_account` and its Graph counterpart on `message`, with the provider id
leading the column list so the index also serves the id-only lookups.

## The post-ingest pipeline

Once a batch of `Message` rows exists and has been flushed, `App\Service\Mail\PostIngestPipeline`
runs the shared pass. The three sync paths used to carry a copy of it each; they agreed,
which was exactly the problem — the ordering is subtle enough that three copies is three
chances to diverge, and anything wanting to react to new mail had to be wired into all three.

The precondition is stated in the docblock and is not negotiable: **the caller has already
persisted and flushed every message.** Ids must exist before threading queries them, and
`MailRuleEngine` matches in SQL against `search_vector`, a generated column, so a message
that has not reached the database is invisible to the user's own rules.

Per message, in order:

1. `MailBodySanitizer::sanitize()` fills `bodyHtmlSafe`. The unsanitised `bodyHtml` is kept
   deliberately — see below.
2. `RawMessageResolver::store()`, but only when the caller supplied bytes. Gmail and Graph
   pass null and fetch lazily on first use, because for them it is a second API call.
3. `MailChangeRecorder::emailChanged(..., created: true, thread: null)` — the JMAP create,
   with **no thread**, because `assignThread()` has not run and a thread it creates has no id
   until the next flush. Reading one early published id 0 to every connected client.
4. `MessageCategorizer::categorize()`.
5. `MessageThreader::assignThread()`, wrapped in a `try` — a threading failure is logged
   against the message, never allowed to cost the batch.

Then, once for the whole batch: `MailRuleEngine::applyToBatch()` (one query per rule, after
threading, so archive and trash actions can reach each message's thread), a flush, a second
pass collecting thread ids per account for `MailChangeRecorder::threadsTouched()`, and a
second flush for the change-log rows.

`PostIngestResult` is what comes back — messages in ingest order, owning accounts by id, and
thread ids by owning account — because the three callers do not finish the same way: IMAP
publishes its Mercure update and dispatches contact harvesting one level up in
`SyncImapMailboxMessageHandler`, while Gmail and Graph do both inline.

### Steps: the extension point

`App\Domain\Interface\PostIngestStepInterface` is auto-tagged `app.post_ingest_step` and has
one method, `afterCommit(PostIngestResult)`. Three rules the pipeline enforces:

**There is exactly one hook and it runs after the final flush.** A step never sees a
half-built batch. A hook inside the loop would let a step change a message the rule engine
then evaluates from stale database state, and mail would be filed somewhere the user's rules
do not explain.

**Steps dispatch, they do not work.** `afterCommit()` runs on the worker holding an IMAP
connection or a Graph rate-limit budget; a parse, an HTTP call or an image decode belongs in
its own handler. `App\Service\Mail\PostIngest\ExtractEventsStep` is the shape to copy — it
collects ids and dispatches `ExtractEventsMessage`, and nothing else.

**Steps cannot fail a sync.** `notifySteps()` catches and logs whatever a step throws and
carries on with the next. A broken step costs whatever it was going to do, never the mail.

Two branches deliberately do **not** come through the pipeline: IMAP's Gmailify claim, and
`SyncGmailMessageBatchHandler::enrichExisting()`. Both re-point a row that has already been
through it once, so running it again would re-record a create for an id JMAP clients already
hold, and re-run rules over mail the user may since have filed by hand.

## Threading

`App\Service\Imap\MessageThreader::assignThread()` is a three-step cascade, and each step
exists because the one below it is not good enough.

**1. The provider's own conversation id**, from `Message::$providerThreadKey`. Gmail and
Graph have already grouped the conversation the way the user sees it in their web client, and
nothing derived locally can beat that.

The trap here is batching. The repository only sees what has been flushed, and a sync batch
threads every message it built before flushing once at the end — so two messages of one Gmail
conversation in the same batch both missed the lookup, each made a thread, and the second
INSERT hit `uniq_message_thread_provider_key_account`, taking the whole batch with it.
`providerThread()` therefore keeps an in-memory map keyed `accountId|providerThreadKey`,
capped at `PROVIDER_THREAD_CACHE_LIMIT` (500), and re-validates each hit with
`EntityManager::contains()` because a worker's manager is cleared between messages and
anything it no longer manages is a stale reference.

**2. RFC 5322 `References` / `In-Reply-To`.** If a referenced message is already synced for
this account, its thread is used. If not, a new thread is created with
`ThreadingMethod::References` anyway — the method records what the message *provides*, not
whether a match happened to occur.

**3. Subject matching, and only as a rescue.** `SUBJECT_FALLBACK_WINDOW` is `-30 days`: long
enough for a slow conversation, short enough that a recurring subject starts fresh rather
than extending a stale thread. It applies **only** to messages that carry a reply prefix
(`REPLY_PREFIX_PATTERN` covers `re|fwd|fw|aw|wg|antw|sv|vs|res|rif|tr|doorst`, in the
languages plMail is translated into plus the ones common European clients emit), and the
candidate thread must also share a participant. A message that is not itself a reply always
starts its own thread — otherwise every "Your order has shipped" notification ever received
collapses into one endless conversation.

Gmail-native `threadId` threading is on the roadmap and not done: the id is carried on
`Message`, but step 2 is still RFC Message-ID based for accounts where step 1 does not apply.

## Categorisation

`App\Service\Mail\MessageCategorizer` resolves the inbox tab from **persisted data only**.
That is the whole design: the same logic runs at sync time and in `app:backfill category`,
with no re-fetch and no resync. Headers are stored raw and unnormalised and every lookup goes
through a helper that lower-cases the stored keys on demand, so adding a signal never needs a
resync — the header is already on the row.

The cascade, in order, and the order is load-bearing:

1. **Gmail's own `CATEGORY_*` labels win outright** when `Message::$gmailLabelIds` is
   non-null. Gmail has already classified the message and disagreeing with it inside a Gmail
   account is a tab that does not match the web UI.
2. **Correspondence override.** If the user has ever mailed this sender, it is Primary
   regardless of any bulk header.
3. **Forums before Promotions.** Discussion lists also carry `List-Unsubscribe`, so testing
   Promotions first would file every mailing list as marketing.
4. **Social by sender domain**, from a small high-signal `SOCIAL_DOMAINS` list.
5. **Updates**, then Primary as the default.

`explain()` returns the same decision with the step that made it and the signal it matched
on, recomputed on demand rather than stored — it reads only persisted data, so explaining a
message costs one pass over headers already in memory and cannot drift from a column written
by an older version of these rules. That is what the "why is this here?" affordance in the
message view renders.

## Labels are the single mechanism

`App\Entity\Label\Label` belongs to the **user**, not to an account. Where a label is
materialised provider-side is `App\Entity\Label\LabelBinding`, one row per (label, account),
carrying the Gmail label id, the Graph folder id or the IMAP mailbox link. Two accounts
syncing a folder called "Receipts" converge on one `Label` with two bindings, and the unified
inbox falls out of the model rather than being reconstructed by merging on name at render
time — which is what `SidebarCounts` used to do.

`Mailbox` is demoted to pure IMAP sync infrastructure and reaches its label through the
binding. Keeping a second link on `Mailbox` would recreate, in mirror image, the asymmetry
the binding table exists to remove.

`App\Service\Label\LabelResolver` is the one find-or-create, always in two steps: resolve the
user-level `Label`, then resolve its `LabelBinding` for the account being synced. Both sync
layers use it — `MailboxSyncer` mapping IMAP folders, `GmailLabelSyncer` splitting Gmail's
`Work/Invoices` naming into a parent chain — so nesting works identically whichever provider
produced it. It caches entity **ids**, never entities, so long-running handlers survive
`em->clear()`.

### What an operation means

`App\Service\Mail\ThreadStatusUpdater` owns what starring, archiving, trashing, labelling and
marking-read actually do. Each is a label mutation first — the database is the source of
truth — and then propagated asynchronously by `App\Service\Label\LabelChangePropagator`.
Archive is *the removal of the Inbox label*. Trash is Trash added and Inbox removed.

Every method there ends in a flush and records the JMAP change first, and that ordering is
the point of the class: a web-UI mutation that skips the log is invisible to connected JMAP
clients until something else happens to touch the thread, and the two were previously kept in
step only by each controller action remembering to call both.

`ThreadLabelSynchronizer` then makes a thread's labels the **union** of its messages' labels
— Gmail semantics, where a thread appears under a label if any message in it carries that
label. That derived union is why `EmailMapper` reads `Message::$labels` and never
`thread_label`: reading the union would report a mailbox for every message in a thread.

The provider mapping `LabelChangePropagator` performs is where the three providers stop being
interchangeable:

- **Gmail** — everything is a label mutation via `messages.batchModify`.
- **IMAP** — star and read map to flags; archive, trash and delete map to moves. Attaching a
  custom label is database-only, because the physical folder is untouched while the location
  label stays. *Detaching* one triggers a physical move only when the detached label was the
  message's location label, because a message must live somewhere. The replacement location
  is resolved in order: a remaining system Trash/Spam label with a backing folder, then a
  remaining folder-backed custom label (last attached wins), then — nothing folder-backed
  left — an archive.
- **Graph** — a folder move replaces the location rather than adding to it.

For IMAP moves the callers must pass messages **before** `flush()`, so `message->mailbox`
still reflects the source folder; the propagator captures the `messageId => sourceMailboxId`
map and optimistically re-points the message to the destination.

### Snooze is archive-with-a-timer, expressed in labels

`App\Service\Mail\ThreadSnoozeService` is the clearest case for the single-mechanism rule.
Snoozing removes the Inbox label and attaches `LabelRole::Snoozed`; it does not write a
status column and then hide rows. Two things follow for free: the "a message carries at least
one label" invariant holds without a second mechanism, and the snoozed pile is reachable by
every path that already works on labels — the sidebar, a query, the unified feed.

The label change **propagates outward**, exactly as archiving does. That is the point rather
than a side effect: a snoozed conversation should be out of the way in Gmail's inbox too. The
propagation happens *before* the labels move locally, so an IMAP job still sees the source
folder.

`LabelRole::Snoozed` is the one role with `hasProviderFolder()` answering false — it has no
IMAP special-use and no provider counterpart, and `MailboxSpecialUse` never maps to it. A
push treating it as a folder can only go looking for an id nobody ever set, and a message
carrying it would look like it is in two places at once to anything counting locations.

`wake()` marks the thread unread, and the read state it had is genuinely lost. That is a
deliberate trade: a thread that returns in the state you left it in is one you have already
learned to scroll past.

## The change log JMAP reads

Every mail mutation writes a row to `jmap_change_log` (`App\Jmap\State\ChangeLog`). The
autoincrement primary key **is** the state token: a client's state for an
`(accountId, objectType)` pair is the highest sequence recorded for it, and `Email/changes`
returns rows with `sequence > sinceState`.

`accountId` is a scalar rather than a `ManyToOne` on purpose — these rows are written from
long-running sync handlers where holding entity references across `flush()` is the documented
footgun.

`App\Jmap\State\StateManager` is the façade, and `record()` **only persists**: it never
flushes, so the log row commits inside the caller's existing unit of work. Two consequences
every caller inherits, and both have bitten:

1. **The ids must already exist**, so a record call belongs after the flush that mints them.
   A message recorded before its insert announces id 0.
2. **Nothing flushes here.** The log rows ride out on the caller's own flush, in the same
   unit of work as the change they describe — so a change that is rolled back takes its
   announcement with it.

`App\Service\Mail\MailChangeRecorder` sits on top because `StateManager`'s entity-free shape
leaves every caller to remember the same two things: which object type the thing it just
wrote is, and that an Email which moved also moved the Thread holding it. Five copies of
that, and the ones that forgot the Thread were not obviously wrong when read. There is
deliberately no method per feature — a draft autosave, an attachment and mail going out are
the same announcement.

Threads are always recorded as *updated*, never created: distinguishing a brand-new thread
from a grown one would mean asking whether every one of its messages is also new, and RFC
8620 §5.2 already requires clients to fetch an id in `updated` that they do not yet hold.

`StateManager` also accumulates the dirty `(account, type)` pairs in memory and
`JmapPushSubscriber` drains them once per request or handler, so a Gmail batch importing 50
messages produces one push notification rather than fifty. See [JMAP](jmap.md).

## Things that bite

**`bodyHtml` and `bodyHtmlSafe` are both real and neither is redundant.** The sanitised copy
is what is rendered; the raw copy is what `StructuredDataEventExtractor` reads, because the
`<script type="application/ld+json">` block it needs is stripped from the safe one — quite
correctly. `BodyHtmlPreservesStructuredDataTest` pins this. Collapsing the two loses
[event extraction](event-extraction.md) from booking confirmations, silently.

**Unread follows `Message::$seenAt`, not the IMAP `\Seen` flag.** There is a test named for
it. Incoming IMAP flag sync over the IDLE stream is not implemented — flags travel outward
only — so marking a message read in another client does not reflect back, and reading the
provider flag as truth would make the two disagree in the more confusing direction.

**Recording a JMAP change before the flush that mints the id publishes id 0.** This is why
`PostIngestPipeline` announces threads in a second pass rather than inside its loop, and why
`MailChangeRecorder::emailChanged()` takes a nullable thread at all.

**`search_vector` is a generated column and no PHP reads it.** It is mapped
`insertable: false, updatable: false` purely so it stays in the schema; `idx_message_search_vector`
is declared as a plain index and built `USING gin` by the migration, because Doctrine's
comparator matches an index on its name and columns and never looks at the method. Dropping
the declaration makes every schema diff ask to drop the index, and dropping the index turns
search into a sequential scan without failing anything.

**The `Archive` label is location bookkeeping, not a state.** "Archived" in the domain model
means "carries no Inbox label"; the `Archive` label exists so the location-label invariant
holds for plain IMAP accounts whose server has a physical Archive folder. It is created
hidden and the user can make it visible, which is why an Archive entry may or may not be in
somebody's sidebar.

**A post-ingest step that does real work slows every sync on the install.** The interface
documents "dispatch, do not work" and the pipeline cannot enforce it; the failure is not an
error but a mailbox that gets slower as features are added to a hook that runs on the
connection-holding worker.
