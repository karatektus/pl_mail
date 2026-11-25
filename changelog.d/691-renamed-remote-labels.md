**A label renamed in Gmail, an Outlook category renamed in Outlook, or a mailbox renamed on a JMAP
server is renamed in plMail too.** It used to come back as a second label under the new name, with
the old one left beside it. The sync now finds a label by the id the server gave it, and goes by
name only for a label the account has never had. A label on several accounts that is renamed on
one of them is split off for that account, as a rename under that account in plMail does. Labels
already doubled this way are merged back into one on the next sync.
[#691](https://github.com/karatektus/pl_mail/issues/691).
