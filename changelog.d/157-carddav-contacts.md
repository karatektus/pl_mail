<!-- highlight -->
**Contacts: an address book, and your Nextcloud or CardDAV contacts in it.** A new **Contacts** page
(in the sidebar) lists, searches and tags your contacts, shows each one's details and the mail you
exchanged, and creates, edits and deletes them, with a photo, postal addresses and notes. The button
beside the calendar's docks the whole address book beside the mail, resizable like the calendar,
and on a wide monitor the two sit side by side. Duplicates are found and merged, with 90 days to undo, and contacts import from
and export to vCard and CSV. A message's sender shows whether they are in your contacts, with one
click to add them. Address books on a server come in too: a Nextcloud connected under Settings →
Integrations is listed under **Settings → Contacts** with no second connection to make, and
Radicale, Baïkal, Fastmail or iCloud connect there over CardDAV. Their contacts sit beside plMail's
own, a filter shows one book at a time, and every change made here is sent back to the server. A
photo, a birthday or anything else plMail has no field for stays on the card. Changes made elsewhere
arrive within fifteen minutes (`app:contacts:sync --stale`). Contacts are suggested when you address
a message, and over JMAP (`Contact/get`, `Contact/query`, `Contact/set`) for the apps. See
[Contacts](docs/features/contacts.md) and [CardDAV](docs/providers/carddav.md).
[#157](https://github.com/karatektus/pl_mail/issues/157), by @jo2904 and @DanversKara.

**Migration:** adds the `address_book` and `contacts_*` tables; nothing existing changes.
