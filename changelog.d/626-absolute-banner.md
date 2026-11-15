**A mail whose header banner is absolutely positioned no longer covers its own text.** Mail laid
out like a print page, such as the notices some insurers send through Scriptura, puts the banner in
a `position: absolute` box, so the text started at the top of the message and ran underneath the
picture. The reading pane now drops `position: absolute` as the composer already did, which is also
what Gmail does. Mail already in the mailbox is repaired on request:
`php bin/console app:backfill safe-html-absolute-position` writes the shown copy of every message
that kept it again from the sender's original. No schema change.
[#626](https://github.com/karatektus/pl_mail/issues/626), by @phdelodder.
