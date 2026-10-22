**A sync no longer fails after mail with an attachment was deleted on the server.** When Gmail,
Outlook or an IMAP server reported a message with an attachment as deleted, the sync that removed it
failed on its next database write: "A new entity was found through the relationship
'MessagePart#message'". The retry went through, but the failed run did not record the sync, and on
IMAP every folder after the one that tripped was skipped until the next run. Deleting mail now goes
the same way whether the server, a discarded draft or "Delete forever" asked for it. As a result,
a conversation that loses its last message is removed instead of left behind empty, and one deleted
for good drops the labels only its deleted messages carried. No schema change.
[#299](https://github.com/karatektus/pl_mail/issues/299).
