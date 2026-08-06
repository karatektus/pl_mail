# The calendar model

JSCalendar in jsonb with the queryable parts projected into columns, the occurrence table
every view actually reads, recurrence and its overrides, time zones, and how one meeting that
arrived twice is drawn once. For the feature itself see [Calendar](../features/calendar.md);
for the sync engine that fills it, [The sync engine](calendar-sync-engine.md).

## The hybrid, and why it is not one thing or the other

`App\Entity\Calendar\CalendarEvent` stores an event as RFC 8984 JSCalendar in a jsonb column,
with anything a query filters, sorts or joins on lifted into real columns beside it. The
docblock calls this the biggest decision in the feature, and it is worth restating as two
refusals.

**A bespoke schema was refused** because everything this calendar will ever talk to speaks
JSCalendar or converts cleanly to it — iCalendar in both directions, CalDAV, and JMAP
calendars when that draft lands. Participants, alerts, links and `recurrenceOverrides` have
nowhere to live in a hand-rolled schema, and losing them on import→export is silent, which is
the worst kind of data loss.

**Pure jsonb was refused** because Postgres cannot do range logic on `"duration": "PT1H"`.
There is no index that answers "what is in July" against a duration string.

So `$jscalendar` holds the truth and `title`, `location`, `startsAt`, `endsAt`, `timeZone`,
`isAllDay`, `status` and `privacy` are projections of it. The rule that keeps them from
disagreeing is not discipline: **`App\Service\Calendar\CalendarEventWriter` is the only thing
that writes either.** Everything writes through it — the editor, extraction, the sync
engine's puller, the booking service, the JMAP `CalendarEvent/set` method. Without it there
are two truths, and the failure is quiet: a caller sets `$title`, forgets
`jscalendar['title']`, the calendar looks right, and the `.ics` export is blank.

`CalendarEventWriter::write()` therefore rebuilds the canonical object from the columns on
every call, and carries four things across explicitly because they have no column to be
rebuilt from:

- **`recurrenceOverrides`** — per-instance decisions the user made; rewriting the series is
  not a reason to lose them.
- **`participants`** — they carry the RSVP, the editor has no participants field, and without
  the carry-across correcting a meeting's title silently un-answers an invitation that was
  already accepted.
- **`alerts`**, but differently: alerts *do* have a field in the editor, so a caller that
  states them is saying what the alerts **are** and an empty list is the user unticking every
  box. `null` — which is what every extractor, every sync pull and every per-instance edit
  passes — keeps what is stored.
- **An extractor's `$jscalendarOverlay`**, merged over the derived object with the derived
  version as the floor, before occurrences are materialised, so the event is complete when
  the materialiser reads it.

The overlay merge runs through `keepAnswersAlreadyGiven()`, which is the same argument
sharpened: an invitation says NEEDS-ACTION about the recipient forever, because that is what
it said when it was sent. Re-running extraction over stored mail is routine, so without this
every RSVP the user had given reverted to unanswered while the organiser — told at the time —
went on knowing better than the screen. It only ever *keeps*; an incoming entry stating an
actual answer wins, because that is the organiser's updated attendee list coming back.

Empty maps are removed rather than left behind, for `recurrenceOverrides` and `alerts` alike:
an empty map is not a fact about an event, and one left behind makes every event that ever
had an alert read as though it still does — which for a sync means a PUT that keeps
re-asserting nothing.

## Occurrences: the table a view reads

`App\Entity\Calendar\CalendarEventOccurrence` is one dated instance. Recurrence is the part
of a calendar naive designs get wrong, and the entity docblock enumerates the only three
options:

- **Expand RRULEs in PHP when a view asks.** To answer "what is in July" you must first load
  every recurring event ever created — a standup from 2019 still generates instances — and
  expand each. Nothing is indexable, nothing is pageable, free/busy becomes quadratic.
- **Materialise everything.** `FREQ=DAILY` with no `UNTIL` has no end.
- **Materialise to a bounded horizon.** This table.

Non-recurring events get exactly one row here too. One code path for reads is worth more than
the rows saved by special-casing them — and it is what makes the alert sweep, the booking
availability reader, the share reader and the JMAP query all read the same rows.

`$span` is a generated `tsrange`, maintained by Postgres from `starts_at` and `ends_at`, so it
cannot drift from them. Nothing in PHP reads it: the only thing that can use it is the `&&`
overlap in `CalendarEventOccurrenceRepository::findInRange()`, which is raw DBAL because DQL
has no range-overlap operator and Doctrine's API cannot reach a GiST index at all. The naive
alternative — `starts_at < :to AND ends_at > :from` — is not merely slower, it *degrades*: a
btree on `starts_at` stops approximating `ends_at` ordering the moment multi-day events
exist, so the planner scans backwards from the window's start looking for events that began
earlier and are still running.

`idx_ceo_span` is declared in the mapping as a plain index and created `USING gist` by the
migration, the same trick `Message::$searchVector` plays with its GIN index: the comparator
matches an index on its name and columns and never looks at the method. Declaring it keeps
the mapping and the database agreeing; **not** declaring it makes every schema diff ask to
drop it, and dropping it turns every calendar view into a sequential scan without failing
anything.

`idx_ceo_starts` — starts-only, unscoped by owner — exists for exactly one reader: the alert
sweep, which asks "what starts near now, anywhere on this install?" once a minute. Both
owner-scoped indexes lead with a user or a calendar and cannot answer that.

## The materialiser and its horizon

`App\Service\Calendar\RecurrenceMaterialiser` runs on **every** event write and rewrites that
event's rows wholesale rather than diffing them: the set is small, the write is one DELETE
and one batch of INSERTs, and a diff would be a second implementation of what the rule means.

| Constant | Value | What it bounds |
|---|---|---|
| `HORIZON_PAST` | `-1 year` | how far back rows are written; short because nobody scrolls back through a series |
| `HORIZON_FUTURE` | `+2 years` | far enough that "next year" is instant |
| `MAX_OCCURRENCES` | `1000` | the second belt — `FREQ=SECONDLY` inside the horizon is sixty million rows, and an `.ics` from a stranger is allowed to say that |

`clear()` is two steps and both are needed: a raw DELETE for what is committed, plus
`em->remove()` for what this unit of work has queued but not yet flushed. Raw SQL cannot see
the second set, and clearing the collection alone does not unschedule the INSERTs — so
materialising twice before a flush queues two rows with the same recurrence id and
`uniq_ceo_event_recurrence` rejects the pair. It is public as well, because an event on its
way out of a synced calendar has to vanish from every view while its row waits for the remote
to be told; see `CalendarEventWriter::markLocallyDeleted()`.

`CalendarEvent::$recurrenceUntil` is set as a by-product: the last moment the event can
occur, or **null meaning "we stopped because we ran out of room, not because the rule did"**.
That null is exactly what the nightly sweep re-reads.

That sweep is `app:calendar:materialise` (`App\Command\Calendar\CalendarMaterialiseCommand`),
and it exists because occurrences are drawn when an event is **saved** and nothing moved the
window afterwards. A weekly standup created today reaches two years out; in eighteen months
it reaches six, and eventually its last row is in the past — at which point the series stops
being drawn and its reminders stop firing, because `DueAlertReader` reads occurrence rows and
there are none left. Nothing announces that: the event still exists and still says it repeats
weekly. Its criterion is `CalendarEventRepository::findNeedingHorizonExtension()` — everything
unbounded, plus anything ending after the current horizon — deliberately not "everything
recurring", because a series with an `UNTIL` inside the window is already drawn to its end.
Re-materialising is idempotent, so a missed night costs nothing and a doubled run costs
nothing, and it flushes in batches of `BATCH` (50) events rather than holding one transaction
on `calendar_event_occurrence` for the length of the sweep.

Two guards inside the expansion are there because a rule can be hostile or simply broken:

- An iterator that does not advance stops on the first repeat with a warning. The occurrence
  cap alone would turn a non-advancing rule into a thousand identical rows rather than a
  hang, which is the harder failure to notice.
- An unusable rule — one `RecurrenceRuleConverter` refused, or one `RRuleIterator` threw on —
  degrades to a single occurrence with `isRecurring` false. One occurrence is wrong-ish; a
  silently empty calendar is worse.

The past horizon **skips** rather than stops: a rule that started in 2019 still has to be
walked to reach this year's instances.

## Recurrence rules, in both directions

`App\Service\Calendar\RecurrenceRuleConverter` converts between JSCalendar's
`recurrenceRules` (RFC 8984 §4.3.3) and iCalendar RRULE. Both directions live there, and the
reverse used to be missing — so two of the three ways a rule reaches plMail, an emailed
invite and a CalDAV resource, kept it verbatim under `plmail:rrule` and expanded to a single
occurrence. A weekly meeting from a calendar server showed up once. Google, meanwhile, wrote
its own copy because there was nothing to call.

**Anything that cannot be converted faithfully refuses the whole rule.** Not "drops the part
it did not understand": `FREQ=MONTHLY;BYDAY=2FR` with an unreadable `BYDAY` becomes "monthly
on the day it started", which is a meeting somebody misses rather than a meeting visibly
missing. A refused rule comes back null, the caller keeps the RRULE verbatim, and a push puts
the sender's own rule back. The one thing dropped rather than refused is a part name RFC 5545
does not define — its grammar is closed, so an unrecognised name is a vendor extension.

`secondly` and `minutely` are deliberately absent from the frequency table. Both are legal in
RFC 5545 and RFC 8984 and sabre's `RRuleIterator` accepts them at validation, but its advance
step has no branch for either and yields the same instant forever. Converting them would
produce an iterator that never moves, which the occurrence cap turns from a hang into a
thousand identical rows — worse, because it looks like it worked.

## Overrides: where a series stops being a rule

A changed instance is a JSCalendar PatchObject filed under the **LocalDateTime the rule
originally put it at** — never where it was moved to. `CalendarEventOccurrence::$recurrenceId`
is the same fact in the occurrence row: the only stable way to say "the one that was meant to
be on the 3rd" once it has been dragged to the 5th, and what makes a second edit of the same
instance update its patch instead of stacking a new one beside it.

The materialiser reads five keys out of an override. Four of them are the patch
`App\Service\Calendar\EventInstanceEditor` writes — the four things an occurrence row can actually
draw — and the fifth is not a patch field at all: `excluded` is spelled by
`RecurrenceRuleConverter::exclusionOverrides()`, because the one override value whose only job is
to be exactly right should not have a second place that has to be right about it.

| Key | Effect |
|---|---|
| `start` | drawn on the day it went to |
| `duration` | an instance that moved is routinely also a different length |
| `title` | written only when it differs from the series' — a patch repeating the series' own title is a claim that this instance was renamed |
| `status: cancelled` | the row is kept and struck through |
| `excluded: true` | the instance is off the calendar entirely |

Reading `duration` used to be missing. An instance dragged into the afternoon because it
became the retro was drawn with the right start and the series' length, which is a meeting
that overlaps the one after it in every view.

**A patch is a partial, and that is the discipline.** Writing a whole event object into the
map — the obvious shortcut when the editor has already posted every field — would state a
location, a description and an all-day flag for one instance that nothing reads and that the
next reader cannot tell from a decision the user made.

Cancelling one instance is `{"excluded": true}`, and that spelling belongs to
`RecurrenceRuleConverter::exclusionOverrides()` rather than being written by hand at each
call site: it is the one override value whose only job is to be exactly right.

`App\Service\Calendar\EventMover` is the drag-on-the-grid path and it gives the **same two
answers the editor gives**, through the same two services, so a drag and a save that mean the
same thing cannot produce different data. "This occurrence" is `EventInstanceEditor::edit()`;
"all of them" is a `CalendarEventWriter::write()` whose times have been run through
`EventInstanceEditor::seriesTimesFor()`, which applies the *difference* the drag made rather
than its absolute value. Writing the dragged block's absolute times as the series' own once
rebased a weekly meeting onto whatever day its fifth occurrence was clicked on.

## Time zones, and what floating means

Timestamps are UTC in a plain `timestamp` column with the IANA zone beside them, rather than
`timestamptz`. That matches every other timestamp in the app, matches how CalDAV and sabre
model it, and avoids Doctrine's lossy `datetimetz` read on Postgres.

**Expansion happens in the event's own zone.** A 09:00 Berlin standup is at 09:00 Berlin in
November and 09:00 Berlin in July, which are different UTC instants — expanding in UTC would
silently move the meeting an hour twice a year. So the seed is converted into the event's
zone, iterated there, and each result converted back.

`RecurrenceMaterialiser::zoneOf()` is **public**, and the docblock says why: the key of an
override is a LocalDateTime in the series' zone, so a producer that fell back to the user's
zone where the expander falls back to UTC would file every patch on a floating event under a
key that is never looked up — an override that silently does nothing. `EventInstanceEditor`
asks there rather than repeating the fallback, and `CalendarPuller` carries its own copy of
the same rule with a comment saying the two must agree.

**All-day events are floating**: local midnight with a null `timeZone`, and they expand in
UTC, which is what floating means — the same wall clock everywhere.
`CalendarEventWriter::write()` enforces the pairing by writing `timeZone = null` whenever
`isAllDay` is true, so the two cannot be set inconsistently by a caller.

`App\Service\Calendar\CalendarTimeResolver` owns the other half: which wall clock a calendar
is *read* in, and how the digits a browser posts turn back into instants. The zone comes from
the **calendar**, not from the user's profile — `UserTimezoneResolver` answers "what clock is
this person reading?", which is right for a rendered timestamp, while a calendar's own zone
is what an event with none of its own is stored and shown in. The two can honestly differ: a
shared work calendar pinned to the office. Every parse there is total — an unusable zone or
an unparseable date returns a fallback or null rather than throwing, because all of it
arrives from a request.

## One meeting, two rows

A meeting can reach plMail twice by two honest routes at once: extracted from its invitation
onto the user's default calendar, and mirrored from the provider onto a Remote calendar. Both rows
are correct. `CalendarPuller` already falls back from `remoteId` to `uid`, but scoped to one
calendar, and these are two.

Nothing collapses the rows. The duplication is answered **on the screen**, by
`App\Service\Calendar\EventClusterer`.

**UID plus start instant is the grouping key, and it is the only honest one.** Matching on
title and time would collapse a weekly 1:1 held with two different people at the same hour
into one chip — a meeting quietly disappearing from a calendar, which is the worst shape a
calendar bug takes. The start is in the key because two occurrences of one series are the
same *event* and not the same *meeting*.

**A cluster is merged only while its members agree**, on exactly the five things a user would
notice on a chip: start, end, title, all-day, and whether it has been called off. The moment
they disagree the cluster splits back into clusters of one and the views draw a chip each.
That is deliberate: a merged chip that quietly picks a winner hides a real disagreement — an
update that reached one path and not the other — behind a tidier UI. And **disagreement
splits the whole group** rather than merging the sub-group that happens to be in the
majority, because a majority is a winner picked with extra steps.

Recurrence is deliberately not one of the five. Two copies where one repeats and the other
does not agree about the occurrence they share and about nothing else, and the repeating copy
draws its own chips on every later day with no partner to merge with — which is exactly the
visible signal that the two differ.

Cancellation is read from the occurrence row **and** from the event's status, because the
range query drops cancelled occurrence rows before a view sees them: the disagreement that
actually reaches the screen is a status of cancelled on one copy and confirmed on the other,
and merging those would draw a live meeting that one path has been told is off.

`copiesOf()` answers the same question about events rather than occurrences, for the editor,
and compares the same five fields through the same private signature — two implementations of
"the same meeting" would agree until one learned about a sixth field.

### A copy shares the meeting's UID

`App\Service\Calendar\EventCopyResolver` turns "which calendars is this on?" into every
calendar the user owns, ticked where the meeting already is, so ticking an empty one creates
the copy there. The decision the whole feature turns on:

**A copy carries the meeting's UID. It does not get one of its own.** Four reasons, and they
compound:

1. `EventClusterer` identifies a meeting by UID plus start, so two rows with different UIDs
   are two meetings *by construction* — a copy with its own UID would draw a second chip on
   the same hour forever, and no later edit could merge them.
2. The schema was built for the shared case: `uniq_calendar_event_calendar_uid` scopes
   uniqueness to **one** calendar, and every identity lookup in `CalendarEventRepository` is
   calendar-scoped for the same reason.
3. RFC 5546 already decided that a UID identifies the meeting across calendars and mailboxes
   rather than identifying a row. Re-minting would be claiming otherwise to every client that
   reads the `.ics`.
4. It is what keeps updates working. A later message from the organiser, an `.ics` re-import
   and a provider pull all match on UID.

**The UID is minted once per request, in the resolver, not per row by the writer.**
`CalendarEventWriter::write()` mints only for a row that has none, so creating one meeting on
three calendars would otherwise produce three UIDs and three chips the moment it was saved.
`newUid()` is public for exactly that caller, rather than the resolver spelling out a second
way of making a UID — two spellings would agree until one of them learned about the domain
part. That domain part is a literal, not the install's hostname, so a UID does not change
when the app moves behind a different name.

The cost is accepted and stated: two rows under one UID cannot be told apart by UID alone.
That was already true the day a meeting could arrive both extracted and mirrored.

Hidden calendars are still **listed** by `optionsFor()`, just unticked. Leaving them out
would turn "put this on my archive calendar too" into an insert that violates
`uniq_calendar_event_calendar_uid` with a 500.

## Things that bite

**A write that bypasses `CalendarEventWriter` produces an event that looks right and exports
blank.** Setting a column without its JSCalendar counterpart is undetectable in the UI. The
JMAP writer (`App\Jmap\Calendar\JmapEventWriter`) is explicit that it touches no column for
this reason.

**An override key written in the wrong zone silently does nothing.** The key is a
LocalDateTime in the *series'* zone; a floating event's zone is UTC, not the user's. Anything
producing an override must ask `RecurrenceMaterialiser::zoneOf()` rather than resolving a
zone itself.

**Anything a patch says beyond `start`, `duration`, `title` and `status` is ignored** — those
four plus `excluded`, which arrives by the other route above. The materialiser reads them and
nothing else, so a "richer" patch is data that survives storage and never affects a view.

**Extending the horizon is not free and shortening it loses alerts.** The occurrence horizon
is also the alert horizon — an alert exists only where an occurrence row does — and
`DueAlertReader`'s `MAX_LEAD` of `+31 days` is the matching bound at the other end. The
JMAP session advertises `materialisedHorizon` straight from the two constants, so a client is
told where its query stops being trustworthy.

**Removing `idx_ceo_span` or `idx_calendar_event_remote_instances` from the mapping does not
fail anything.** Both are declared as plain indexes and built with a different method by the
migration; undeclared, the next schema diff drops them, and the symptom is a calendar that
gets slower rather than a calendar that breaks.

**Merging duplicates is a render-time decision with no stored id.** `copiesOf()` re-derives
from the UID rather than threading a cluster id through the URL, because a cluster is a fact
about the data at the moment it is read and a minted id would be a claim the next write can
falsify. Anything that caches a cluster reintroduces exactly that.
