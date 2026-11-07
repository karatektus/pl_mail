**A "Mark as spam" filter puts the mail in Spam, and in the server's Junk folder.** It labelled
the mail Spam in plMail but archived it on the server, so after the next sync every message the
filter had caught sat in the Archive, unread. Mail it already put there is moved to Spam with
`php bin/console app:backfill spam-in-archive`.
