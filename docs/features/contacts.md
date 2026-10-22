# Contacts

plMail keeps an address book: the people you write to, with their addresses, phone numbers, postal
addresses, birthdays, a photo and notes. It is one list, wherever a contact lives. Some are plMail's own and
exist only here. Others come from an address book on a server, and changes go both ways:

- a **Google** or **Microsoft** mail account brings its contacts with it, on the same sign-in as its
  mail and calendars — nothing to connect (see [Google](../providers/google.md) and
  [Microsoft](../providers/microsoft.md));
- your [Nextcloud](../providers/carddav.md#connecting-a-server), or any other CardDAV server, is
  connected under **Settings → Contacts**.

An account connected before plMail synced contacts needs one more sign-in to allow them: **Settings
→ Contacts** and **Settings → Account health** both offer it.

Open it with the address-book icon beside the calendar button. Like the calendar button it is a
three-way switch: the address book beside the mail, then full width with your mail behind it, then
closed again. **Settings → Contacts → Behaviour** can turn it into a two-way one (**Full width
only** or **Beside mail only**), as for the calendar. On a phone, **Contacts** in the menu opens the
full page.

The docked address book is the whole of it: search, open, edit, add and merge contacts there while
your mail stays where it was. Drag its edge to make it wider or narrower (or use the arrow keys on
the edge; a double-click puts it back). Drag it past its widest and let go, and it goes full width;
past its narrowest, and it closes. plMail remembers the width and the position. The arrows in its
header make it full width, and the × closes it. Opening a message from a contact at full width
brings the mail back beside it.

On a wide monitor (1920 pixels across or more) the address book and the calendar can be docked side
by side. On a smaller screen, such as a laptop's, there is room for one of them beside the mail, so
opening one puts the other away. Below 1024 pixels the icon opens the full page.

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
message to or from the contact that has not been deleted, in any folder. Clicking one opens it in
the mail beside the contact, which stays as it was; on the full page the mail slides in from the
left.

**New contact** opens **quick add**: one box to type or paste anything about the person into — a
name, an email signature, a line like `Mia Hoffmann <mia@example.net>`, an address, or a whole
vCard. It is read as you type, and what it finds appears below as rows: each can be unticked,
retyped, or given another type with one click (a work number that is a home number, a line taken
for the job title that is really the company). A line that could not be placed is asked about
rather than guessed, a greeting above a signature is left out, and an address that is already on
one of your contacts is pointed out. A vCard is read field by field and nothing is guessed. **All
fields** opens the full editor with everything found already filled in.

The **editor** shows the name as one field; its parts (prefix, first, middle and last name,
suffix) fill themselves as you type and open under **Edit parts**. Once you edit a part by hand it
stays, and the page says when the name and its parts no longer match. Email and phone end in a
dashed row — type into it and it becomes a row, typed as home or work from the address, or as a
mobile from the number, until you pick a type yourself. The star marks the preferred one. Every
other kind of detail shows only when the contact has one; the chips under **Add** bring the rest.
Once something has changed, a bar at the bottom says how much and offers **Save** and **Discard**.

**Paste details** in the editor reads pasted text the same way and compares it with the contact
before anything changes: what is already there is greyed out, what is new is ticked, and what
differs from what is saved ("Replaces …", or a different type for the same number) is left for you
to tick. **Apply** puts the ticked details into the form and highlights them; nothing is saved
until you save.

When your administrator has switched on **Contact help** (Admin → AI), both pages have **Improve
with AI**. On quick add it gives the text a second reading — the company the rules could not tell
from a slogan, a fax number written as `Fax -99` — and marks what it changed, with **Undo AI
changes** to go back. In the editor it sends the contact and the signature of the newest mail the
contact sent you, and shows its suggestions, each with its reason, in the same list as pasted
details. You can switch it off for yourself under Settings → Assistant.

A contact holds:

- its name in parts (prefix, first, middle and last name, suffix), a nickname, and how the names are
  spoken (**Phonetic spelling**, for names a phone should sort or read out differently);
- company, department, job title and office;
- email addresses, phone numbers and postal addresses, each labelled and one of each marked primary;
- a birthday and other dates (an anniversary, say) — the year can be left out;
- **People**: the names of a spouse, children, a manager, an assistant and so on;
- websites, chat addresses (SIP, XMPP, Skype, Teams, Matrix and others), and **custom fields** of
  your own naming;
- notes, tags and a photo.

A label that came from the server and is not one of plMail's own (a "Godmother" typed on a phone)
is shown as it is and kept when you save.
If you have a synced address book you can choose where a new contact goes under **Save to**: one
button per address book when you have up to four, a menu when you have more. **plMail** keeps it in
plMail only. Otherwise it goes to that address book's server and from there to every device that
syncs it; the line under the buttons says which. A read-only address book is never offered.

Editing works the same for both kinds. For a synced contact, the edit is saved here at once and sent
to the server straight after, and from there to your phone. Anything plMail has no field for, such
as the properties a phone uses to link a contact to an app, stays on the server's copy untouched.
Google and Outlook have no place for some fields; see **Things that bite**. See [CardDAV](../providers/carddav.md#sync) for how
edits and conflicts are handled.

A contact from a **read-only** address book (a shared book, a company directory) can be read and
written to, but not changed or deleted here. Its page says where it comes from.

**Photos** are JPG, PNG or WebP up to 5 MB, and kept at up to 512 pixels on the longer side. They go
both ways: a photo on the server is shown here, and a photo uploaded or removed here is sent to the
server. In the editor, the camera on the photo uploads one and **Remove photo** at the bottom removes
it.

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

**Export** downloads every contact as one `.vcf` file, photos included. A synced contact is exported
as its server's card with your edits in it, so the file keeps what plMail has no field for. The CSV
export is a summary for a spreadsheet — one email, one phone and one website per contact — and reads
back in; use vCard to move contacts whole.

## While reading and writing mail

A message's sender, and every address in its details (To, Cc, Reply-To), shows whether it is already
in your contacts, with a button to add it if not. The button asks who it is:

- **New contact**: check the name taken from the mail and save. *More details* opens the full
  editor with the name and address filled in.
- **Add to an existing contact**: search your contacts as you type and pick one. The address is
  added to that contact, so somebody writing from a second address stays one person. The search
  starts with the sender's name. Contacts in read-only address books are not offered.

Once saved, the contact opens in the contacts pane beside the mail. If the pane was closed it opens,
and if it was open it goes to that contact. *More details* opens the editor there too. On a phone
there is no pane: *More details* opens the editor as a page, and after a save the button turns into
a link to the contact.

When you address a message, your contacts are suggested along with the addresses plMail has
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

**Google and Outlook cannot hold everything.** Google keeps every field above. Outlook has fixed
places: three email addresses, two business and two home numbers and a mobile, one address of each
kind, one spouse, manager and assistant and any number of children, one website, three chat
addresses and a birthday. It has no other dates, no phonetic middle name and no custom fields. What
does not fit is not sent and stays only in plMail until the contact next changes in Outlook (see
[Microsoft](../providers/microsoft.md#contacts-are-on-the-same-sign-in-too)).

**Outlook keeps a photo removed here.** Microsoft's interface can replace an Outlook contact's photo
but not remove it, so a photo removed in plMail comes back the next time the contact changes in
Outlook. Remove it in Outlook.

**Custom fields and the office are plMail's own on a CardDAV server.** vCard has no property for
either, so they are stored on the card under plMail's own names (`X-PLMAIL-FIELD`,
`X-PLMAIL-OFFICE`). They survive the round trip, but a phone does not show them.
