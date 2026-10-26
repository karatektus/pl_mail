# CardDAV

CardDAV is the standard protocol for address books on a server, and plMail speaks it to any server
that implements RFC 6352: Nextcloud, Radicale, Baïkal, Fastmail, iCloud. It is usually the same
server as [CalDAV](caldav.md), on the same app password. A user connects one from their own
settings; there is no administrator step.

A mirrored address book's contacts join your [contacts](../features/contacts.md), alongside
plMail's own. They are listed, searched, edited, merged and deleted there like any other, and every
change made there is written back to the server. They are also suggested when you address a
message.

## Connecting a server

**Settings → Contacts → Connect a CardDAV server.** The same form as for calendars, with the same
four fields in the same order — server address, name, username, app password — and the same
deliberately-off option to reuse a mail account's stored password. See
[Connecting a server](caldav.md#connecting-a-server) on the CalDAV page for what each one wants;
everything there applies here.

**Nextcloud needs no CardDAV connection.** A Nextcloud connected for files under **Settings →
Integrations** is listed in Settings → Contacts too, with its own **Choose address books** button,
on the same app password. It is marked *Set up in Integrations* rather than offering a disconnect
button, because the connection also holds your files and calendars; disconnecting it there removes
its address books along with them, and the confirmation says how many. Its address books are
looked up under `/remote.php/dav`, as for [its calendars](caldav.md#nextcloud-no-second-connection).
A CardDAV connection pointed at a Nextcloud by hand keeps working, and its contacts app shows a
per-book address under **Contacts → Settings** if you want to point at one book only. An address
book already mirrored through one of the two is shown as *Already mirrored through …* on the
other's list and cannot be ticked there, so its contacts are not listed twice.

Saving connects immediately and then lists the address books the server offers, with tick boxes.
Ticked books are mirrored; their first sync is queued at once, so the contacts appear within a
minute rather than at the next sweep.

**For any other server it is a separate connection from CalDAV**, even on the same server. Some
servers carry only one of the two, and a person may keep their contacts and their calendars in
different places. Connecting a Radicale or a Baïkal for both means entering the app password twice.

## Discovery

Exactly the CalDAV discovery with the address-book names: the pasted address first, then
`/.well-known/carddav`, then the principal's `addressbook-home-set`, then the home is listed. A
pasted calendar URL is not taken for an address book, and the same server's calendars are never
offered in the address-book list. See
[Discovery, and the `.well-known` dance](caldav.md#discovery-and-the-well-known-dance).

The same [refused addresses](caldav.md#addresses-plmail-will-refuse) apply: a server on your LAN
needs its host in `INTEGRATIONS_ALLOWED_HOSTS`, and plain `http://` needs `INTEGRATIONS_ALLOW_HTTP`.

## Sync

**Polling only.** CardDAV has no push, so mirrored books are read by `app:contacts:sync --stale`
every fifteen minutes (at :11, :26, :41 and :56, offset from the mail and calendar sweeps that share
the worker). A change made on a phone appears here within a quarter of an hour; **Settings →
Contacts** has a sync-now button per book.

Changes are read the way calendars are: `sync-collection` (RFC 6578) where the server advertises it,
otherwise `getctag` plus a full listing when the ctag moves. Only a full listing deletes local cards
it did not mention; a delta never does.

**Edits made here are saved at once and sent afterwards.** The contact is marked pending (the
Contacts list shows a small clock on it), and a couple of seconds later the worker sends it on the
`export` queue, with the mail leaving plMail. Sending does not read the server: changes made
elsewhere arrive with the next sync. If the server can't be reached, the edit stays pending and
that sync sends it first. Each sync sends pending edits *before* reading the server, so the
server's older copy never overwrites an edit on its way out.

**Every write is conditional.** A new contact is `PUT` with `If-None-Match: *` under a name derived
from its UID; an edit or a delete carries `If-Match` with the etag last seen. If the card changed on
the server in the meantime, the server's version wins: the address book is read again and the edit
made here is dropped, with a warning in the log naming the contact. Merging two versions of a vCard
field by field is a feature of its own, and getting it wrong corrupts an address book.

A card the server refuses outright (`403`, usually from a book that did not say it was read-only) is
handled the same way: the server's copy is kept and the refused edit is logged, so one card cannot
stop the rest of the book from syncing. Any other failure — the server unreachable, out of space, or
rejecting the password — keeps the edit pending, and it is sent again on a later sync.

**What plMail does not show, it does not touch.** A contact here has every field listed under
[Contacts](../features/contacts.md#a-contact), and a card can carry more, such as the properties a
phone uses to link a contact to an app. All of it is kept. plMail keeps the vCard the server sent
and applies an edit to it, changing only the properties that were edited. It does not rebuild the
card from its own fields. An address or a number that is still on the card keeps its type (work,
home), its grouping and the server's own formatting.

The fields are read in every spelling the common clients write — Apple's grouped `X-ABDATE`,
`X-ABRELATEDNAMES` and `X-ABLabel`, vCard 4's `ANNIVERSARY` and `RELATED`, `IMPP` and the older
`X-JABBER` family, Apple's 1604 for a birthday without a year — and a list changed here is written
the way most of them read it. The photo goes both ways: one uploaded or removed here replaces the
card's, and one that was not changed here stays on the card exactly as it was.

**Deleting a contact deletes its card.** A contact moved to the bin here is deleted on the server at
the next sync, and stays in the bin as one of plMail's own. Merging
duplicates updates the kept contact's card and deletes the merged ones' cards.

Read-only books, such as a shared book or a server's global address list, are mirrored and
suggested, and cannot be edited, deleted or merged here. Whether a book accepts writes is asked of
the server (`current-user-privilege-set`), not assumed.

## Suggestions while writing

The composer and JMAP `Contact/autocomplete` read one ranked list, in this order:

1. how often the address has been seen in your mail;
2. then whether it is in your contacts (plMail's own or a synced book);
3. then how recently it was seen, then the name.

So an address you write to often still comes first, and a contact you have never mailed comes before
an address you once typed into a draft. An address in both places is suggested once, under the name
from your contacts. Typing part of a name offers every address of that contact; typing part of an
address offers only that address.

## Things that bite

**The calendar and contacts connections are separate.** Connecting Nextcloud under Calendars does
not bring its contacts in; connect it again under Contacts.

**An edit made here can be lost to an edit made elsewhere.** If the same contact was changed on a
phone between two syncs, the phone's version wins and the change made here is dropped (and logged).
The window is the time between saving and the worker's next run, usually seconds.

**Unticking an address book, or disconnecting the server, discards edits not yet sent.** The copies
here go, along with any photo uploaded to them here; the server keeps its own. If a contact shows
the pending clock, wait for it to clear first.

**A LAN server is refused by default**, as for CalDAV. Add the host to `INTEGRATIONS_ALLOWED_HOSTS`.

**Moving a contact between address books is not offered.** On the server it is a delete in one
collection and a create in another; do it in the server's own interface.
