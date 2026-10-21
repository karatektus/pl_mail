**Contacts from a CardDAV server.** Connect Radicale, Baïkal, Fastmail or iCloud under
**Settings → Contacts** — a Nextcloud already connected under Settings → Integrations is listed
there with no second connection to make — tick the address books to mirror, and their contacts are
suggested when you address a message — ranked after the people you actually write to, before
addresses you only ever typed once. A new **Contacts** page (in the user menu) lists, searches,
creates, edits and deletes them, and every change is sent back to the server; a photo, a birthday
or anything else plMail has no field for is kept on the card. Changes made elsewhere arrive within
fifteen minutes (`app:contacts:sync --stale`, run by the scheduler). JMAP `Contact/autocomplete`
returns the same list, with a new `inAddressBook` flag. **Migration:** adds the `address_book` and
`contact_card` tables; nothing existing changes. See [CardDAV](docs/providers/carddav.md).
[#157](https://github.com/karatektus/pl_mail/issues/157), by @jo2904.
