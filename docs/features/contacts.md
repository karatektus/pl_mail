# Contacts

plMail keeps an address book: the people you write to, with their addresses, phone numbers, postal
addresses, a photo and notes. It is one list, wherever a contact lives. Some are plMail's own and
exist only here. Others come from an address book on a server, such as your
[Nextcloud](../providers/carddav.md#connecting-a-server) or any other CardDAV server, and changes go
both ways.

Open it from **Contacts** in the sidebar, or the address-book icon beside the calendar button.

## The list

Every contact as a card: photo or initials, name, job and company, and the main email address.
Search finds a contact by any of its names, its company or any of its addresses. Tags (you set them
on a contact) appear as chips above the list and narrow it to one tag.

Once you have a synced address book, a second row of chips chooses where to look: **All books**,
**Not synced** (plMail's own contacts), or one address book. A synced contact carries a small
cloud, or a lock if its address book is read-only. A clock means an edit made here is waiting to be
sent to the server.

## A contact

The contact's page shows its details, its notes, related contacts, and **Reference emails**: every
message to or from the contact that has not been deleted, in any folder.

**New contact** asks for a name; everything else is optional. If you have a synced address book
you can choose where the contact goes under **Save to**. **Not synced** keeps it in plMail only.
Otherwise it goes to that address book's server and from there to every device that syncs it.

Editing works the same for both kinds. For a synced contact, the edit is saved here at once and sent
to the server straight after. Anything plMail has no field for, such as a birthday or a phone's own
fields, stays on the server's copy untouched. See [CardDAV](../providers/carddav.md#sync) for how
edits and conflicts are handled.

A contact from a **read-only** address book (a shared book, a company directory) can be read and
written to, but not changed or deleted here. Its page says where it comes from.

**Photos** are JPG, PNG or WebP up to 5 MB. A photo on a synced contact's card is shown here. A
photo you upload here stays here and is not sent to the server.

## Deleting

**Delete** moves a contact to the bin. Deleting a synced contact also deletes its card on the
server, and the contact stays in the bin as one of plMail's own.

## Duplicates

**Duplicates** lists pairs that look like the same person, with how sure the match is and why
(the same email address, the same phone number, a similar name). Pick the one to keep and
**Merge**. The other one's addresses, numbers and tags move to the kept contact, and its notes are
added below the kept contact's. A merge can be undone for 90 days.

Synced contacts merge like any other. The kept one's card is updated, and the merged one's card is
deleted from its server, so an undo brings the merged contact back as one of plMail's own. Contacts
from a read-only address book cannot be merged.

## Import and export

**Import** takes a `.vcf` (vCard, as every phone and address book exports) or a `.csv` file. You see
a preview first. Imported contacts are plMail's own, and you land on the duplicates page afterwards
to merge anything you already had.

**Export** downloads every contact as one `.vcf` file. A synced contact is exported as its server's
card with your edits in it, so the file keeps what plMail has no field for.

## While reading and writing mail

A message's sender shows whether they are already in your contacts, with a button to add them if
not. When you address a message, your contacts are suggested along with the addresses plMail has
learned from your mail. The order is explained under
[suggestions while writing](../providers/carddav.md#suggestions-while-writing).

## On a phone

The plMail apps read and edit the same contacts over JMAP (`Contact/get`, `Contact/query`,
`Contact/set`). Each contact says which address book it is in and whether it can be changed. An app
cannot change a contact from a read-only book, and its edits to a synced contact go on to the
server like edits made on the web.

## Things that bite

**The bin has no page yet.** A deleted contact is kept, but nothing on the web brings it back. Merge
instead of deleting when you are tidying up duplicates: a merge can be undone.

**An import makes plMail's own contacts.** To put a `.vcf` file into a synced address book, import
it on the server (in Nextcloud, under Contacts), and the contacts arrive here with the next sync.

**A contact cannot move between address books**, or from plMail's own into a synced one. Create it
in the other place and delete the old one.

**A photo uploaded here is not sent to the server**, so your phone does not show it.
