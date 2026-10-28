**Labels can be IMAP keywords that other mail apps see.** An IMAP account's folder list has a new
switch, **Labels as keywords**, off by default. Switched on, a label put on or taken off a
conversation is written to the server as a keyword on each of its messages, where the server keeps
keywords, and a keyword set or cleared in another mail app (Thunderbird's tags, for one) puts the
label on or takes it off in plMail. Labels still never move mail. The label dialog now says, per IMAP
account, whether the label is a folder there, a keyword, or only in plMail, and why. System keywords
such as `$Junk` or Thunderbird's `$label1` are never read as labels. Adds a nullable column,
`mailbox.keywords_allowed` (migration `Version20261028100000`).
[#221](https://github.com/karatektus/pl_mail/issues/221), by @phdelodder.
