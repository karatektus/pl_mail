# Sharing and booking

Two ways of letting somebody without a plMail account see or use part of your calendar: a **shared
link**, which shows them what you choose to show, and a **booking page**, which lets them take an
hour of it. Both live under **Settings → Sharing**, and both are gated by a secret in the URL and
nothing else — no sign-in, no session, no second factor. Whoever holds the address is the audience.

That makes the address a credential, which is why the most important thing on this page comes first.

## The address is shown once

For a shared link and for a booking page alike, plMail stores only a hash of the address, never the
address itself. A database dump, a backup on somebody else's storage, or a read gained through an
unrelated hole therefore yields no working URLs.

The cost is stated plainly on the form, because it is the first thing people try to work around:

> The address is shown once, when it is created. It is not stored, so it cannot be shown again — if
> you lose it, give the link a new one.

So when you create one, **copy it there and then.** There is no screen that can show it again,
because nothing stored can reconstruct it, and there is no copy button on an existing row for the
same reason.

**Regenerate** is the recovery, and it is also the fix for a URL that leaked: it mints a new address
and the old one stops working immediately — *New address. The old one no longer works.* That is the
right thing to do with an address that went missing anyway.

Editing a link or a page does **not** change its address. Narrowing what a link reveals, or changing
the hours a booking page offers, must not break a URL you already sent.

## Shared links

**New shared link** asks for five things.

**What you call it** — only you see this; it is how you find the link again to change or revoke it.

**Which calendars** the link covers. Explicitly, calendar by calendar: not "all of them" and not "the
visible ones", because visibility is a sidebar preference you change for your own reading, and a link
whose contents followed it would start revealing a calendar the moment you ticked it back on.

**What this link reveals** — four checkboxes: **Titles**, **Location**, **Description** and **Who is
coming**.

**How far it reaches** — either **A rolling window from today**, with a number of **Days ahead**, or
**Between two dates**, with a **First day** and a **Last day**. The two are genuinely different
things. "Here is my availability for the next fortnight" has to move with today and stay useful for
as long as the link lives; "here is my diary for the conference" is two fixed dates and must not creep
forward into the weeks afterwards. A rolling window defaults to 14 days and reaches at most 366.

The window is always resolved in **your** time zone, never the reader's. A rolling fortnight is a
fortnight of your days, and a fixed range names two dates on your calendar — otherwise the same link
would cover different days depending on where it was opened, and the last day of a conference link
would appear or vanish across the date line.

### What each checkbox actually does

**With none of them ticked, the link shows only when you are busy.** Busy/free is the floor, not an
option — a link that revealed nothing at all would have no reason to exist. The reader sees your days
with anonymous **Busy** blocks on them, marked *This link shows when the calendar is busy, and
nothing else*, and days with nothing on them saying *Nothing on this day.*

Each checkbox adds exactly one thing on top of that. They are a set rather than a level, deliberately:
sharing titles but not locations is what you want when your calendar says where you live, and sharing
who is coming but not titles is what a team wants when the subject is confidential and the attendance
is not. A "none / some / all" slider could express neither.

The settings list shows what each link reveals — *Reveals Titles, Location* or *Busy/free only* — so
a link you widened is visible as a widened link.

### Privacy on the event is a ceiling the checkboxes cannot raise

Every event has a privacy setting, and it wins over the link:

- A **private** event stays a plain busy block, whatever the link's checkboxes say. The form says so:
  *An event marked private stays a plain busy block whatever is ticked here.* You said "the fact that
  I am busy is fine, the subject is not", and no per-link checkbox may override that — the link is a
  decision about an audience, the privacy is a decision about one meeting, and the narrower one has to
  win or the wider one becomes a way to undo it in bulk.
- A **secret** event does not appear at all — not even as a busy block. Its existence is the detail: a
  block appearing on a Tuesday afternoon is exactly what somebody reading the link would act on.

The cost of the second one is real and is accepted: a shared calendar containing secret events says
you are free at hours you are not, so somebody can book over one. That is the trade the word "secret"
asks for; showing it as busy would make the setting mean nothing different from private.

Cancelled events are left out for a different reason — a called-off meeting is not a claim on your
time, so leaving it in would say "busy" at an hour you are free.

None of this is a check a template performs. The page is built from an object that has already had
everything the link does not reveal removed from it, so there is no tooltip, data attribute or `.ics`
that *could* carry a title the link did not unlock.

### What the recipient sees

A month grid, drawn with the same weeks, chips and day markings as your own calendar, and under it a
list of the days it covers. Times are shown in your zone and labelled as such. There is an **Add to
your calendar** link that hands out the same window as an `.ics` for their own calendar app to
subscribe to. A window with nothing in it says *Nothing in this window*, and a covered day with
nothing on it says *Nothing on this day* rather than being skipped. A single page renders at most
2000 entries.

The grid is a calendar month; your link publishes a window. Where the two differ — and for a rolling
fortnight most of the month is the difference — the days your link does not cover are drawn dimmed
and named in a legend as **Days outside the shared window**. That distinction is not cosmetic: a
blank cell that looked like a free one would have the page claiming you are free on days you never
published. For the same reason the ◀ ▶ steps only reach the months your window actually touches, and
are drawn greyed out at the ends rather than paging into a month that would look empty.

Chips carry no calendar colour, deliberately. A shared page says nothing about your calendars — not
their names, not their colours, not how many there are — so every chip is drawn in your accent
instead. Colours would let somebody holding a busy/free link group a fortnight of anonymous blocks by
which diary they came from, which is structure about your life that no checkbox offered to reveal.

The page is drawn in **your** appearance: your theme, your accent, your corner radius and density.
Nothing else about you crosses over — the page has no name, no address and no hint of which install
it came from, and the template is handed three strings rather than your account so it could not print
one if somebody added a line that tried. An account that never chose gets Paper, which is what a new
account starts as. A theme set to *Follow the system* is resolved against the reader's own machine, so
the page is legible light or dark.

On a phone the grid keeps the shape of a month and drops the labels inside each cell to a mark per
entry; the list underneath is where the detail is read.

**Revoke** takes a link out of service without deleting the row, so you keep a record of it; a revoked
link is marked as such in the list. **Delete** removes it entirely — *Anyone still holding its address
loses it.*

An unknown address, a revoked link and a malformed one all answer 404 and all answer identically.
Telling them apart would confirm which addresses had once been real, and would tell somebody you sent
a link to that you had taken it down — which is a fact about your availability you chose to stop
publishing.

## Booking pages

A booking page publishes a set of hours and lets a stranger take one of them. One page is one *kind*
of appointment — "30 minute intro call", "office hours" — rather than one person's availability, so
two kinds of appointment are two pages.

**New booking page** asks for:

| Field | What it means |
|---|---|
| **What the appointment is called** | Shown to whoever opens the link, and used as the title in your calendar |
| **What this is about** | Optional prose on the public page |
| **Bookings land on** | The calendar the appointment is written to. If that calendar syncs somewhere, the booking goes with it |
| **Check these calendars for clashes** | A time is only offered when nothing on these calendars overlaps it |
| **Your hours are in** | The zone the hours below are wall clock in |
| **Days you are bookable** | Mon–Sun; a new page starts with Monday to Friday |
| **From** / **Until** | The bookable hours, the same on every day chosen. A new page starts at 09:00–17:00 |
| **Appointment length (minutes)** | Defaults to 30; five minutes is the shortest |
| **Buffer (minutes)** | Quiet time left either side of anything already in the diary. Defaults to 0, up to four hours |
| **Shortest notice (minutes)** | How soon from now somebody may book. Defaults to 120, up to 30 days |
| **Bookable up to (days ahead)** | Defaults to 30, up to 366 |

Two details are worth pulling out.

**The two sets of calendars are not the same set, and that is the point.** *Bookings land on* is where
an appointment is written; *Check these calendars for clashes* is what "free" is measured against. The
useful configuration is asymmetric — bookings land on one calendar you keep for them, and free has to
mean free against your work calendar, your personal one and the mirrored one from Outlook. The
destination is always checked for clashes whether or not you ticked it, which the form says: *The
calendar bookings land on is always checked, whether or not it is ticked here.* A page whose
destination was not in its own busy set would double-book itself on the second request.

**The buffer applies to what is already in your diary, not to the slots.** An event from 10:00 to
11:00 with a fifteen-minute buffer occupies 09:45 to 11:15 as far as the slot list is concerned, so
the slot before it and the slot after it both go. That is what makes it symmetric with no special
case.

The hours are wall clock in the page's zone, which is its own setting rather than the destination
calendar's: 09:00 stays nine in the morning when the clocks change, and moving a page to a calendar
displayed in another zone must not silently move the hours it offers.

Every number is clamped rather than validated. A page whose hours are backwards or whose weekday list
is empty renders and offers nothing, because a public URL that errors because you typed 0 in a box is
worse than one that quietly has no times. The one refusal is a page with no writable destination
calendar: *Choose a calendar that accepts changes for bookings to land on.* Unlike a link covering
nothing, a booking page with nowhere to write would accept appointments and lose them.

**Switch off** takes a page down without losing its hours or its destination, which is what you want
for a fortnight away. A disabled page 404s exactly like an unknown address.

### What a booker sees

A card naming the appointment, its length and the zone the hours are printed in, and under it one
**week** of your availability: seven columns, each holding the times still free on that day. Times
are first drawn in your zone and then re-drawn in theirs — only the display changes; which slots
exist is decided entirely by your hours and your diary. The ◀ ▶ steps move a week at a time and stop
at the ends of what the page offers rather than paging into empty weeks. The page opens on the first
week that has anything in it, which matters when your shortest notice is long: a page with a
fortnight's notice has nothing this week by construction.

A day with nothing free says *Nothing free* rather than disappearing, and days that have already
passed are dimmed. Nothing says **why** a time is missing — a gap in your morning is indistinguishable
from an hour outside your working day, and an empty column from a day off. Whoever holds the address
is not entitled to read your diary out of the shape of the holes in it.

They fill in **Your name**, **Your email** — *the confirmation and the calendar file go here* — and an
optional note, and press **Confirm the booking**. Choosing a time and filling the form is one submit,
not two pages.

Like the shared calendar, the page is drawn in your appearance and says nothing else about you.

Afterwards they land on a page saying **That is booked**, and a confirmation with an `.ics` attached
is sent to them. That mail comes **from you**, through your own account, because it goes to a stranger
about a meeting with you and mail from nobody about a meeting with nobody is worse than none. The
attachment carries no invitation method, so no client tries to RSVP to it.

If your diary leaves nothing free, the page says *There are no free times at the moment. Try again
later.* If it is only this week that is full, the week itself says *No free times this week. Try the
week either side.*

The public POST is rate limited to six attempts an hour per address, because it creates rows and sends
mail, which is the definition of a spam vector — and the address in the URL is no help, since the
person abusing the page is holding the same one. Somebody who trips it is told *That is a lot of
bookings from one place. Please wait a while before trying again.* Reading the page is deliberately not
limited: a limit there would let one stranger take your published page off the internet by refreshing
it.

### What a booked event looks like on your calendar

An ordinary event, on the calendar the page names, at the hour that was taken. Three things mark it:

- **The title is the page's name and the booker's**, in that order — *30 minute intro call — Sam
  Reyes*. Your week is a list of titles, and "30 minute intro call" four times over says nothing.
- **The description carries who booked it**: `Booked by Sam Reyes <sam@example.com>`, followed by their
  note. That is also the only copy that travels — a synced calendar carries the description to the
  provider, so you still know who booked and how to reach them when you read the meeting on your
  phone.
- **A badge** — *Booked through your booking page* — because it is the only kind of event on your
  calendar that somebody outside this install can cause to appear, and "which of these did I not put
  here?" is a question you will ask the first time a page is abused.

The booker is deliberately **not** an attendee on the event. Pushing an attendee list to a provider is
how the provider decides to send the invitation again to everybody on it, which would mail a stranger a
meeting request they did not ask for on top of the confirmation they did.

The event is otherwise entirely normal: it is pushed to a connected calendar like anything else, it
can be edited, moved and deleted, and cancelling it frees the slot again. There is no cache — the slot
list is rebuilt from your diary on every page load, so calling a meeting off brings its hour back on
the next refresh and dragging one to Thursday moves the hole with it.

Nothing about a booking can be rewritten by mail arriving afterwards. The only party who could send
mail carrying its identity is the booker, who has no standing to move an hour in your diary.

### Two people, one slot

Double booking is refused by the database and by nothing else. Two strangers pressing **Confirm** on the
same half hour at the same instant both read the slot list before either wrote to it, so any
check-then-insert answers "free" to both — narrowing that window makes the bug rarer and never makes it
go away, and "rarer" is the worst possible property for a bug that quietly puts two people in one
appointment. The database is the only participant that sees both requests, so it is the only one that
can decide between them.

The loser sees the page again, with the slot gone and a line saying:

> That time has just been taken. Please choose another.

The winner's booking is complete. The loser's event and booking are both undone together — they were
written as one unit of work, so the refusal took the event with it and there is nothing half-written
left behind.

A stale form — a page left open while the hours changed underneath it — gets a different message on
purpose: *That time is no longer being offered. Please choose another.* Telling somebody they lost a
race they were never in would send them round the loop for nothing.

## Things that bite

**The address cannot be shown again, ever.** Not by an administrator, not from the database, not from a
backup. If you closed the dialog without copying it, use **Regenerate** and send the new one.

**A busy/free link still reveals your pattern.** It reveals nothing *concrete* — no titles, no
locations, no names — but a reader can see exactly which hours of which days you are occupied. That is
the entire purpose, and it is worth being deliberate about who you send it to.

**Marking events secret makes a shared link lie about your availability.** They vanish rather than
appearing as busy, so the link shows you free at hours you are not. That is what "secret" asks for; if
you want the hour blocked without the detail, use **private** instead.

**Editing a link can widen it silently.** Ticking another checkbox on an existing link changes what
everybody already holding its address can see, without the address changing. That is deliberate —
narrowing a link must not require re-sending it — but it cuts both ways. The settings list shows what
each link reveals, and **Regenerate** is what you use when you meant to cut somebody off.

**A revoked link and an unknown one are indistinguishable from outside.** Both are a 404. There is no
"this link has been revoked" page, on purpose.

**Deleting a booking page deletes the appointments booked through it.** The confirmation says so:
*Delete "…"? The appointments already booked through it go with it.* Use **Switch off** if you only
want it down for a while.

**A booking page whose hours are impossible offers nothing rather than complaining.** An end before a
start, a slot longer than the working day, or no weekdays ticked all produce a page saying there are no
free times. The numbers are clamped where they can be, and this is what "clamped" looks like when the
combination is nonsense.

**A page that offers no slots may be doing exactly what you told it to.** Check the shortest notice
against the horizon: a notice period longer than the window ahead leaves nothing bookable at all.

**Only your first mail account can send the confirmation.** The confirmation and the booker's calendar
file go out through it. An install whose first account cannot send is an install whose bookers hear
nothing — the booking itself still lands on your calendar, because a mail server refusing a connection
is not a reason to unbook somebody.

---

**Related:** [Calendar](calendar.md) · [Connected calendars](calendar-sync.md) ·
[Reminders](calendar-alerts.md) · [Security](security.md)

**How it works:** [Security model](../internals/security-model.md) — how public tokens are stored, and
what a public link can reach. [The calendar model](../internals/calendar-model.md) — event privacy and
where a booked event's fields live.
