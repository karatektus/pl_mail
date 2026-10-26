**A partly deleted conversation shows the deleted part in Trash and the rest everywhere else.**
Opened from the Inbox, a conversation with a deleted message in it showed that message among the
others, and a discarded draft without even a mark; its row offered **Move to inbox** and **Delete
forever** instead of **Delete**, because the conversation carried Trash. Now each list shows its own
part: Trash the deleted messages, every other list the rest, each with its own sender, preview,
date and message count, and the buttons that fit. This replaces the **In Trash** chip of
[#336](https://github.com/karatektus/pl_mail/issues/336), since a conversation no longer shows
deleted and live mail together. **Delete forever** on such a conversation deletes only its deleted
part; it used to be refused. The phone app has done the same since 0.4.2. No schema change.
