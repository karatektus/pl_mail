**IMAP IDLE no longer costs a process per folder.** Every folder with IDLE on (the Inbox and Junk of
each IMAP account) used to get a background process of its own, about 40 MB each, so fifteen
accounts came to more than a gigabyte just to wait for mail. All of them now share one process,
`app:imap:listen`, whose memory does not grow with the number of folders; the IMAP supervisor starts
it in the worker container as before. It runs on plMail's own IMAP client, which waits on every
server at once, so a server that is slow to answer holds up only its own folders. It also stops in
a few seconds on shutdown, where the per-folder processes could outlast the worker's grace period
and be killed. No schema change; nothing to do on upgrade.
[#421](https://github.com/karatektus/pl_mail/issues/421).
