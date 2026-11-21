**An attachment named only in its Content-Type keeps its name.** Some mailers give a file's name
only as `Content-Type: …; name=` and send a bare `Content-Disposition: attachment`; plMail stored
such an attachment under an eight-character hash, so it downloaded without an extension and a PDF
had no preview. The name is now taken from `Content-Type` when the disposition has none, and
`app:backfill attachment-content-type-names` (also run as an upgrade task) renames the attachments
already stored, from each message's stored original. No schema change.
[#718](https://github.com/karatektus/pl_mail/issues/718), by @james-e-morrison (written with Claude).
