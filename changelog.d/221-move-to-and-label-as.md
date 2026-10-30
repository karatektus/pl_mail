<!-- highlight -->
**Move to moves, and Label as only labels.** A folder is a place and a label is a tag, and plMail
now keeps them apart per account. **Move to** offers an account's folders and moves the mail there
on the server — the copy in the folder you are looking at, so a reply in Sent or a copy in Archive
stays put. **Label as** offers the labels that are tags there and never moves mail. On Gmail, which
has no folders, Move to offers the inbox, spam and trash; a new switch on a Gmail account's row,
**Move to offers labels**, adds its labels, and moving to one labels the mail and takes it out of
the inbox, as Gmail does. A move never makes a folder. The sidebar lists each account's folders
under the account and keeps the LABELS section for tags; **Folders in Labels** in Settings → Labels
lists folders there too. In a conversation, a folder chip says where a message is, and a label chip
has an × to take it off. Filters gain **Move to folder**; a filter that applied a label which is a
folder on every account it covers becomes one, because that is what it did, and Apply label now
only labels. To make a label a folder on one plain IMAP or Outlook account, open it and use **Change per
account**; its mail on that account moves into the new folder. **Make it a label** does the reverse and asks where
the mail goes. [#221](https://github.com/karatektus/pl_mail/issues/221).

**Migration:** `Version20261030100000` rewrites the actions of those filters; no schema change.
