**Lists, unread badges and search answer faster on a large mailbox.** Every list and badge leaves
out conversations that are entirely in the bin (#268), and each of them worked that out from the
messages on every query: about 170 ms each on a mailbox of 67k conversations, and the sidebar
asks about ten times. Whether a conversation is in the bin is now stored on it and kept by the
database. On the same mailbox the Inbox count dropped to 35 ms, the unified Inbox page to 52 ms,
and most other lists and badges to between 1 and 20 ms. The results are the same on all 41 lists,
badges and searches compared. [#223](https://github.com/karatektus/pl_mail/issues/223).

**Migration:** adds `message_thread.binned` with the triggers that keep it, and fills it for the
conversations already stored. That took under a second on 67k conversations.
