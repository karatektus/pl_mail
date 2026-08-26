# AI assistance

Optional. plMail is a complete mail client with all of it switched off, which is the state every
existing installation is in and the state most will stay in. That is not a disclaimer — it is the
constraint the whole design is bent around, and most of the decisions below only make sense in its
light.

The model host is an **Ollama container on the operator's own network**. Nothing is sent anywhere
else, and there is no hosted service to fall back to.

> **Status.** Built: the transport, the gateway, the admin section, the onboarding step,
> categorisation, and embedding storage. Not built yet: the search integration, the composer's
> writing help, and the backfill that embeds an existing mailbox.

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
