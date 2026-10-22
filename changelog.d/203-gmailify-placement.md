**Mail trashed or archived in Gmail no longer leaves the Inbox of the account Gmail collected it
for.** When a Gmail account collects mail for another connected account (Gmailify), plMail merges
the Gmail copy into that account's own IMAP copy, and the merge applied Gmail's `TRASH`, `SPAM`,
`INBOX`, `SENT` and `DRAFT` too. Mail still in the IMAP server's Inbox showed as trashed or
archived and dropped out of the Inbox list. The IMAP folder now decides where such a message sits;
Gmail's other labels still merge. Mail that Gmail deleted for good, by emptying its bin, was also
removed from the other account here, although its IMAP server still had it, and the IMAP sync
never fetched it again. That copy now only loses its link to Gmail. Mail already moved this way is
put back with `app:backfill gmailify-placement`; mail already removed is not brought back. No
schema change. See [Accounts](docs/features/accounts.md#gmail-collecting-mail-for-another-account-gmailify).
[#203](https://github.com/karatektus/pl_mail/issues/203), by @phdelodder.
