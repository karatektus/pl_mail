**A POP3 account whose server numbers its messages `1`, `2`, `3` deletes them again.** With
"delete from server" on, a server whose UIDLs are plain numbers, which RFC 1939 allows, made every
sync fail after storing the new mail and before deleting anything, so the server mailbox filled up
until it hit its quota. No mail was lost or fetched twice. No schema change.
[#522](https://github.com/karatektus/pl_mail/issues/522), reported by @mmssix.
