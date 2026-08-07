# Mail

Everything that happens between a message arriving and you being done with it: reading it,
finding it later, putting it away, and writing back.

![The plMail inbox](../screenshots/inbox.png)

## The sidebar, and what the lists mean

The left-hand rail is organised around labels rather than folders, because one label spans every
account you have connected. Clicking **Inbox** shows the inbox of all of them at once; clicking a
label you made shows everything carrying it, wherever it arrived.

| Entry | What it lists |
|---|---|
| **Inbox** | Everything still in the inbox, across every account, newest conversation first |
| **Starred** | Conversations with at least one starred message |
| **Snoozed** | Conversations waiting for their wake time |
| **Sent**, **Drafts**, **Trash** | The corresponding system label, across accounts |
| **Archive** | Hidden by default — switch the Archive label visible in **Settings → Labels** to get it |
| **Accounts** | One row per account; clicking it lists that account alone |

Under **Accounts**, expanding an account shows the labels that actually exist on it. That list is
narrower than the sidebar's own label list on purpose: the sidebar means "across every account",
and the per-account list answers "what does this mailbox actually have". Which account you left
expanded is remembered on the server rather than in the browser, so the sidebar renders already
expanded instead of blinking open after the page has drawn.

Every list pages at fifty conversations. **Newer** and **Older** in the toolbar move between pages.

## Tabs

The inbox is split into the five Gmail categories — **Primary**, **Social**, **Promotions**,
**Updates** and **Forums** — with an unread count on each. For a Gmail account plMail trusts
Gmail's own `CATEGORY_*` labels. For everything else it works the category out from headers that
were already stored, which is why re-categorisation never needs a resync.

There is one override worth knowing: a sender you have written to yourself is pulled back into
Primary regardless of any bulk-mail header on the message. Open a message's **Details** panel and
the `category` row says which rule decided, and which header or domain it matched on.

## Threads

Replies collapse into one conversation, ordered oldest to newest with the latest message
expanded. Each message has its own menu: **Star**, **Archive**, **Mark as unread**,
**Delete this message**, **Print**, and **Show original**.

**Show original** reconstructs the message from the stored header map and the decoded body — no
raw RFC 822 blob is kept — and prints the SPF, DKIM and DMARC verdicts alongside it where the
provider recorded an `Authentication-Results` header. Both it and **Print** open in their own tab.

HTML bodies are rendered inline rather than in an iframe. Before that happens the body is put
through a sanitiser once, at ingest: `cid:` references are rewritten to plMail's own attachment
route, `<style>` blocks are flattened onto the elements they applied to, and scripts, forms,
iframes and classes are dropped. Links are forced to open away from the app.

## Labels

Labels are yours to create, from **Create label** in the sidebar or **Settings → Labels**. A label
has a name, one of nine colours — gray, red, orange, amber, green, teal, blue, violet or pink —
and optionally a parent, which is how nesting works. A label can be hidden from the sidebar
without being deleted.

Creating a label does nothing at the provider. Gmail gets it the first time the label is actually
applied to a message; plain IMAP never gets a folder created for it, because a folder only matters
when a message physically moves. Mirroring structural changes — renames and deletions — outward is
off unless you switch it on per account; see [Accounts and aliases](accounts.md).

Deleting a label removes it from **every** account, and takes any nested labels with it. Messages
are not deleted; they keep their other labels.

## Search

The search box is at the top of every page and answers at `/mail/search`. Free text runs as a real
full-text query against Postgres — stemmed and ranked, not a substring scan — and combines with
any operators you type.

| Operator | Takes | Matches |
|---|---|---|
| `from:` | a name or address | sender address or display name |
| `to:` | an address | the To list |
| `cc:` | an address | the Cc list |
| `subject:` | words | the subject line |
| `label:` | a label name | a label you made |
| `has:attachment` | — | also accepts `has:attachments` |
| `is:unread`, `is:read`, `is:starred` | — | read state and stars |
| `in:` | a mailbox name | see below |
| `after:` | a date | received on or after |
| `before:` | a date | received before |

`in:` accepts `inbox`, `sent`, `drafts` (or `draft`), `trash` (or `deleted`, or `bin`), `junk` (or
`spam`), `archive` (or `archived`) and `snoozed`. Quoted values are kept together, so
`from:"Ada Lovelace"` works.

Suggestions appear as you type. Operators are completed from the list above; `from:`, `to:` and
`cc:` complete against your own harvested contacts, because remembering how a sender spells their
name is usually the reason you opened the search box. Enter takes the highlighted suggestion when
the list is open and submits otherwise, Tab takes it, arrow keys move through it, and the first
Escape dismisses the list while a second clears the box. The last eight searches are kept in the
browser and offered when the box is focused and empty.

An operator plMail cannot honour — `is:important`, `in:nowhere`, a date that is not a date —
becomes plain text rather than being dropped. That is deliberate: a dropped filter is a filter you
asked for and did not get, and the result would be a page of everything that reads as though the
search had been ignored. As free text it finds little or nothing, which is at least the truth.

A query that is nothing but a half-typed operator — `from:` with no value — returns an empty page
rather than the whole mailbox.

Results are **Most recent** first. The switch beside the pagination changes that to **Most
relevant**, which is full-text rank — the best match leads, whenever it arrived. Whichever you pick
is remembered for your next search, and paging keeps it. Switching orders starts again at the first
page, because page four of one order is page four of nothing in the other.

Search only covers mail that has been synced. How far back that goes is a per-account setting; see
[Accounts and aliases](accounts.md).

## Acting on mail

Row buttons and the toolbar do the same things, the toolbar over everything selected. **Select
all** has a menu beside it offering **All**, **None**, **Read**, **Unread** and **Starred**.

Star, **Archive**, **Delete**, **Label as**, **Mark as read** / **Mark as unread** and **Snooze**
all apply to a whole conversation or to a single message, and all of them travel outward to the
provider as well as changing what you see here. Archiving is modelled as removing the Inbox label
and nothing else — where the message lands afterwards is the provider's business.

**Refresh** in the toolbar queues a sync for every active account you own and spins until those
jobs have drained.

## Snooze

Snoozing is archiving with a timer. The conversation leaves the Inbox now — at the provider too,
not only in plMail's view of it — gains the Snoozed label, and comes back when its time is up.

The menu offers **Later today**, **Tomorrow**, **This weekend**, **Next week**, **Pick a date and
time**, and **Unsnooze** on something already snoozed. Later today is 18:00 and is offered only
while that is still ahead; the other three land at 08:00 on the next day, the coming Saturday and
the coming Monday. All four are computed in your browser, because the server never sees a timezone
for the session and would otherwise resolve "tomorrow morning" to wherever the container thinks it
is.

`app:mail:wake-snoozed` runs every minute and is what brings conversations back. A woken
conversation is marked **unread** — that is the point of the feature, since a thread returning in
the state you left it in is one you have already learned to scroll past. The read state it had is
genuinely lost.

The Snoozed list is ordered by the conversation's last message, like every other list, rather than
by wake time.

## Attachments

Attachments show as chips under the message, with a preview for images. Clicking one downloads it;
only images are ever served inline, so email-supplied HTML can never run on plMail's own origin.

**Save to** on an attachment pushes it out to a connected service — see
[Files and integrations](integrations.md). This works for Gmail and Microsoft messages whose
attachment has never touched plMail's disk; it is materialised on first access.

## Composing

**Compose** opens a window docked at the bottom right. Replying from inside a thread opens the
editor at the foot of the conversation instead, on a wide screen; below that it falls back to the
dock. Either way the window offers rich text, contact autocomplete on the address fields, and a
**From** selector listing every active account and every sending alias on it.

**Attach files** takes files from your machine, capped at **25 MB per file**. **Attach from a
service** opens the file picker for any connected service plMail can download from.

Drafts save themselves two seconds after you stop typing, and a draft is only created once the body
has at least five characters — otherwise every stray keystroke minted one. Closing the window,
popping it out, or attaching a file all force a save first. The trash button in the compose window
genuinely deletes the draft rather than closing the window on top of it.

## Undo send

Pressing send queues the message with a **ten-second** delay and answers with a **Sending…** toast
carrying an **Undo** button. An inline reply skips the toast: the message is appended to the thread
straight away and the reply bar becomes a countdown you can click to cancel.

Undo does not race the mail out of the door — it sets a flag the send job checks when it wakes, so
cancelling is decided before anything is transmitted. The message goes back to being the draft it
was, with the editor reopened where it was.

## Where to read further

- [Mail ingest](../internals/mail-ingest.md) — the pipeline from provider to database, threading and
  categorisation.
- [Accounts and aliases](accounts.md) — sync windows, instant delivery, sending addresses.
- [Filters](filters.md) — sorting mail as it arrives, and applying a rule to mail you already have.
- [JMAP](../internals/jmap.md) — the same operations as a client sees them.

## Things that bite

**The Undo button disappears two seconds before the message does.** The send job is held for ten
seconds, but the toast carrying Undo fades after eight. Nothing is wrong when a message goes out
after the button has gone — the window really did close.

**Waking a snoozed conversation marks it unread, and the old read state is gone.** This is chosen,
not accidental, but it does mean snoozing a thread you had read leaves you with an unread thread
afterwards.

**A snooze time in the past is accepted.** It means the next sweep, a minute later, brings
the conversation straight back. That is a harmless way to say "bring this back now", not an error.

**Deleting a label deletes it everywhere.** The sidebar's label list is cross-account, so the
delete is too — including nested labels underneath it. A JMAP client's `Mailbox/set` destroy is the
per-account operation; the web UI has no equivalent.

**Search finds nothing older than your sync window.** Mail that has not been synced is not
searchable, however certain you are that it exists. Widen the window in
**Settings → Mail accounts** and let the next run walk further back.

**An oversized attachment can fail with no per-file reason.** PHP discards the whole request body
when it exceeds `post_max_size`, so nothing arrives to report an error about. plMail answers
"Upload too large" for the whole upload rather than staying silent, but it cannot tell you which
file caused it.

**Marking a message read in another client does not come back.** Flag changes travel outward only;
incoming IMAP flag sync over the IDLE stream is not implemented.
