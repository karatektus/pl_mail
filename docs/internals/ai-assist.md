# AI assistance

Optional. plMail is a complete mail client with all of it switched off, which is the state every
existing installation is in and the state most will stay in. That is not a disclaimer — it is the
constraint the whole design is bent around, and most of the decisions below only make sense in its
light.

The model host is an **Ollama container on the operator's own network**. Nothing is sent anywhere
else, and there is no hosted service to fall back to.

> **Status.** All of it is written and passes unit tests and PHPStan; none of it has been run
> against a real Ollama. One end-to-end test —
> `compose-send-optimistic.spec.ts:124` — fails in the full suite and passes in isolation, which
> predates this work.

## Off is a real state

Three conditions decide whether anything happens, and `AiSettings::enabledFor()` is the only place
they are asked:

1. the master switch is on,
2. a host is configured,
3. a model is named **for that feature** — the embedding model for search, the chat model for the
   other two.

Two of those are the ones people actually get wrong: switched on with no host, or a host with no
model. Both produce a feature that appears to exist and never answers, so both are refused before a
request is spent finding out.

The three features are separate switches because they have very different costs and very different
appetites for being wrong. Writing help is asked for once, deliberately, by somebody looking at the
result. Categorisation runs unattended on every message that arrives. Embedding runs over the whole
mailbox. Wanting the first and not the third is reasonable, and one master switch would make that
choice for people.

## Why the Ollama client ignores every rule the image proxy enforces

`ImageProxyFetcher` refuses private addresses. It takes a URL out of a message somebody was *sent*,
so a link to `10.0.0.5` is an attacker asking this server to fetch something only this server can
reach.

`OllamaClient` performs no address validation at all, because the private address **is** the
feature. The endpoint is typed by an administrator into a form only an administrator can open and
stored as configuration; there is no path from message content to that column.

The two must never share a policy and must never share a code path. A helper both called would
eventually be relaxed for one of them and quietly weaken the other.

## Categorisation can only break a tie

The verdict is stored in `message.ai_category`, **beside** `category` and never in it. `category` is
what the deterministic rules produced and what every list query filters on, and it has to stay
explicable without reaching for a machine that may since have been switched off.

`MessageCategorizer::explain()` reads the verdict in exactly one branch: the one that would
otherwise have answered *"no rule recognised this, so Primary"*. Above that line the model could only
overrule something that actually matched a header — which would make a tab's contents depend on
which model happened to be installed the week the mail arrived.

Three consequences fall out of that placement:

- Switching the feature off is sufficient on its own. Nothing clears the column, nothing is
  re-processed, and a stale verdict is inert as long as a rule recognises the mail.
- The categoriser never calls anything. `explain()` runs behind list rendering and the details
  panel; a round trip in there would put another machine on the path of drawing a page.
- Only mail no rule placed is ever asked about. The answer for anything else is already known and
  would be discarded anyway.

The single request lives in `ClassifyMailHandler`, on a worker, after the transaction, over a batch,
for a subset. An ambiguous answer — two category names in one reply — is discarded rather than
resolved by first match.

## Vectors: no pgvector, and that is the recommendation

The shipped image is `postgres:18-alpine`, which has no `vector` extension. Moving to
`pgvector/pgvector:pg18` means musl → glibc **on an existing data volume**, and that was tested
rather than reasoned about:

> The swap returns wrong rows from an index scan, with an empty log. musl records no collation
> version, so `datcollversion` is NULL, so Postgres's mismatch check never fires. 44
> collation-dependent indexes — 18 of them UNIQUE — are silently walked with the wrong comparator.

On a stack whose upgrade documentation says *"Starting the new image IS running it"*, that is not a
hazard worth accepting for a feature nobody has asked to be mandatory. A dump-and-restore migration
is immune, because it rebuilds every index under the new libc — but that is a manual, downtime
operation, not something to trigger by pulling a tag.

### One function name, two possible bodies

The application only ever writes `plmail_embed_distance(a, b)`. The **migration** picks the body:

| | body | cost |
|---|---|---|
| normally | plpgsql dot-product loop | 0.11 ms per 768-dim row |
| if `vector` exists | `(a::vector) <=> (b::vector)` | ~24× faster, and reaches an HNSW expression index |

The second is `LANGUAGE sql` and a single `SELECT`, so Postgres inlines it. Detection lives in SQL,
once. There is no PHP branch and no second query builder to rot, and the same tests exercise
whichever body is installed.

### The bound is a rule, not a hope

56 ms over 500 candidates is cheaper than the full-text pass that produces them. **11 seconds** over
100,000 rows is worse than the 9–10 s pathology the search UNION was rewritten to escape. So the
distance function may only ever be evaluated over a bounded candidate set, and the search
integration has to keep that rule rather than assume it.

### Storage

One row per **message**, in a table of its own.

Per chunk would multiply every `(thread, matching message)` row before the `GROUP BY` collapsed it
again — exactly the row multiplication removed from the label joins in v0.1.40, whose defining
property is that the answers stay correct and only the clock shows it.

A table rather than a column because a 768-dimension `float4` array is 3,092 bytes, past the TOAST
threshold, so every vector is stored out of line — 391 MB for 100,000 messages. That does not belong
on `message`. Truncating to 384 dimensions, where the model supports it, keeps the array inline and
halves the arithmetic.

Vectors are normalised to unit length **on the way in**, which is what lets the distance function be
a dot product with no square root per row. A zero-length or non-finite vector is refused rather than
stored: one NaN in that column poisons every `ORDER BY` that touches it, and it would be blamed on
the search rather than on the model that produced it.

## Semantic search

A vector hit is **another arm of the same UNION**, so it lands in `COUNT(*) OVER ()`, pages
correctly, and needs no second statement to reconcile with. Four rules hold it together:

- **The query is embedded once**, in the controller. `buildSearchSql()` runs up to four times per
  search — the cheap pass, the body rescue, and twice more when a page past the end recovers its
  total — one of them inside a statement-timeout transaction, where a slow model would be reported
  as a database fault.
- **The candidate set is bounded** to the most recent `SEMANTIC_CANDIDATES` (2,000) messages.
- **A distance threshold, never a top-k.** The total rides on the same statement, and "lexical
  matches plus up to k" is only true on page one.
- **The inner `LIMIT` is parenthesised.** These arms are joined with a bare `' UNION '`, so an
  unparenthesised `LIMIT` would bind to the whole union.

The rank join is 1:1 — the property the label joins lost and had to be given back in v0.1.40.

## Writing help

Four tasks, a closed set. A free-form instruction parameter would be a prompt an attacker could
write, with the user's own mail as context and the answer pasted into the user's own draft. The
message being replied to is read from the **database** by id with ownership checked, never from the
page.

The server makes the call. `connect-src 'self'` is enforced, so a browser could not reach the model
host — and should not: that address is on a private network, and putting it in a page hands it to
every script the page loads.

The route carries **no draft id**: a composer opens before one exists, which is exactly when
somebody wants a first draft.

Generated text is **inserted, never substituted** — a rewrite that overwrote the original would be a
model deleting somebody's words with no undo they can see. It goes in as a plain text node at the
end of the body, because a styled wrapper is subtracted by the composer's typed-length calculation
and can stop the draft autosaving.

## When new mail gets indexed

Not when it arrives. Mail used to be embedded by a post-ingest step within seconds of landing,
which spent a round trip to the model host on every message the installation ever received — to
answer a question almost nobody has, because mail you might search for is rarely mail you read ten
minutes ago.

Two triggers replaced it, and both live in `App\Service\Ai\EmbeddingCatchUp`:

- **Right after a search.** The embedding model is the small one — well under a gigabyte, a couple
  of seconds cold — and **search and indexing use the same model**, so a query that has just been
  embedded has left it warm. `SearchController` queues a batch of at most fifty of the newest
  unindexed messages onto the ingest transport and returns; nothing on the request path waits for
  the model. Throttled to one batch per mailbox per five minutes, or paging through results would
  queue one per page.
- **`app:ai:index-new-mail`, nightly at 03:20.** The backstop, for somebody who has not searched in
  a fortnight. Newest first, at most `--limit` messages per mailbox.

The same two-model distinction is why a **search no longer holds the backfill back**. The yielding
signal — `InteractiveAiActivity` — counts the composer only: 20.3 GiB and thirteen seconds cold,
with a person watching a cursor. Counting a search there meant a finished search suppressed for
ninety seconds the very indexing whose model it had just paid to load.

## Embedding an existing mailbox

`app:ai:embed-mailbox`. Both triggers above are bounded and both work newest-first, so neither ever
reaches the mail that was already there when the feature was switched on. That needs one pass, and
on a large mailbox that is hours.

The job re-dispatches itself with a cursor rather than looping — a single job holding hours of work
is killed by any worker restart with nothing to show for it. The walk is by ascending id, the one
ordering nothing can change underneath it. **The vectors already stored are the progress record**,
so there is no second one to keep in step, and the cursor advances past everything *looked at*
rather than everything *stored*, so one unanswerable message never becomes a wall it restarts at.

It runs on the **maintenance** transport, not ingest: a backfill in front of the ingest queue would
stop new mail appearing until an old mailbox had finished being catalogued.

## Things that bite

- **A model change invalidates every stored vector.** A mailbox embedded at one width and searched at
  another returns nonsense rather than an error, so `EmbeddingStore::alreadyStored()` filters by
  model — which is what lets a backfill resume correctly instead of believing it has finished.
- **A top-k vector arm would make the result count page-dependent.** The total rides on the rows as
  `COUNT(*) OVER ()`; if the candidate set is "the k nearest", the total is only true for page one.
- **A non-deterministic score breaks paging.** `LIMIT/OFFSET` over an unstable sort can return the
  same row twice and some third row never. Both existing orders end in `thread_id DESC` for this
  reason.
- **Filling the page with vector hits would disable the body-substring rescue**, which only fires
  when the page did not fill.
- **The catch-up finder matches on the model and not on the width**, although the coverage count
  matches on both. What consumes its ids is `EmbedMessagesHandler`, which skips whatever
  `EmbeddingStore::alreadyStored()` reports — and that asks about the model alone. A finder that
  tested the width too would hand over a full budget the handler then drops: a nightly sweep that
  reports itself busy and indexes nothing.
- **An unbounded catch-up is a second backfill.** `app:ai:index-new-mail` has a per-mailbox ceiling
  for that reason: without one, the first night on an install that never ran a pass would queue a
  hundred thousand messages onto the ingest transport and put new mail behind them — with no state
  row, no pause button and no panel, because those belong to `app:ai:embed-mailbox`.
