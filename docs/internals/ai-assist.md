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

## Vectors: pgvector is optional, and the image is built rather than pulled

The shipped image is `postgres:18-alpine`, which has no `vector` extension. Moving to
`pgvector/pgvector:pg18` means musl → glibc **on an existing data volume**, and that was tested
rather than reasoned about:

> The swap returns wrong rows from an index scan, with an empty log. musl records no collation
> version, so `datcollversion` is NULL, so Postgres's mismatch check never fires. 44
> collation-dependent indexes — 18 of them UNIQUE — are silently walked with the wrong comparator.

That number is now 48, of which 24 are UNIQUE — 84 and 59 counting the system catalogues, which the
wrong comparator walks too — and `datcollversion` is still NULL. Both re-checked
against the current schema. On a stack whose upgrade documentation says *"Starting the new image IS
running it"*, a libc change may never ride in on a pulled tag. **That route stays closed, and a
dump-and-restore is not offered as the default path either.**

What replaced it is `docker/postgres/Dockerfile`: a two-stage build `FROM
postgres:${POSTGRES_VERSION}-alpine` that compiles pgvector and copies three files —
`vector.so` and its two extension scripts — into a copy of the same base image. Same libc, same
server binaries, same on-disk format, 1 MB larger. Nothing that reads or compares existing data
changes, so there is no index to rebuild. Opt in with `compose.pgvector.yaml`; the base stack is
untouched and pgvector never becomes required.

Two things in that Dockerfile are load-bearing and easy to lose:

- **`OPTFLAGS=""`.** pgvector's Makefile defaults to `-march=native`, which bakes the *build*
  machine's instruction set into `vector.so`. An image built where AVX-512 exists and run where it
  does not takes SIGILL on the first distance — the connection closes mid-query with nothing in the
  log.
- **The pin is v0.8.6, not v0.8.1.** v0.8.0 does not compile against PostgreSQL 18 at all
  (`vacuum_delay_point()` gained a parameter; `TupleDescData.attrs` went away), and v0.8.1 is the
  earliest tag that does — verified by building every tag from 0.8.0 to 0.8.6 against
  `postgres:18-alpine` rather than by reading the changelog. But 0.8.3 fixed *possible index
  corruption with HNSW vacuuming* and 0.8.4 fixed *`hnsw graph not repaired`*, and
  `message_embedding` is `ON DELETE CASCADE` from `message` on an installation that deletes mail in
  bulk, so it is vacuumed constantly. The earliest version that merely compiles would ship two known
  index-corruption bugs.

### One function name, two possible bodies

The application only ever writes `plmail_embed_distance(a, b)`. The **migration** picks the body,
and the choice happens in SQL, once. There is no PHP branch and no second query builder to rot, and
the same tests exercise whichever body is installed.

Measured on 74,000 rows of 1024-dimension unit vectors, PostgreSQL 18.4 on musl, single-threaded,
warm cache:

| | 20,000 rows | 74,000 rows | per call |
|---|---|---|---|
| plpgsql dot-product loop | 2.14 s | 7.70 s | 0.107 ms |
| `(a::vector) <=> (b::vector)` | 1.23 s | 4.51 s | 0.061 ms |

**1.75×, and this page previously claimed ~24×.** The correction matters because it points at where
the time actually goes. pgvector's dot product is SIMD and takes microseconds; what dominates is
that `embedding` is `real[]`, so every row pays a detoast of a 4 KB array and a fresh `real[]` →
`vector` conversion before any arithmetic starts. The conversion is the cost, not the distance. It
is still worth taking — 0.63 s → 0.36 s on the semantic arm — but it is a constant-factor win on a
scan, not the thing that removes the scan.

`Version20260826210000` installs whichever body fits the database it first meets;
`Version20260901120000` asks again, once, for installations that gained the extension afterwards.
Neither ever requires it.

### The bound is a rule, not a hope

56 ms over 500 candidates is cheaper than the full-text pass that produces them. **11 seconds** over
100,000 rows is worse than the 9–10 s pathology the search UNION was rewritten to escape. So the
distance function may only ever be evaluated over a bounded candidate set, and the search
integration has to keep that rule rather than assume it. Nothing below changes that rule; an index
would be what finally makes it unnecessary.

### Storage

One row per **message**, in a table of its own.

Per chunk would multiply every `(thread, matching message)` row before the `GROUP BY` collapsed it
again — exactly the row multiplication removed from the label joins in v0.1.40, whose defining
property is that the answers stay correct and only the clock shows it.

A table rather than a column because a 768-dimension `float4` array is 3,092 bytes, past the TOAST
threshold, so every vector is stored out of line — 391 MB for 100,000 messages. That does not belong
on `message`. Truncating to 384 dimensions, where the model supports it, keeps the array inline and
halves the arithmetic. Measured at the width the current model answers at, 74,000 rows of 1024
dimensions is 397 MB of table, essentially all of it TOAST — which is also why the `real[]` → `vector`
conversion above dominates the distance it feeds.

Vectors are normalised to unit length **on the way in**, which is what lets the distance function be
a dot product with no square root per row. A zero-length or non-finite vector is refused rather than
stored: one NaN in that column poisons every `ORDER BY` that touches it, and it would be blamed on
the search rather than on the model that produced it.

### What it would take to reach an index, and what it would cost

A distance function only ever scans. The reason to want pgvector is HNSW, and **no index is created
today** — not because it was forgotten, but because three separate things block it. All three were
verified against pgvector 0.8.6 on PostgreSQL 18.4, and none of them is fixed by trying harder in
the migration.

**1. The indexable expression and the function's expression are not the same expression.**

```
CREATE INDEX … USING hnsw ((embedding::vector) vector_cosine_ops);
ERROR:  column does not have dimensions
```

HNSW needs a width. Only `(embedding::vector(1024))` builds, and Postgres treats that as a different
expression from `(embedding::vector)` — a different typmod argument to `array_to_vector()`. An index
built on one is invisible to a query written with the other. Confirmed both ways with `EXPLAIN`: the
typmod-bearing query takes `Index Scan using …`, the inlined function's expression takes a `Seq
Scan` and a `Sort`, **with no error and no notice**.

Spelling the width into the function body is not the fix. The width is whatever the configured model
returns — `count($unit)` in `EmbeddingStore`, not a setting a migration can read — and a fixed
`::vector(1024)` raises `expected 1024 dimensions, not 768` for *every* call the moment somebody
changes the model in the admin panel. A dropdown would 500 `/mail/search` for everybody.

**2. HNSW answers `ORDER BY … LIMIT k`; the search asks a threshold.** The semantic arm is
`plmail_embed_distance(…) <= 0.45` over the most recent `SEMANTIC_CANDIDATES` messages. No
approximate-nearest-neighbour index serves a range predicate — neither HNSW nor IVFFlat has an
operator for it. Reaching an index means the query becoming a top-k ordering.

**3. Building it is not something a boot-time migration can spend.** At 74,000 × 1024, with
`maintenance_work_mem` left at PostgreSQL's 64 MB default, which is what this stack ships:

```
CREATE INDEX … USING hnsw ((embedding::vector(1024)) vector_cosine_ops) WHERE dimensions = 1024;
NOTICE:  hnsw graph no longer fits into maintenance_work_mem after 13928 tuples
Time: 460363.979 ms (07:40)
```

578 MB of index — larger than the 397 MB table, because HNSW keeps a full copy of every vector plus
its graph. At `maintenance_work_mem = 2 GB` the same build takes 1 min 54 s and is the same size.
Migrations run from the entrypoint of all six services behind one advisory lock, so that is not a
slow migration, it is a stack that does not come back for eight minutes.

So the shape it would have to take, if someone picks this up:

- **One partial index per width**, `WHERE dimensions = N`, which is what lets a mailbox
  half-re-indexed after a model change keep both halves.
- **The width interpolated into the search SQL** rather than bound — it is already an `int` from the
  model, and `$semantic->dimensions` already exists at that point — so both sides say
  `::vector(N)`.
- **A top-k `ORDER BY … LIMIT`** in place of the distance threshold.
- **Built by hand, `CONCURRENTLY`, once**, by an operator who has been told the eight minutes and
  the 578 MB — never from a migration.

What that buys, measured on the same 74,000 rows, top-20 nearest:

| | |
|---|---|
| exact scan | 3.55 s |
| HNSW index scan | 3.0 ms (12 ms cold), 2,084 buffers |

**~1,180×**, which is the number that would justify all of the above — and which the function swap
above emphatically is not. Two caveats on it. Inserts into a table carrying the index cost 9.1 ms
per row against 0.32 ms without, measured over 2,000 rows, so a full 74,000-message backfill pays
about 11 extra minutes spread across itself. And the benchmark corpus is uniform random vectors,
where every pair is near-orthogonal — distances span 0.86–1.14 with a standard deviation of 0.031 —
so its *recall* number is meaningless and is not quoted here. The timings are sound; measuring
recall needs real embeddings.

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
