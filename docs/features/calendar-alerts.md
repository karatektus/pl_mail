# Reminders

A reminder goes off before an event and tells you about it — as a notification on the devices you
have allowed, or as an email to yourself. plMail calls them *Reminders* in the editor and *alerts*
in the log and the console; they are the same thing.

Reminders are stored on the event in the vocabulary every calendar server understands, so one set in
plMail arrives in Outlook, Google Calendar or a CalDAV client, and one set there arrives here. There
is no plMail-only reminder field for a mapper to have to learn about.

## Setting one

Reminders live in the event editor, under **Reminders**. Six offsets are one click each, in this
order:

- **At the time of the event**
- **5 minutes before**
- **10 minutes before**
- **30 minutes before**
- **1 hour before**
- **1 day before**

Tick as many as you want; an event may carry up to ten in total.

Below them is **Something else, in minutes**, a plain number field, with **How the extra reminder
arrives** beside it — **Notification** or **Email**. The custom field only ever *adds* a reminder; it
does not change the six above, which are always notifications.

The custom field takes anything from one minute up to **44,640 minutes — thirty-one days**. That
ceiling is not politeness: it is exactly as far ahead as a reminder can be honoured, so a larger
number would be stored, would round-trip, and would never fire. Zero, a negative and anything past
the ceiling are ignored rather than refused, and an empty field is the ordinary case.

### Reminders that came from somewhere else

The list also shows any reminder already on the event that is not one of the six — an alarm set in
another client, imported from an `.ics`, or mirrored from a connected calendar. Those appear
described in words rather than as a choice you could have made: *2 hours before the start*, *15
minutes after the end*, *At 2026-08-12 09:00* for one pinned to an exact instant. An email reminder
is marked *· Email*; notifications are left unmarked, because writing "· Notification" on five rows
out of six would make the one that says Email harder to spot rather than easier. A trigger this build
cannot read at all says *Reminder set somewhere else*, so it can still be unticked instead of
rendering as a blank row.

You can untick any of them. You cannot create one of those shapes here — the editor deliberately has
no way to express an absolute trigger — which is the trade that lets a Google reminder, a CalDAV
alarm and plMail's own six sit in one list.

### Reminders belong to the whole event

On a repeating event the editor says so where you would otherwise assume the scope radio applies:

> Reminders belong to the whole event, so the choice below does not apply to them.

A save scoped to **This event** writes that occurrence's times and title and leaves the reminders on
the series exactly as they were. There is no way to give one occurrence a reminder of its own.

The other half of that is useful: a repeating event with one reminder produces **one reminder per
occurrence**, automatically. An occurrence you dragged to Thursday is reminded about on Thursday, and
one you cancelled is not reminded about at all — the reminder reads the same occurrence rows the
calendar views read, so it inherits every move and cancellation for free.

## How a reminder is delivered

Every minute, plMail sweeps for reminders that have come due and sends them. A minute is the unit
people set reminders in, and it is the bound on how late one can be — a five-minute reminder
delivered on a five-minute cadence could arrive anywhere between on time and after the meeting
started.

Two channels, chosen by what the reminder says it is:

**Notification.** Delivered as a Web Push notification to every device that has confirmed a
subscription for your account. It is the same push mechanism plMail already uses for new mail, so
enabling notifications once enables both. The payload carries the title and the time — a
notification that says "something is happening" and makes you open the app to find out what is not a
reminder — and it is encrypted end to end under the device's own key, so the push service sees
ciphertext. Opening it takes you to the occurrence's own day.

**Email.** Sent to the address of your first mail account, from that same address, through that
account's own sending path — a Gmail account through the Gmail API, an IMAP account through its own
SMTP. From and To are the same address on purpose: this is a reminder rather than correspondence, it
has to land in the mailbox you actually read, and sending it from anywhere else would put it in spam
on any host that checks alignment. No copy is filed in Sent.

**There is no fallback between the two.** A notification reminder on an install with no push is not
quietly turned into mail. You asked for a notification, and a service that mails you instead is one
you stop trusting with your address.

### What a fresh install needs first

On a brand-new install, **neither channel can deliver anything**, and nothing about the editor tells
you that — a reminder ticked on an event that has nowhere to go looks exactly like one that works.

For notifications, the server side is already done: a VAPID keypair is generated on first start
along with the rest of the secrets. What is missing is a subscribed device. Go to
**Settings → Notifications** and turn **Enable notifications** on, on each device that should be
notified. The page reports what that device's state actually is — on, off, blocked in the browser,
waiting to confirm, or unsupported. On iPhone and iPad it only works once plMail has been added to
the Home Screen, which the page also says.

If the server genuinely has no keys — an install where they were set to empty by hand — the page says
*Push is not configured on this server*, and:

```bash
docker compose exec php php bin/console app:push:generate-vapid-keys
```

prints the lines to set.

For email reminders, you need at least one mail account that can send. A user with no mail account at
all is a perfectly ordinary state — you can delete your last account and keep a calendar — and it is
not treated as an error.

Either way, a reminder with nowhere to go is written down as having gone off and a warning is logged.
It is not retried; see below.

### Running it by hand

The sweep is `app:calendar:alerts`, scheduled every minute. It is safe to run by hand and safe to run
twice — what makes it idempotent is the record it writes, not the schedule.

```bash
docker compose exec php php bin/console app:calendar:alerts --dry-run
```

lists what is due right now without sending anything or writing anything down. Without `--dry-run` it
delivers, and also prunes the delivery records older than a week.

## Things that bite

**A reminder that fails to send is lost, not retried.** This is deliberate, and it follows from
firing exactly once. The record saying "this reminder has gone off" is written *before* anything is
sent, in a single insert that either succeeds or reveals that another sweep already owns it. The
alternatives both lose: sending first and recording afterwards means a container killed in between
re-sends a minute later, and checking first means two overlapping sweeps both decide to send. So a
push service having a bad thirty seconds costs that reminder. "Your meeting starts in ten minutes"
is not a true statement fifteen minutes later, which is why a lost reminder is the better failure.

**Turning reminders on does not deliver a year of history.** A reminder whose moment passed more than
**an hour** ago is not due and never will be, whatever the records say. That floor is what stops a
first sweep after an upgrade, or after importing twelve months of flights, from delivering every
reminder in your archive at once. It is also the limit on how much downtime the sweep can catch up
from: an hour of headroom covers a restart, a deploy or a long migration, and does not cover a
weekend.

**A reminder set more than thirty-one days before an event never fires.** It is stored, it
round-trips to your other clients, and the sweep does not look that far ahead — the alternative is a
query with no upper bound scanning the whole table every minute. The editor's custom field enforces
the same ceiling, so this only bites for reminders that arrived from elsewhere.

**A reminder measured from the *end* of an event longer than a day never fires either.** The sweep
reaches one day behind an occurrence's start for end-relative triggers.

**An exact-instant reminder on a repeating event is ignored.** One instant cannot mean each of a
hundred occurrences, and picking one of them to mean would be inventing an answer. On a one-off event
it is honoured normally.

**Occurrences are only written out for a bounded window** — roughly a year back and two years forward
from when the event was last saved — and a reminder needs an occurrence to attach to. A reminder on
something far enough in the future has nothing to fire against yet.

**Removing every reminder from an event does not clear the reminders at Google.** Google cannot
distinguish "this event has no reminders" from "this event uses the calendar's defaults" in a way
that round-trips, so plMail only writes reminders when there is at least one to write. Outlook has no
such ambiguity and does clear.

**A merged event loses reminders the copy you clicked does not have.** When one meeting sits on two
calendars and is drawn as a single entry, saving it writes the reminders you see to every ticked
copy — so an alarm that exists only on the copy you were not looking at goes. The alternative bug is
worse: unticking a reminder would silently leave it on copies you cannot see.

**Enabling notifications is per device, not per account.** Each browser, phone and desktop has to be
turned on separately in **Settings → Notifications**, and a browser that later revokes permission
stops receiving without saying so here.

---

**Related:** [Calendar](calendar.md) · [Connected calendars](calendar-sync.md) ·
[Invitations and events from mail](calendar-invitations.md) · [Other clients](clients.md)

**How it works:** [The calendar model](../internals/calendar-model.md) — where a reminder is stored on
an event, and how occurrences are materialised.
