**A rate-limited Outlook mailbox is a warning in the log, not an error, and a sync asks Outlook
about half as much.** Outlook rate-limits a personal mailbox about once an hour even when it is
idle, and each time the log got an ERROR with a stack trace and an empty message. It is now one
warning that says what happened, how long Outlook asked to wait, and Microsoft's request id. A
sync also used to look up which folder is the Inbox, Sent and so on, thirteen requests, every
time; the answer is kept now and asked again only when the folder list changes, or once a day.
And a rate limit during those lookups is no longer taken to mean the folder does not exist, which
could file the Inbox as a custom folder for one sync or create a second Archive folder. No schema
change. [#524](https://github.com/karatektus/pl_mail/issues/524)
