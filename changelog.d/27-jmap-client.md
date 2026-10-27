<!-- highlight -->
**Connect a JMAP account — Stalwart, Fastmail, Cyrus — and get its mail, calendars and contacts
from one login.** Choose **JMAP** under **Incoming protocol** when adding an account, and give the
server's host name and an app password, or a Fastmail API token. Mailboxes are labels, as in Gmail,
and what you read, star, file, archive or delete here is written back to the server; mail is
imported Inbox first. Sending goes through the server, which files the copy in Sent. The account's
calendars and address books are offered under **Settings → Calendars** and **Settings → Contacts**
like a Google or Microsoft account's, and changes on the server arrive within seconds over push. An
IMAP account on a server that speaks both can be switched to JMAP on its edit form, keeping its
mail. plMail is a JMAP server itself, so one plMail can hold another's mail and calendars as an
account, with an app password from the other one; its calendars are now offered to any JMAP client
in the standard draft's form. See [JMAP](docs/providers/jmap.md).
[#27](https://github.com/karatektus/pl_mail/issues/27).

**Migration:** adds `message.jmap_id` and `label_binding.jmap_mailbox_id`, both empty on every
existing row. A JMAP server on a private network is reached only once its host is in
`INTEGRATIONS_ALLOWED_HOSTS`, as a LAN CalDAV server is. JMAP push runs inside the existing IMAP
supervisor container as `app:jmap:listen`, so no container is added; with a public HTTPS
`APP_PUBLIC_URL`, the server is also asked to call plMail's webhook at `/webhook/jmap/`, which a
reverse proxy has to pass through like the Graph and Gmail ones.
