# Invitations and events from mail

Most of what belongs on a calendar arrives as mail first — a meeting request, a flight
confirmation, a parcel with a delivery window, or a sentence somebody wrote agreeing to Thursday at
two. plMail reads all four, and treats them differently according to how much it can honestly claim
to know.

There are three levels of confidence, and it is worth knowing which one you are looking at:

| What arrived | What plMail does | Where it appears |
|---|---|---|
| A real invitation (a `text/calendar` part) | Offers you the RSVP buttons, and puts it on the calendar once you say **Yes** or **Maybe** | The card above the message, and — once answered — the calendar |
| A booking with schema.org markup — flights, parcels, hotels, restaurants, tickets, orders | Puts it on the calendar, marked as found in mail | The calendar, and *Happening soon* |
| A date written in ordinary prose | **Offers** it, and adds nothing until you say yes | A card above the message only |

The third one never writes to your calendar on its own. That distinction is enforced in the data
rather than by convention — a proposal has no occurrence anywhere, so no calendar view can show one
by accident.

## Invitations

When a message carries a calendar invitation, a card appears above it with the meeting's title,
when it is, where it is, and who organised it. The card describes the meeting *as it now stands*,
not as this particular message described it: when an update has arrived since, the original
invitation is still where you go to answer, and the answer belongs to the current meeting.

**Yes**, **Maybe** and **No** are the three answers. Your current answer is highlighted, and shown
as a chip — *Accepted*, *Maybe*, *Declined* — beside the meeting. An invitation you have not
answered says *No reply*.

Below that, **N invited** expands to the full participant list with each person's answer, the
organiser marked as such and your own row in bold.

Answering does two separate things, and they can fail independently:

1. **Your answer is recorded here, always.** It goes onto the event immediately, whatever happens
   next.
2. **A reply is sent to the organiser**, as a standard iTIP reply, through the same account the
   invitation arrived on — a Gmail account sends it through the Gmail API, an IMAP account through
   its own SMTP. If that fails you are told: *"Your answer was saved here, but the reply could not
   be sent to the organiser."* Nothing is rolled back. An organiser who never heard "no" keeps a
   seat for somebody who thinks they declined, so it is worth saying out loud rather than
   swallowing.

No copy of the reply is filed in Sent.

A cancellation shows the title struck through and says *This meeting has been called off*, with no
answer buttons. The event stays on the calendar, struck through, rather than disappearing — "was
this called off or did I imagine it?" is a question a calendar should be able to answer.

**An invitation is on your calendar once you accept it, and not before.** Until then it lives on
the card above the message and nowhere else. **Maybe** counts as yes — a tentative meeting is one
whose slot you still have to keep, and hiding it is how people double-book — so it is drawn too,
striped in its calendar's colour so you can tell it from a firm yes. **No** takes it back off. Nothing about that is one-way: the invitation itself never goes anywhere, so changing your
mind later moves the meeting on or off again.

**The calendar shows your answer as you give it.** With the calendar open beside your mail, pointing
at **Yes** or **Maybe** — or tabbing to it — outlines the slot the meeting would take, dashed, in its
calendar's colour, and striped for Maybe. Answer, and the meeting is in that slot straight away: the calendar is redrawn in
place, without reloading the page, and the meeting is ringed for a moment so your eye finds it. With
motion set to **Full** the answer itself travels there, turning into the meeting on the way; at
**Minimal** or **None**, or when your system asks for reduced motion, nothing moves and the outline
is the whole of it. With the calendar closed, the answer lands on the calendar button in the top
bar, which keeps a dot until you open it.

This applies to invitations addressed to you. A flight confirmation, a parcel, a date you accepted
out of a sentence, a calendar you mirror from Google, a meeting *you* organised — none of those are
anybody's to accept, and all of them appear straight away as they always did.

### A calendar file that is not an invitation to you

Not every calendar file in a mail asks you anything. An appointment attached as `termin.ics` by a
recruiter or a booking system names no organiser and no guests; an invitation forwarded from
somebody else's diary names people, and you are not one of them. Anybody can send you either, so
neither is put on your calendar for you.

The card above the message says *"Attached to this message — nothing has been added to your
calendar"* and offers two buttons. **Add to calendar** puts it there; **No thanks** leaves it off,
and the card remembers which you chose. You can change your mind later with the other button. No
reply is sent to anyone — nobody asked.

Such a file is recognised by its name as well as by its type, because it is very often sent as "a
file" and nothing more.

Events of this kind that were already on your calendar before this rule existed stay there.

Answering an invitation does **not** count as editing the event. The organiser is still the
authority on when the meeting is, so a later message moving it still moves it here, even after you
accepted. Their update also cannot un-answer it: an organiser's "send update" carries the attendee
list as *they* last saw it, which routinely still says nobody has replied, and believing that would
quietly take an accepted meeting off your calendar.

### A change from somebody who did not send the invitation

A meeting is recognised by an identifier inside the calendar file, and that identifier is no secret:
everyone who was invited has it, and so does anyone a copy was forwarded to. So a message asking to
move or cancel a meeting is only acted on when it comes **from where the meeting came from** — the
address that sent the invitation, or the organiser the event names. That covers the organiser's own
mailbox and a calendar service that sends on their behalf, since such a service sends the updates
from the same address as the invitation.

From anybody else, nothing is changed. The card above the message says *"This message asks to change
this event, but it was not sent by whoever the event came from. Nothing was changed"* and names the
sender, with one button: **Apply the change**. Use it when you know the message is genuine — the
organiser wrote from a different address, or a colleague sent the update for them. Once applied, that
sender counts as one the meeting has come from, and their next update is not asked about.

The same holds for a meeting that reached your calendar from a connected Google, Microsoft or CalDAV
calendar rather than by mail: only mail from its organiser changes it unasked.

## Events read out of a booking

Confirmation mail from airlines, couriers, hotels, restaurants and ticket sellers routinely carries
machine-readable markup describing the booking — the sender has already parsed it into structured
fields, so reading it is arithmetic rather than inference. That is what makes it safe to write to
the calendar without asking.

Each such event carries a kind, which decides the icon it is drawn with:

**Meeting** · **Delivery** · **Flight** · **Train** · **Stay** · **Reservation** · **Rental** ·
**Ticket** · **Order** · **Call**

The kind is also what marks the event as having come from mail rather than from you. Open one in the
editor and it says so: *"This event was found in an email. Editing it stops later messages about the
same booking from overwriting your changes."*

That sentence is a real rule and not a warning. Booking mail arrives in a sequence — a
confirmation, then a change, then perhaps a cancellation — and often out of order, so plMail keeps
revising the same event as more mail about it turns up. A later revision wins by the revision number
the sender stated, falling back to when the mail arrived; a superseded claim is still recorded, so
"why is this on my calendar?" stays answerable. A cancellation sets the status and strikes the event
through; it never deletes the row.

The moment you edit the event yourself, that stops. Your version is the one that stays.

### Only from a sender your mail server vouches for

Markup in a mail is written by whoever wrote the mail, and the From line is not proof of who that
was. So a booking is put on your calendar unasked only when your mail server has checked the sender
and says the From address is genuine (it records this in the message's `Authentication-Results`
header). The same check decides whether a later mail may move or cancel a booking you already have.

| Account | Whose verdict is believed |
|---|---|
| **Gmail** | Google's. |
| **Outlook / Microsoft 365** | Microsoft's. |
| **Other (IMAP)** | A server on the same domain as the account's IMAP host — `mx.example.org` for an account on `imap.example.org`. |

Everything else — a sender that failed the check, a server that does not record one, a server
plMail cannot tie to the account — gets the same card as a calendar file that is not an invitation
to you: *nothing has been added to your calendar*, with **Add to calendar** and **No thanks**. A
change to a booking you already have is held the way a meeting change is, with **Apply the change**.

### Not an event

An extracted event has a **Not an event** button in the editor, which is a different act from
deleting it. Deleting means "not any more". Dismissing means "this was never an event", and only the
second one is worth remembering:

> Remove this event and stop it coming back? Later emails about the same booking will not put it on
> the calendar again.

What is remembered is the booking's identity rather than the row, so the *next* message about the
same booking is refused too — which is the one that would otherwise put back a second copy of what
you just threw away. It also survives a re-run of extraction over your stored mail, which is
something plMail does whenever the reading gets better.

Only extracted events can be dismissed. A hand-made event has no claim behind it and nothing would
ever re-create it.

## Dates found in prose

A message that says "shall we say Thursday at 14:00?" carries no markup and no invitation. plMail
reads it anyway, and then does the only honest thing available: it offers it.

A proposal card appears above the message with a dashed edge and no calendar colour, because nothing
has been added to a calendar yet — it says *"Found in this message — nothing has been added to your
calendar."* Underneath, the sentence it was read from is quoted verbatim, which is the point of the
card: a guess whose evidence you can see is judgeable in a second, and a bare date with an Add button
is not judgeable at all.

**Add to calendar** writes the event, on the calendar the message's account files things to, keeping
the sentence as the event's description so the reasoning survives the card. The event is a normal
one from then on: no "found in your email" affordances, no reconciler revising it, full confidence —
you read the sentence and agreed with it.

**Not an event** remembers the refusal the same way a dismissal does, so the date is not offered
again however often the message is read.

### When plMail is willing to guess

Precision over recall, throughout, because the two mistakes do not cost the same: a missed
suggestion is a card nobody sees, and a card offering to calendar "Sale ends Friday!" is the moment
somebody goes looking for the switch. Every rule refuses on doubt.

A message is only considered when **all** of these hold:

- It is not a draft, and it belongs to somebody.
- It was sorted into **Primary** at ingest — bulk, marketing and mailing-list mail is out.
- It carries no list or bulk headers, even if it landed in Primary anyway. A shop you once wrote to
  is still a shop.
- One of your own addresses is in **To** or **Cc**. A date announced to a list is an announcement; a
  date sent to you is an arrangement. This rule removes most of what survives the ones above.
- There is nothing an extractor could read — no calendar part, no meeting flag, no schema.org
  markup. A real event is on its way and will be better than a guess.
- The date is not in the past and is within a year, judged against **the message's own date** rather
  than against now, so re-reading old mail reaches the verdict it would have reached the day the
  mail arrived.
- Nothing about it has been refused before.

And the text itself has to be unambiguous. Explicit and semi-explicit forms in German and English
are read — `04.08.2026 um 14 Uhr`, `4. August 2026, 14:00`, `2026-08-04 14:00`, `Samstag um 15 Uhr`,
`Saturday at 3pm`, `tomorrow at 9`, `next Tuesday 10:30` — with a duration where the mail states one
(`2 Stunden`, `90 Minuten`, `2 hours`).

Three rules shape the reading:

- **A date and a time, in the same sentence, near each other.** A date alone is refused, because
  "gültig bis 31.12.2026" in a footer is a date. A time alone is refused for the same reason with
  the day missing instead of the hour. And a paragraph mentioning a deadline in its first line and an
  opening hour in its last states two facts, not one appointment.
- **The first sentence that yields both wins** — not the first date in the message. Signature blocks,
  copyright years and unsubscribe footers all carry dates, and none of them carries one next to a
  time.
- **Relative words resolve against the message.** "Saturday" in a mail sent on a Friday still means
  that Saturday a year later.

What it deliberately refuses: a two-digit year (`04.08.26`), a numeric day-and-month with no year at
all (`04.08.`), a slashed date your own locale reads as impossible (`13/25/2026`), and a bare hour
that no *at* or *um* introduces — "Room 3, seats 12" is not half past midday. Hours are read on a
24-hour clock unless a meridiem says otherwise, so `tomorrow at 9` is nine in the morning.

Written out, `4. August` **is** accepted: the month name removes the ambiguity, and the year
resolves forward from the message.

## Happening soon

When something is coming up, a button appears in the topbar wearing the next thing's icon — a plane,
a box, a train, or a plain calendar mark for an appointment you entered yourself. Pressing it opens
**Happening soon**: everything due within the next fortnight, soonest first, up to twelve entries.

Each row gives an icon, when it is, what it is, and — for the rows read out of mail — which message
it came from. That last one is the one that matters: an extracted row is a guess a program made about
your mail, and "why is this on my calendar?" has to be answerable in one click or the guess cannot be
checked at all. A row whose message has since gone renders without the link rather than with a dead
one, and so does a row you typed, because nothing made that one but you.

The button is only there when there is something to open, deliberately. A control that is always
present and usually says "nothing coming up" trains people not to press it; one that appears when a
parcel is due is a piece of news.

One thing is **not** in this list, deliberately:

- **Proposals.** A date read out of a sentence and not yet accepted has no business sitting in a list
  people trust to be true, and stripped of the sentence it came from it could not be judged anyway. A
  proposal is answered where its evidence is: on the message.

Only visible calendars are read, so hiding a calendar hides its bookings here too.

Separately, the calendar button in the topbar carries a coloured dot for what is still ahead
**today** — inside the hour, within a few hours, or later today. It is three bands rather than a
countdown, because a colour you have to read a number off is not a dot, and it considers only today:
a meeting that finished an hour ago is not news and tomorrow's is not urgent.

## Re-reading mail you already have

Extraction and proposal detection both improve over time, and both can be re-run over mail that is
already synced:

```bash
docker compose exec php php bin/console app:backfill events
docker compose exec php php bin/console app:backfill proposals
```

Run with no argument, `app:backfill` lists the available tasks and asks. Re-running is safe:
anything you dismissed stays dismissed, and anything you edited yourself is not overwritten.

`events` reads **the last 24 hours of mail** unless you say otherwise — the usual reason to run it is
a mail from this week, not every calendar file of the last ten years. `--since` widens it: a count
with `h`, `d` or `w`, a date, or `all`.

```bash
docker compose exec php php bin/console app:backfill events --since=7d
docker compose exec php php bin/console app:backfill events --since=2026-09-01
docker compose exec php php bin/console app:backfill events --since=all
```

## Things that bite

**Mail in Spam or the bin is not read for events.** An invitation or a booking your provider filed
under Spam does not reach the calendar. Move the message out — **Not spam**, or restore it from the
bin — and it is read then.

**On a self-hosted mailbox, bookings may always ask first.** If your IMAP server does not stamp its
verdict on incoming mail, or stamps it under a name on a different domain than the IMAP host, plMail
has nobody to believe and every booking arrives as a card to accept. Invitations are unaffected.

**A forged sender cannot be told from a real one where the server reports nothing.** On such an
account a meeting change is judged by its From address alone, the same address you would judge it by
reading the mail. Gmail and Outlook accounts do not have this gap.

**An invitation you have not answered is not in your calendar.** That is deliberate — see above —
but it does mean a week of unanswered requests looks like a free week. The card above each message
is where they are; press **Yes** or **Maybe** and they appear.

**An invitation that arrived before this behaviour existed stays on the calendar whatever you
answered.** Nothing was rewritten on upgrade, on purpose: emptying somebody's calendar during a
`docker compose up` is not an acceptable way to ship a feature. `app:backfill events --since=all`
re-reads the mail and brings the old ones in line.

**Your RSVP can be saved without the organiser hearing it.** The two halves are independent on
purpose. When the reply cannot be sent, the toast says so — and that is the only notice you get.
Check that the account the invitation arrived on can actually send mail.

**Only Primary mail is ever read for prose dates.** If a message you expected a suggestion from
landed in another category, or carries list headers, or was addressed to a list rather than to you,
no card appears and nothing is wrong. This is the rule that removes the most noise and it is also the
one that will occasionally remove something you wanted.

**Dismissing a proposal or an extracted event is permanent for that booking.** Both write a refusal
keyed on the booking's identity, so later mail about the same booking is refused too. There is no
undo screen; add the event by hand if you change your mind.

**Editing an extracted event stops it tracking the booking.** That is what the note in the editor
means. Correct a wrong time by hand and a later mail moving the meeting will no longer move it for
you.

Only a real correction counts, though. Opening the event and ticking another calendar — putting the
same meeting on your work calendar as well — changes nothing about the meeting, so it does not stop
it tracking, and a later mail moving it moves **every** copy. That used to be the other way round: a
share was recorded as an edit, the event went quiet, and the only way to notice was that a
reschedule you had been told about never appeared.

**Deleting an extracted event is not the same as dismissing it.** A plain delete lasts until the next
message about that booking arrives, or until the next time extraction is re-run — and then it comes
back. **Not an event** is the one that sticks.

**A cancelled meeting stays on the calendar.** It is struck through rather than removed, because the
useful answer to "wasn't there something today?" is the struck-through entry rather than a gap.
Delete it yourself if you want it gone.

**Happening soon reaches exactly a fortnight.** A flight three weeks out is on the calendar and is
not in the list. Two weeks is how far ahead a booking is still something you would act on today.

---

**Related:** [Calendar](calendar.md) · [Reminders](calendar-alerts.md) ·
[Connected calendars](calendar-sync.md)

**How it works:** [Event extraction](../internals/event-extraction.md) — how an invitation and how a
sentence become a calendar entry, and how later mail revises one.
