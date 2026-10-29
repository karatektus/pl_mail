**An email your IMAP server keeps in several folders is one message.** Proton Bridge's All Mail,
Gmail's label folders and copies another client made were each stored as a message of their own,
so a conversation could show every message twice and a second folder of that kind was downloaded
in full again. plMail now records the folders a message is kept in: a copy of mail it already has
is recognised from the server's index and not downloaded, read and starred reach every copy, and
removing a copy from one folder no longer loses the email. Messages stored twice before this are
folded into one over the next syncs, keeping their labels and stars.
**Migration:** four new tables-or-columns (`message_location`, `message.rfc822_size`,
`message.server_email_id`, `purged_copy`); the migration fills `message_location` from the existing
messages in one statement, which takes a moment on a large install.
[#415](https://github.com/karatektus/pl_mail/issues/415), [#414](https://github.com/karatektus/pl_mail/issues/414).
