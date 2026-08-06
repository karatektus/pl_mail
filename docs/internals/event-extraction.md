# Event extraction

How an `.ics` invitation becomes a calendar entry, how an ordinary sentence becomes an offer
of one, and why those are two different mechanisms rather than one with a confidence score.
The user-facing side is
[Invitations and events from mail](../features/calendar-invitations.md).

## Two paths, and the line between them

| | Extraction | Proposals |
|---|---|---|
| Input | a `text/calendar` part, or schema.org markup | prose in an ordinary message |
| Output | a `CalendarEvent`, written unsupervised | an `EventProposal` row, offered on a card |
| Visible on the calendar | yes, immediately | only after the user says yes |
| Revisable by later mail | yes, by `EventReconciler` | no — accepting is a decision |
| Interface | `App\Domain\Interface\EventExtractorInterface` | `App\Service\Calendar\Proposal\ProposalDetectorInterface` |

The split is the whole design. An extractor reads something the sender already parsed —
a UID and a SEQUENCE, or a reservation number in a JSON-LD block — so writing it to a
calendar is arithmetic. A detector reads a sentence, so writing it to a calendar would be a
guess appearing beside facts. The asymmetry of cost settles it: a missed event is an
annoyance, an invented flight time is a missed flight.

Both feed off the same ingest hook. `App\Service\Mail\PostIngest\ExtractEventsStep` dispatches
`ExtractEventsMessage` with a list of message ids, and `ExtractEventsHandler` runs the
extraction runner and the reconciler per message, catching per message: one unparseable invite
must not cost the batch, and the batch must not be retried for it, because a message that
cannot be parsed will not parse on the second attempt either. Proposals run as their own
post-ingest step, `App\Service\Calendar\Proposal\ProposeEventsStep`.

## The extractor interface

`EventExtractorInterface` is auto-tagged `app.event_extractor` and has four methods:
`supports()`, `extract()`, `stopsCascade()` and `priority()`. Adding one is writing a class.

**The cascade is first-wins per dedup key, not first-wins overall.** One message legitimately
carries several unrelated events — a two-leg flight, an order with three parcels — and an
invite can sit beside a booking confirmation. So every extractor gets to look, and only a
collision on the same key is resolved by priority. `EventExtractionRunner` implements that as
`$byKey[$event->dedupKey] ??= $event`, where higher priority ran first, and the loser is not
an error: two extractors agreeing about one booking is the system working.

`stopsCascade()` is the exception and exists for exactly one case. A real iCalendar invite is
authoritative, and there is nothing a guess further down the list can add to it.

`supports()` must be cheap enough to call on every message; anything expensive — parsing,
fetching raw MIME — belongs in `extract()`. `ExtractionContext` carries the message, the
account, the `text/calendar` parts, the **unsanitised** `bodyHtml`, the headers, the
lower-cased from-address, and a `rawMimeLoader` closure that is lazy because on Graph it is an
API call and most messages are not invites.

The runner catches whatever an extractor throws and logs it: one broken extractor must not
cost the events the others found, nor the message.

Calendar parts are identified by **content type only, never disposition** — Gmail invites are
stored inline so they do not raise a paperclip, and an IMAP one may be either.

## `ExtractedEvent` is a claim, not a row

What an extractor returns is `App\Service\Calendar\Extraction\ExtractedEvent`, and it is
deliberately not a `CalendarEvent`. Whether a claim becomes a row, updates one, or is filed as
a superseded duplicate is `EventReconciler`'s decision — keeping the two apart is what lets
several extractors run over the same message and disagree without any of them writing.

`$sourcePayload` is stored verbatim on the resulting `EventSourceLink`, and it is the reason
extraction can be re-run as a **backfill rather than a resync**: the extractor's input sits
next to its output, so improving a mapper and replaying it needs no mail server. That is the
same property `MessageCategorizer` has, and `app:backfill events` is what exercises it.

## The two extractors

### `IcsEventExtractor`

The most trustworthy source there is, and the only one that is not a guess: the sender's own
UID, their own SEQUENCE, their own cancellation. RFC 5546 settled what identity and revision
mean for calendar mail decades ago, and following it is what makes plMail agree with every
other client about which update supersedes which. It is the extractor that stops the cascade.

`IcsEventExtractor::NAME` is a constant (`'ics'`) because the name is no longer written only
there — the invite card finds its event by asking for the link this extractor left, and a
string typed twice is a card that silently stops appearing the day somebody renames one of
them.

Three shapes reach it, and the third is why `rawMimeLoader` exists:

- IMAP stores a `text/calendar` `MessagePart` already, with bytes on disk.
- Gmail does too, inline or as a lazy stub.
- **Graph has no part at all**, because a `text/calendar` section inside
  `multipart/alternative` is not an attachment in its object model. All it gives is
  `meetingMessageType` on the message, so the invite is read out of the raw MIME instead —
  one fetch, cached to disk, only for messages that flag themselves.

A recurring invitation is **converted**, not merely kept. The RRULE used to be stashed
verbatim under `plmail:rrule`, and `RecurrenceMaterialiser` reads `recurrenceRules` and
nothing else — so somebody's weekly meeting arrived by mail and appeared on the calendar
once. The stash is now only what a rule `RecurrenceRuleConverter` refused falls back to; see
[The calendar model](calendar-model.md) for why refusing a rule outright beats converting most
of it.

A VEVENT with neither an end nor a duration is an instant, and iCalendar says to treat a
date-time one as zero-length — but a zero-length row is invisible in every view, so it gets a
nominal hour.

### `StructuredDataEventExtractor`

Events from the schema.org markup a booking confirmation already carries. Google created
email markup for exactly this — flights, parcels, hotels, tickets — and it is why Gmail can
tell you what is happening this week without a model anywhere near it. The sender has already
parsed their own booking into structured fields; reading them is arithmetic, not inference.

Priority 80, below the invite and above anything that guesses, and it does **not** stop the
cascade: one message routinely carries several unrelated things, and a sender parser written
later for one airline may know something this does not.

`CONFIDENCE` is 90 rather than the invite's 100. The fields are exact but their meaning is
not always: an itinerary is several objects with one reservation number, a delivery estimate
is a promise rather than a fact, and the sender chose which of half a dozen legal shapes to
emit.

The input is `Message::$bodyHtml` — unsanitised and untouched, because the `<script
type="application/ld+json">` block is stripped from `bodyHtmlSafe`, quite correctly. That is
why the two copies exist, and `BodyHtmlPreservesStructuredDataTest` pins it. It is also
attacker-influenced, so nothing trusts a field to exist, to be the type it should be, or to be
a sane size: `MAX_EVENTS` is 25 (a message claiming more is an attack or a template bug, not
an itinerary), `MAX_TITLE` 200, `MAX_LOCATION` 300, `MAX_DESCRIPTION` 2000.

There is **no sequence**. These sources carry no revision number, and inventing one — a
counter, a hash of the payload — would make an arbitrary ordering look authoritative to the
reconciler. Left at 0, the reconciler falls back to when the mail arrived, which for a
confirmation followed by a change notice is exactly right.

A schema.org timestamp carries an **offset, not a zone**, and a zone cannot be recovered from
one: `-05:00` is Chicago in summer and Bogotá all year. The instant is exact either way, so
the event is stored as UTC with a null `timeZone` and rendered in the user's own zone rather
than asserting a zone nobody stated.

## Dedup keys

The dedup key is what an extraction claims to be **about**, used before an event exists and
for suppressions. Each extractor mints its own shape:

| Extractor | Key |
|---|---|
| `IcsEventExtractor` | `'ics:' . $uid` — the UID is the identity, so it is also the key |
| `StructuredDataEventExtractor` | `'jsonld:' . sha256(issuer \| type \| identity)` |

The structured-data hash joins its three parts with a **NUL byte**, which cannot occur in any
of them, so `("AB", "1234")` and `("AB1", "234")` are not the same booking. The issuer domain
is in the key because a reservation number is six characters and unique only to the company
that issued it. An identity that comes out empty is dropped entirely: without something to
recognise the booking by, every resend of the same confirmation would be a new event and no
change notice could ever find the one it is about.

`CalendarEvent::$dedupKeyVersion` records which formula produced the keys on an event's source
links. Changing how a key is derived orphans every event already keyed the old way — an update
arrives, matches nothing, and becomes a duplicate. The column costs a smallint now and makes
that a re-keying backfill later rather than a data-loss event.

## Reconciliation

`App\Service\Calendar\EventReconciler` turns claims into what the calendar shows. This is
where a booking's life happens — a confirmation, then a change, then a cancellation, usually
across a thread and not always in order. Getting it wrong shows up as three copies of one
dinner, or a meeting that quietly un-cancels itself because an older mail was synced last.

Six rules, each because the obvious alternative is worse:

1. **Identity is the UID, unique per calendar.** For an invite that is the sender's own UID,
   verbatim.
2. **A later revision wins, by SEQUENCE, falling back to when the mail arrived.** Out-of-order
   delivery is normal, so an older revision arriving after a newer one is *filed* rather than
   applied.
3. **A superseded extraction is still recorded, with `applied = false`.** That is what makes
   "why is this on my calendar?" answerable, and it is the difference between an audit trail
   and a guess.
4. **Cancellation sets a status; it never deletes.** Users want to see the thing was called
   off, and deleting the row fights anyone who wants it back. Every occurrence of the event is
   marked cancelled rather than removed.
5. **A user-edited event is never overwritten.** `CalendarEvent::$isUserEdited` is set by
   `CalendarEventWriter::markUserEdited()` the moment a person edits an extracted event: a
   later mail may know more about the booking, but it does not know more than the user.
6. **Nor is an event this was never responsible for.** `EventSource::mayBeRewrittenByMail()`
   draws that line, and the claim is still filed against the event so the audit trail
   survives.

Where the event goes is `App\Service\Calendar\ExtractedEventCalendarResolver`: the user's
**default calendar**, with `Account::SETTING_CALENDAR_TARGET` as an override for anyone who
wants a particular mailbox's bookings kept apart.

It used to be the account's own calendar, on the reasoning that an event should land where the
mail that carried it lives. That is right about mail and wrong about a calendar: a person has
one diary, the fact that a flight confirmation arrived at the work address rather than the
private one is a property of the message and not of the flight, and filing by it split one day
across as many calendars as the user had mailboxes. It did so silently, too — a per-account
calendar is visible and coloured like any other, so the event was on screen and simply not
where its owner would look. `CalendarProvisioner` still creates a calendar per account; that
is what the setting points at, and what a mirrored provider calendar attaches to.

The override is **validated against the user** rather than trusted, because a setting is a
string in a jsonb bag and a calendar id that has since been deleted, or belongs to somebody
else, must fall back rather than throw or leak.

### The unit-of-work lookup

`findOneByUid()` asks the database, which cannot see a queued INSERT. Two messages in one
batch carrying the same UID each found nothing, each created an event, and the flush was
rejected by `uniq_calendar_event_calendar_uid`. A resend and its original land in the same
batch routinely — a backfill processes a whole mailbox at once, and an invite is usually sent
more than once. So `pendingByUid()` walks
`EntityManager::getUnitOfWork()->getScheduledEntityInsertions()` rather than keeping a
property: the scheduled set is the actual answer to "what will exist after the flush", it is
emptied by `em->clear()` between batches with nothing to remember to reset, and the service
stays stateless.

## Provenance

`App\Entity\Calendar\EventSourceLink` records which message put an event on the calendar and
what exactly it said. It is many-to-many with metadata rather than a nullable `message_id` on
the event, because **neither direction is single**: one message can produce several events,
and one event is typically produced by several messages spread across a thread.

`$payload` is the load-bearing column — the extracted fragment exactly as it was read — and
`$applied = false` means "this message was read, and lost": a stale duplicate, or an update
that arrived after a newer one. `uniq_event_source_link` on `(event_id, message_id, extractor)`
keeps one row per extractor per message per event; `idx_event_source_link_dedup` is what a
re-keying backfill would use.

`App\Service\Calendar\InviteReader` reads this back for the card above a message, and it loads
**per conversation, not per message**: the card is drawn by a partial included once per
message, so a lookup keyed on the message would be an indexed query per row on every thread
anyone opens, to answer "not an invitation" for nearly all of them. It implements
`ResetInterface` rather than relying on per-request convention, because it holds entities and
under a worker runtime a cache that outlives its request hands out objects belonging to a
closed entity manager.

## Suppression

`App\Entity\Calendar\EventSuppression` is a user's refusal, remembered. Small, easy to leave
until later, and the difference between a feature people like and one that feels like it is
fighting them: extraction is re-runnable by design, so without this table every backfill would
put back the thing the user just dismissed.

It is keyed on **`sha256(dedupKey)` rather than the event id**, for two reasons that both
matter — it has to survive the event being deleted, and it has to catch the *next* message
about the same booking before it creates one. `uniq_event_suppression` on
`(usr_id, dedup_key_hash)` makes the refusal idempotent, and the column is a fixed-width 64
because nothing needs the original.

`EventReconciler` asks about suppression **before anything is created**, and the proposal path
writes into the same table, so "not an event" means the same thing whichever mechanism offered
it.

## Proposals: reading a date out of prose

Three stages, of which two are built.

### Stage one — the shape gate

`App\Service\Calendar\Proposal\DateShapeGate` is a single regex over text already in memory:
no query, no disk, no network. Nearly all mail dies here, and only what survives is worth
splitting into sentences and reading properly. It is what makes the expensive parse
affordable — and, the docblock notes, what makes stage three thinkable at all: a model asked
about every message is a bill, one asked about the few per cent of mail that names a weekday
is not.

It is **deliberately over-permissive**. A gate that tried to be accurate would be the parser
written a second time, the two would disagree, and the failure mode is the invisible one: a
message the parser could have read that the gate threw away, which nobody notices because
nothing was displayed. It answers "possibly" and "certainly not", and only the second answer
is acted on.

German and English are in one alternation rather than one pass per language, because this
user's mail is both and no message says which it is. Every weekday and month name is listed
in **full** rather than as a stem with a wildcard: the first version used
`(?:mon|die|sam|mai)[a-zäöü]*`, which matches "money", "die", "same" and "mail" — the German
definite article and the word "mail" appear in essentially every message, so the gate passed
everything and stopped being a gate. Umlauted alternatives are matched without a leading `\b`,
because PCRE's `\b` is ASCII-only unless UCP is on and `ü` is therefore not a word character.

### Stage two — the deterministic detector

`App\Service\Calendar\Proposal\DeterministicDateDetector` is the one implementation of
`ProposalDetectorInterface`. Detectors read; they must not decide whether the date may be
offered.

### The noise rules, which live in one place

`App\Service\Calendar\Proposal\EventProposer` decides *when it is acceptable to guess*, and
everything about that lives there and nowhere else — because a second detector will arrive and
must not bring its own opinion about what counts as noise. Precision over recall throughout: a
card offering to put "Sale ends Friday!" on somebody's calendar is the moment they decide the
feature is stupid and go looking for the switch.

- **Not a draft, and not mail without an owner.** Nothing to propose to.
- **Primary only.** `MessageCategorizer` has already sorted bulk, marketing and
  discussion-list mail out at ingest from the same persisted headers a backfill sees, so this
  is a column read rather than a second set of rules free to disagree with the first. An
  *uncategorised* message is refused too, because "not yet classified" is not "classified as
  personal".
- **No list or bulk headers, even when the category says Primary.** The correspondent override
  pulls anyone the user has ever mailed back into Primary, which is right for a tab and wrong
  here: a shop the user once wrote to still sends newsletters, and those newsletters name
  dates. `BULK_HEADERS` re-checks `list-id`, `list-post`, `list-unsubscribe`,
  `x-mailman-version`, `x-google-group-id`, `feedback-id` and `x-csa-complaints`.
- **Addressed to the user.** One of the account's own addresses has to appear in To or Cc. A
  date announced to a list is an announcement; a date sent to you is an arrangement. This is
  the single rule that removes most of what survives the ones above.
- **Nothing an extractor could read.** A `text/calendar` part, the Graph meeting header, or
  schema.org markup means a real event is coming. Extraction runs asynchronously, so waiting
  to see the event would be a race — refusing on the *signal* is the same answer without the
  race, and the outcome is checked as well for the backfill case where extraction has already
  run.
- **Nothing in the past, nothing beyond `HORIZON_DAYS` (365)** — both judged against the
  **message's own date**, never against `now()`, so a backfill re-reading old mail reaches the
  verdict it would have reached the day the mail arrived. A year is generous on purpose: what
  lies beyond it is contract language, warranty periods and renewal dates — real dates,
  correctly parsed, that nobody wants on a calendar.
- **Nothing already refused**, through the same `EventSuppression` table extracted events use.

### The proposal row, and what accepting means

`App\Entity\Calendar\EventProposal` is a table of its own rather than a state on
`CalendarEvent`, and that is the whole point of the feature rather than a detail of it:
`CalendarEventWriter` materialises occurrences on every write, every range query reads
occurrences, and `UpcomingEventIndicator` lights the topbar dot from them — so an event row is
*visible by construction*, and keeping a guess in that table would make it a view's job to
remember to exclude it. One view forgetting once is an invented flight time on somebody's
calendar. **A proposal materialises nothing and therefore cannot leak: there is no occurrence
to find.**

Accepting does not flip a column. It writes a `CalendarEvent` through `CalendarEventWriter`
and the proposal row goes. Refusing writes an `EventSuppression` keyed on `$dedupKeyHash`.

`$sourceSentence` is kept and is not decoration: a guess whose evidence is visible can be
judged in a second, while a bare date with an Add button next to it cannot be judged at all,
so it gets clicked or ignored on a coin flip.

`uniq_event_proposal_message_starts_at` is the guard that actually holds — `EventProposer`
asks before it writes, but that check is a read on data another worker may be about to change,
and a backfill running while mail arrives processes the same row from two directions. The
message leads the index because every read is "what does *this* message propose?". There is
deliberately **no** index on `usr_id` beyond the FK's and none on `starts_at`: nothing sweeps
this table by user or by date, so an index for a query nobody makes is a write cost on every
ingest.

An accepted proposal becomes `EventSource::AcceptedProposal`, not `Manual`. The day, the hour
and the title were plMail's reading of somebody else's sentence and the user only agreed with
it — so when such an event turns out to be an hour out, the parser is the suspect, and that is
a question no query can ask once the row claims a human typed it. It carries no
`ExtractionKind`, so `isExtracted()` answers false and none of the "found in your email"
affordances appear beside it, and `mayBeRewrittenByMail()` answers false because it was
decided once by the person whose calendar it is.

## The stage that is deliberately not built

`ProposalDetectorInterface` has one implementation and exists anyway. The docblock says so
plainly, because an interface with one implementation usually should not exist:

> The stage after this one is a model. […] That detector will be a class implementing this
> interface, tagged `app.proposal_detector`, with a lower priority so it is asked only about
> what the deterministic one could not read. Nothing else changes: not `EventProposer`, not the
> entity, not the card, not the noise rules — which is the point, because the noise rules are
> the part that must not be re-decided by whoever adds the model.
>
> NO model code, config or dependency exists yet, and none should be added here. This is the
> seam, not the feature.

`EventSource::Llm` is the other half of the same reservation. The case exists now because the
alternative is a one-way door: if guessed events ever land unmarked next to parsed ones, there
is no query that separates them again. `isTrusted()` answers false for it alone — an untrusted
source is documented as never being pushed outward to a connected calendar, capped in
confidence and shown with an explicit confirm affordance.

That method is also the codebase's own example of why exhaustiveness matters. It used to be
`self::Llm !== $this`, which answers "trusted" for **every case written after it** — so the
next unsupervised source would silently inherit the calendar-writing permission the method
exists to withhold. It is now an exhaustive `match` over every case with no `default`, the
same fallthrough `Integration\Provider::authKind()` carried until it was closed.

`EventSource::AcceptedProposal` completes the argument from the other side: a guess a person
confirmed and a guess nobody saw are different facts, and the enum is the only place either is
written down, because the resulting row looks identical otherwise. Folding the two together
would lose the single fact that makes an accepted proposal safe to show at full confidence —
that a person looked at it.

## Things that bite

**Changing how a dedup key is derived orphans every event already keyed the old way.** The
update arrives, matches nothing, and becomes a duplicate — and the user's suppressions stop
applying at the same moment, because they are keyed on the hash of the old string.
`CalendarEvent::$dedupKeyVersion` and `EventSourceLink::$dedupKey` exist so that is a backfill;
neither helps if the version is not bumped.

**Sanitising `bodyHtml` in place deletes structured-data extraction.** The JSON-LD block is a
`<script>` tag. `bodyHtmlSafe` is the rendered copy for that reason and
`BodyHtmlPreservesStructuredDataTest` is what notices.

**An extractor that stops the cascade stops it for the whole message.** Only
`IcsEventExtractor` does, and only when it actually produced something — the runner checks
`[] !== $byKey` before breaking.

**A post-ingest step must dispatch rather than work**, and extraction is the reason the rule
exists: it can mean a parse, a disk read or a fetch of raw MIME, on a worker holding an IMAP
connection.

**`EventSource::isTrusted()` currently has no caller in `src/`.** The consequences its
docblock describes — Tentative status, a confidence cap, never pushed outward — are not
enforced anywhere yet, and would need wiring the day an untrusted source is added.

**A proposal is not an event and must never become visible as one before it is accepted.** The
separation is structural — no occurrence row — so anything that "simplifies" proposals into a
flag on `CalendarEvent` reintroduces the failure the table was created to make impossible.

**Both the gate and the noise rules judge against the message's date, not now.** A change that
reaches for `new DateTimeImmutable()` inside `EventProposer` makes `app:backfill proposals`
produce different answers from the live path, and the difference only shows up as old mail
proposing nothing.
