**IMAP runs on plMail's own client, and IDLE no longer costs a process per folder.** Every folder
with IDLE on (the Inbox and Junk of each IMAP account) used to get a background process of its
own, about 40 MB each, so fifteen accounts came to more than a gigabyte just to wait for mail. All
of them now share one process, `imap-listener` (`app:imap:listen`), whose memory does not grow
with the number of folders, and a server that is slow to answer holds up only its own folders.
Sync, import, flags, moves, deletes, the Sent copy and the connection test speak IMAP through
the same client instead of webklex/php-imap, which now only parses mail (#82 replaces that).

What you may notice: a draft another client left in an IMAP Drafts folder is now a draft in
plMail (it was stored as `Draft` without the backslash and not recognised), and keywords other
clients set (`$Forwarded`, `$Junk`) are kept on new mail. A message the server lists but does not
hand over is asked for again a few times before it is passed over, instead of being skipped at
once. `app:imap:test` no longer writes a test message into the Sent folder unless given
`--append`.

The worker runs `imap-listener` and `jmap-listener` itself; the `imap-supervisor` process is
gone, and `--without=imap-supervisor` (or `--only`) still means both listeners. It also stops in
a few seconds on shutdown, where the per-folder processes could outlast the grace period and be
killed. No schema change; nothing to do on upgrade.
[#421](https://github.com/karatektus/pl_mail/issues/421).
