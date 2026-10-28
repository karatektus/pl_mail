**CardDAV address books on go-webdav servers sync their contacts.** Without sync-collection,
plMail lists an address book with an addressbook-query, and that query had no filter, which
RFC 6352 requires. Servers built on go-webdav, such as Meerkat CRM, answered it with no cards, so
the mirrored book stayed empty. The query now carries a filter that every card passes.
[#350](https://github.com/karatektus/pl_mail/issues/350).
