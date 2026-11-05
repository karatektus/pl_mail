**Folders stay out of LABELS, and an account's folders are drawn as the server's tree.** With two
or more accounts, every folder of one account was listed under LABELS too, because it counted as a
label on the other account, and deleting it there deleted the folder. A folder of one account is
now listed under LABELS, and offered by **Label as** for a selection, only once it has been put on
another account's mail as a label. Under each account, a folder inside another, such as one in the
Inbox, is now inside it in the sidebar as in other mail apps, and a folder with folders in it
collapses on its own. **Migration:** quick: one row in `label_binding` for each account whose mail
carries a label it had none for. [#221](https://github.com/karatektus/pl_mail/issues/221),
reported by @adrianfurgol.
