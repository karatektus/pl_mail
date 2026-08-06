# Connected calendars

plMail can mirror calendars you keep somewhere else — Google, Outlook, a CalDAV server, or anything
published as an `.ics` at an address — and, for the first three, send your changes back. It can also
move a calendar in and out as a file.

Everything on this page lives under **Settings → Calendars**, which has two halves: the list of your
calendars, and **Where calendars come from** beneath it.

The provider-specific setup — which console, which client ID, which scopes — is on its own pages:
[Google](../providers/google.md), [Microsoft](../providers/microsoft.md),
[CalDAV](../providers/caldav.md), [ICS feeds](../providers/ics-feeds.md).

## Where calendars come from

Two populations that look like one on screen and are nothing alike underneath.

**Mail accounts that already carry calendars.** A Gmail or Outlook account you added for mail can
also see your calendars there, because plMail asks for calendar access on the same consent as
mailbox access. There is no second sign-in, no second application to register, and no second thing
to configure — the account shows a **Find calendars on…** button and that is the whole of it.

**Connections made for calendars alone.** A **CalDAV server** — Nextcloud, Radicale, Baïkal,
Fastmail, iCloud, a box in a cupboard — or a **calendar address**, which is any published `.ics`.

If the section says *No account or connection here carries calendars yet*, you have IMAP accounts and
nothing else; connect a CalDAV server or subscribe to an address.

### Picking which calendars to mirror

**Find calendars on…** lists everything the account or connection can see and asks you to tick the
ones to keep in step. Ticking one creates a calendar here and fills it in within the minute; the
one already marked *Main* is the provider's primary.

**Unticking one deletes the copy held here.** The screen says so, and adds the important half:
anything on that calendar that came from your mail rather than from the calendar is moved to your
default calendar first. That population is real — you can point a mail account's extracted events at
a mirrored calendar, and from then on every booking read out of that account's mail lands there as
the only copy in existence. Everything the remote actually gave you is deleted, because those are
copies and the provider still holds the originals; re-tick the calendar and they come back within the
minute.

Your default calendar cannot be unticked while it is the default: *New events land here, so this one
stays. Make another calendar the default first.*

Nothing the browser posts describes a calendar. Only the ticked ids are read; the name, the colour,
the time zone and — most importantly — whether the calendar accepts writes are re-read from the
remote as the subscription is made.

### Connecting a CalDAV server

**Connect a CalDAV server** asks for a name, a server address, a username and an app password.

The address can be the full CalDAV URL your server shows you, or just its domain — plMail asks the
domain where its calendars live before giving up. The form opens prefilled with the domain of your
own mailbox, which is a suggestion and nothing more.

Use an **app password** created on the server rather than your login password; iCloud and Fastmail
accept nothing else. There is a checkbox offering to reuse the password from one of your mail
accounts, which is off by default and worth leaving off — most servers want an app-specific password,
and the address in the box above is one you typed and nothing has checked, so ticking it sends a
password you gave plMail for your mailbox to whatever host is in that field.

Gmail and Outlook calendars do not come in this way. They arrive with the mail account.

**Disconnect** removes every calendar mirrored from that server, moving anything that came from your
mail to your default calendar first, exactly as unticking does.

### Subscribing to a published address

**Subscribe to an address** takes a single field: the address a calendar is published at, ending in
`.ics`. A `webcal://` link works too — it is the same address under another name, and plMail rewrites
the scheme for you. `webcals://` likewise.

This is how you follow public holidays, a fixture list, a colleague's published availability, or the
"secret address in iCal format" that Google and Outlook hand out for a calendar they will not give
API access to. Leave the name empty and the calendar names itself.

Two things the form says and means:

- **A subscribed calendar is read-only.** It is a copy of a published file; there is no method that
  would write to it and no server that would accept one.
- **Anyone holding the address can read the calendar**, so treat a secret one like a password.
  Nothing renders the address back once it is stored.

The feed is fetched exactly once while you wait, which is why a subscription that fails leaves
nothing behind — there is no credential to correct and no second field to change, so a broken row you
would have to notice and delete would only be in your way. The failure worth expecting: a
**Subscribe** button copies a link to a *web page* about as often as it copies a link to a calendar
file, and the two are indistinguishable until something tries to parse one.

## What two-way sync carries

For Google, Microsoft and CalDAV, changes travel in both directions. What crosses is the meeting
itself: title, start and end, time zone, all-day, location, description, status, the recurrence rule
with every per-occurrence change filed against it, participants, and reminders. A booking taken
through one of your booking pages, and an event you created on two calendars at once, both travel
like any other event.

What does not cross:

- **Anything about how plMail draws it.** A calendar's colour, its visibility, its position in your
  list, whether it is your default — those are yours.
- **Where an event came from.** plMail records whether an event was typed, read out of a message,
  mirrored, or booked; a provider has no field for that and nothing carries it back.
- **Attachments on an event.**

Two more limits are provider-specific and are covered under
[Things that bite](#things-that-bite): clearing every reminder does not clear Google's, and a
subscribed `.ics` feed is one-way by construction.

### Which side wins

The rules are short and they are worth knowing, because "changes sync back" is only worth having if
it is trustworthy.

1. **Your changes go out first, always.** Every pending local change is pushed before anything is
   pulled. So the pull that follows asks "did the remote change too?" of a remote that has already
   been told, and the ordinary case — you edited here and nobody else touched it — is not a conflict
   at all.
2. **An unchanged remote copy is not applied.** If the remote's version marker matches the one stored,
   nothing is written at all. This is what stops the pull straight after a push from re-applying the
   remote's echo of your own edit over something you typed in the meantime.
3. **A changed remote copy wins.** Not last-write-wins — there is no clock the two sides share, so
   comparing timestamps is comparing two guesses. The remote wins because it is the copy other people
   can also see: losing an edit you made on your phone is recoverable by making it again, and
   diverging from what an organiser and four attendees are looking at is not.
4. **When both sides changed, the local change is discarded — loudly.** Rule 1 means this only happens
   when the push for that event failed. The discarded version is written to the log in full before it
   is overwritten, so there is something to look at afterwards.
5. **A read-only calendar is never pushed to.** Your edit stays local, and the run says so once.

A calendar's row in settings shows when it last synced, or *Not synced yet*, and carries the last
error when there was one.

## Push, and why a self-hosted install usually polls

Connected calendars are swept every fifteen minutes. Google and Microsoft can also *push* — tell
plMail the moment something changes — and both notifications say only "something in this calendar
changed", so a webhook does exactly one thing: ask for a sync of the calendar it names.

Push needs a publicly reachable **HTTPS** address, because that is where the provider calls back.
plMail checks this itself rather than letting the provider refuse: the configured public URL must
start with `https://` and must not be `localhost`, `127.0.0.1` or `::1`. A great many self-hosted
installs fail that on purpose, and that is fine — **push is never load-bearing.** A calendar that
cannot register a channel is a working calendar fifteen minutes behind, and there is no arrangement
in which refusing to sync because push could not be registered would be better.

Google additionally requires the callback host to be **verified in the Cloud project that owns the
OAuth client** — verify it in Search Console, then add it under Domain verification in the Cloud
console. Until that is done, every registration is refused. It is at least visible: a warning in the
log at registration time, rather than a channel that silently never delivers. Microsoft has no
equivalent step. See [Google](../providers/google.md) and
[Microsoft](../providers/microsoft.md).

CalDAV servers and `.ics` feeds have no push at all and are always polled. Neither is a problem.

Channels are registered and renewed by an hourly sweep rather than at the moment you tick a calendar.
That is deliberate: registration fails for reasons that have nothing to do with your click — no
public address yet, a domain verification still pending — and tied to the subscribe screen alone,
those calendars would never get push until somebody thought to unsubscribe and re-subscribe them.
Driven from a sweep, the same install starts pushing within the hour of the underlying problem being
fixed, with nobody touching anything. Ticking a calendar does ask once, immediately, and gives up
quietly, which is what removes the first hour.

Some Google calendars can never be watched — a country's holidays, birthdays drawn from Contacts,
week numbers. Google refuses those permanently, plMail remembers the refusal, and the calendar simply
reads as polled.

### Syncing by hand

Each calendar row has a **Sync now** button, which answers *Syncing now — this calendar will update
in a moment.* From a terminal:

```bash
# every connected calendar
docker compose exec php php bin/console app:calendar:sync

# one of them
docker compose exec php php bin/console app:calendar:sync 12

# only those due — this is what the scheduler runs, every fifteen minutes
docker compose exec php php bin/console app:calendar:sync --stale
```

And for push channels:

```bash
# register and renew whatever needs it — the scheduler runs this hourly
docker compose exec php php bin/console app:calendar:push

# re-register everything regardless of expiry
docker compose exec php php bin/console app:calendar:push --force

# tear the channels down and go back to polling
docker compose exec php php bin/console app:calendar:push --stop
```

`app:calendar:sync` dispatches the work to a background worker rather than doing it in the console
process, so a sync you run by hand behaves exactly like a scheduled one — including its retries.
`app:calendar:push` reports "staying on polling" rather than failing, for the reason above.

## Calendars as files

### Export

Every calendar row has a **Download as .ics** button, and every event has **Download .ics** in its
editor. An exported event is the whole series, not the one occurrence you clicked.

A whole-calendar download is streamed one meeting at a time, so a decade of a busy calendar costs one
meeting of memory rather than ten years of it — with the visible consequence that the browser shows an
indeterminate progress bar, because the size is not known until the last event has been written.

The file is a calendar object, not an invitation: no `METHOD` is written, deliberately, so
downloading your own calendar does not make some clients try to send four hundred meeting requests on
your behalf.

### Import

**Import a calendar file** on the settings page reads an `.ics` — an export from another calendar, or
an invitation somebody sent you — onto a calendar you pick. Files up to **4 MB**.

Only calendars that accept new events are offered. If you have nothing but mirrored calendars, the
picker says so: *None of your calendars accepts new events. A mirrored calendar is a read-only copy;
make one of your own first.*

Import has one rule that is not obvious, and the form states it:

> An event already on this calendar is updated rather than added twice, and one already on another of
> your calendars — from an invitation, or from a calendar you mirror — is left where it is.

So three outcomes, and the middle one is the surprising one. On the target calendar under the same
identity: updated, which is what makes export-then-import a round trip rather than a duplication. On
a *different* calendar of yours: skipped and counted, because you picked this calendar and rewriting a
row on another one is not what you asked for — least of all when the other one is a mirror, where the
rewrite would be pushed out to the provider. Nowhere yet: created.

The result is reported as *N added, N updated, N skipped*, or, when there was nothing to do,
*Nothing to add — every event in that file is already on your calendars.* A file plMail cannot read
as a calendar says so rather than importing zero events silently.

Two things an import deliberately ignores: the revision number in the file, and anything you have
dismissed. You chose this file and pressed a button — refusing half of it because the exporter wrote
a lower revision than the original invitation, or leaving out the one event you were looking for
because you once dismissed it, would both be the import quietly doing nothing where it matters most.

## The same meeting arriving twice

One meeting can reach plMail by two honest routes at once: extracted from its invitation onto your
default calendar, and mirrored from the provider onto a connected one. Both rows are correct, and
plMail does not delete either. The duplication is answered on screen instead.

Two entries are treated as the same meeting when they share an identity **and** a start instant.
Identity is the UID the organiser assigned — not the title and the time, because matching on those
would collapse a weekly one-to-one held with two different people at the same hour into a single
entry, and a meeting quietly disappearing from a calendar is the worst shape a calendar bug takes.

They are **drawn as one entry only while they agree** about the five things you can see: start, end,
title, all-day, and whether it has been called off. Agreeing, you get one entry showing both
calendars' colours, saying *On Work, Personal*, opening one editor with both ticked. Disagreeing,
the group splits and you get an entry each — deliberately, because a merged entry that quietly picked
a winner would hide a real disagreement (an update that reached one route and not the other) behind a
tidier screen.

Recurrence is not one of the five. Two copies where one repeats and the other does not agree about
the one occurrence they share and about nothing else, so that occurrence merges and the repeating
copy draws its own entries on every later day — which is itself the visible signal that the two
differ.

## Things that bite

**Unticking a calendar deletes the copy held here.** It is not "stop syncing and keep what I have".
Events the remote gave you go; events that came from your mail are moved to your default calendar
first. Re-ticking pulls the remote's events back.

**An unpushed local edit is lost when you unsubscribe.** An event carrying both a remote id and a
change that has not gone out yet is deleted with the rest, so that change never reaches the provider.
The window is one sweep — fifteen minutes — and it is logged. Wait for a sync before unsubscribing if
you have just edited something.

**Google's consent screen lets you untick calendar access while granting mail.** The account then
syncs mail perfectly and answers every calendar call with a refusal. The **Find calendars** screen
says so rather than showing an empty list: *Google's consent screen lets you untick calendar access
while allowing mail. Reconnect the account and leave the calendar box ticked.*

**On Microsoft, the calendar permission may be missing from the app registration.** Mail keeps
working without it; calendars do not. The same screen says so, and it is an administrator's fix — see
[Microsoft](../providers/microsoft.md).

**The first read of a Google calendar goes back one year, not forever.** A calendar in use for a
decade holds tens of thousands of events, and an unbounded first read would fetch every one of them
to fill a local table with meetings from 2016 that no view will ever show. Forward, it is unbounded.

**A Microsoft calendar is synced over a window, not in full.** Graph offers change tracking on exactly
one surface, and it is a bounded one: a year back and two years forward, matching the range plMail
draws occurrences into. Events outside that window are not mirrored.

**Removing every reminder does not clear the reminders at Google.** See
[Reminders](calendar-alerts.md#things-that-bite).

**A `.ics` subscription re-downloads the whole file whenever it changes.** There is no delta feed for a
published file, and a file states what exists rather than what was removed — so a feed that has
changed is read again from scratch, which is what stops cancelled fixtures accumulating forever.
Unchanged, it costs a conditional request and no body at all, which is why polling a holiday feed
every fifteen minutes is free. Feeds are read up to 8 MB.

**An import onto the wrong calendar cannot be redirected afterwards.** Events already on another of
your calendars are skipped rather than moved, so importing the same file onto a second calendar adds
nothing there.

**Two entries for one meeting means the two copies disagree**, not that deduplication failed. Look at
the start, the end, the title, the all-day flag and whether one of them is cancelled — one of those
differs. Open either and save it with both calendars ticked to bring them back together.

---

**Related:** [Calendar](calendar.md) · [Invitations and events from mail](calendar-invitations.md) ·
[Reminders](calendar-alerts.md) · [Sharing and booking](calendar-sharing.md)

**Setting up a provider:** [Google](../providers/google.md) ·
[Microsoft](../providers/microsoft.md) · [CalDAV](../providers/caldav.md) ·
[ICS feeds](../providers/ics-feeds.md)

**How it works:** [The sync engine](../internals/calendar-sync-engine.md) — the driver contract every
provider implements, push channels, and how duplicates are resolved.
