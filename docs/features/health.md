# Account health

**Settings → Account health** is the page that answers "why has my mail stopped?" — and, where it
can, offers the button that fixes it.

Everything on it was already known. plMail records a refresh token that stopped working, a calendar
whose sync keeps failing, a push registration that has gone quiet, a file service whose connection
lapsed; it simply never showed you any of it, so a broken sign-in looked like a mailbox that had
gone quiet on its own. This page reads that stored state back. It never probes: nothing here makes
a request to a provider to find out how things are, so opening it is free and its answers are as
fresh as the last time something was tried.

## The entry appears when there is something to say

**Account health is not a permanent item in the settings list.** It is there when something needs
attention, and absent when nothing does — the entry showing up is itself the signal, rather than a
row that spends a slot in a long list saying everything is fine.

That is a change. If you have read an older version of this page, or learned where the item sits, you
will go looking for it on a good day and not find it. Nothing is broken and nothing has been removed.

The page itself is always reachable. The URL keeps working, it is where reconnecting an account
returns you, and the topbar's own indicator points at it — so a bookmark, or a dot you followed after
fixing something, lands on the page rather than being bounced somewhere else. Visiting it while
everything is healthy shows the all-clear: *Everything is working*, with a line saying the entry will
come back to the settings list if mail ever stops arriving. It is not a wall of green checkmarks and
it is not empty. While you are standing on the page the nav entry is shown too, so there is something
for the list to mark as current.

## What it reports

Six things can appear, and each one says what it means for your mail rather than what the provider
called it:

| What | What it means | Repair |
|---|---|---|
| **An account needs you to sign in again** | The stored sign-in no longer works, so nothing about this account is running — no new mail, no calendar syncing, no filters on delivery, no sending. Mail already downloaded is untouched. | **Reconnect this account** |
| **A calendar has stopped syncing** | That calendar shows what it knew when it last worked. Changes made elsewhere are not arriving, and changes you make here are not going out. | **Try syncing now** |
| **Instant delivery expired** | The push registration's own expiry passed and nothing renewed it, so nothing is being pushed at all. | **Re-register push** |
| **Instant delivery is registered but not arriving** | The registration is alive and unexpired, and mail has arrived that it never announced. The notifications are being lost on the way in. | **Re-register push** |
| **A connection needs reconnecting** | Saving attachments to a file service, and attaching from it, will not work. Your mail is unaffected. | **Reconnect** |
| **Background jobs were given up on** | Work that failed repeatedly and was set aside so it would stop retrying. Usually the aftermath of one of the others rather than a fault of its own. | **Put them back on the queue**, or **Discard them** |

Every repair says what it will do *before* you press it, and every one of them says what it will
leave alone. Pressing one changes the button while it works — a repair that looks inert is a repair
people press twice.

**Consequences are filed under their cause.** One dead sign-in can take three calendars and five
hundred queued jobs down with it. That is one thing to fix, not nine, so the calendars are listed
underneath the account that explains them and the count in the topbar counts causes rather than
symptoms.

**Severity is drawn by consequence, not by how alarming the error looks.** Instant delivery falling
back to polling is a notice and deliberately does *not* light the topbar indicator: your mail is
arriving. A page that paints "slightly delayed" the same red as "stopped" is a page people learn to
close, and then the red that mattered goes unread too.

The provider's own error text is kept, behind a **Technical detail** disclosure. It is there for
when you need it and out of the way when you do not.

## Reconnecting an account, in place

This is the one worth knowing about before you need it.

When a sign-in dies — a password changed, two-factor switched on, access revoked from the provider's
security page — the reflex is to delete the account and add it again. That works, and it costs you
everything: every message synced, every thread, every label, every rule that pointed at them.

**Reconnect this account** re-runs the provider's consent screen and writes the fresh sign-in onto
the account you already have. Mail, threads, labels, filters, aliases, calendars and settings all
stay exactly as they are. Nothing is downloaded again and nothing is deleted; syncing simply picks
up where it stopped and catches up over the next few minutes.

**It is guarded on identity.** You have to sign in as the same address at the same provider. Signing
in as a different Google account — the second one in the account chooser, which is a mistake anyone
makes — is **refused outright**, and nothing is changed. plMail tells you which address you actually
signed in as and which one it expected. Swapping the address in would file a stranger's mail into
these threads, with no way back.

## The two ways instant delivery breaks

Push can fail in two quite different ways, and the page names which one happened, because they send
you to two different places to look.

**Expired** means the registration's own expiry passed. That is read off a stored date rather than
inferred, and it is true at any hour. Renewal runs once a day on its own, so a registration that
expired is a renewal that did not run — and a scheduled-task worker that has simply stopped logs
nothing at all, so nothing else on the install would ever say so. If this keeps happening, the thing
to fix is the scheduler, not the account.

**Registered but not arriving** means the opposite: the registration is alive and unexpired, and mail
turned up that push never announced. That is evidence rather than a timer — a mailbox that changed
without a notification to explain it. The notifications are being lost between the provider and this
install. For Gmail that points at the Cloud Pub/Sub leg: the push subscription that forwards your
topic to this address may be missing, or pointing somewhere else. That part of the path is outside
plMail, which is exactly why it can fail while everything here looks correct.

A mailbox that never changes never produces that evidence and never raises this, at any hour of the
night.

Each card carries the three dates it was judged from — **Registration expires**, **Last notification
received** and **Renewal last ran** — so you can tell a scheduler that stopped from a delivery path
that never worked without reading a log. Any of them may read *never*, *nothing has ever arrived* or
*no run recorded*, and on a registration that never delivered anything that is the finding rather
than a gap.

Both are **warnings** and both light the topbar indicator. Push being deliberately **off** is a
different thing entirely and stays a quiet notice — see the traps below.

The repair is the same for both: **Re-register push**, which is safe to press more than once.

## Calendars that fail permanently

A calendar whose sync answers the same way every time now **backs off** instead of retrying forever:
a quarter of an hour, then doubling, capped at a day. Two consequences worth knowing:

- A broken calendar stops flooding the logs, and stops occupying the sweep that other calendars are
  queued behind. Before this, a calendar that could not sync was retried on every pass, for as long
  as it stayed broken.
- Nothing is muted. The first failure always reports, a failure that *changes* always reports, and
  because the delay is capped at a day, a condition that heals on its own is picked up again within
  one.

**Try syncing now** clears the backoff and asks the calendar to sync straight away. That dispatches
the work rather than doing it, so the card says the sync has been **started** and keeps saying so
until an answer comes back — through a reload, not just as a flash you might miss. When the answer
arrives the card says whether it worked, and a repeat failure says so plainly rather than looking
like a sync that never ran.

## Where to read further

- [Accounts and aliases](accounts.md) — connecting and disconnecting mailboxes, instant delivery.
- [Connected calendars](calendar-sync.md) — what a calendar sync does, and the providers it does it
  with.
- [Files and integrations](integrations.md) — the connections that can lapse.
- [Troubleshooting](../install/troubleshooting.md) — the operator's side: the queue, the logs, and
  the health checks a browser cannot see.
- [Administration](admin.md) — queues, workers and the monitoring an administrator gets.

## Things that bite

**Reconnecting refuses a different address, and that is the feature.** If the provider signs you in
as the wrong account — a second Google identity still logged in, a personal address where the work
one was meant — the repair stops and changes nothing. Sign out of that identity with the provider
first, then try again. There is no "use it anyway".

**A reconnect is refused across providers too.** The same address at a different provider is a
different mailbox, and the check compares both.

**"Try syncing now" queues the sync; it does not perform it.** The page comes back saying the sync
has started, because it has, and the result lands moments later. Pressing it again in the meantime
does nothing useful — the card will say what happened without being asked twice.

**Instant delivery being off is not an error, and will not light the indicator.** A self-hosted
install with no publicly reachable HTTPS address can never register push at all. Mail arrives on the
fifteen-minute sweep, which is why this is a notice rather than a warning. Push that is *broken* —
expired, or registered and not delivering — is a warning and does light it, because you asked for
instant delivery and are not getting it.

**A broken push does not stop your mail.** Both push failures fall back to the scheduled check, so
mail keeps arriving a few minutes later than it should rather than not at all. There is no rush, and
nothing is lost while you work out which leg is broken.

**"Not arriving" is only ever reported from evidence.** plMail waits for a mailbox to change without
a notification announcing it, rather than for a stretch of silence to elapse. A genuinely quiet
mailbox therefore raises nothing however long it stays quiet — an absence of warnings is not proof
push is working, only that nothing has yet proved it is not.

**Account health leaves the settings list when nothing is wrong.** Looking for the menu item on a
good day and not finding it is the intended behaviour, not a missing page. The URL and the topbar
indicator both still reach it.

**Discarding abandoned jobs cannot be undone.** Anything they had not finished stays unfinished, and
nothing is retried. **Put them back on the queue** is the safe one — jobs that fail again simply end
up back here.

**Abandoned jobs are usually a symptom.** Hundreds of them almost always mean one dead sign-in
higher up the page. Fix that first and put the jobs back afterwards, or they will fail for the same
reason and return.

**The page reports what was last observed, not what is true this second.** It reads stored state and
never probes, so an account that has just been fixed elsewhere stays on the list until something
tries it again.
