**IMAP folders can be made, renamed and deleted in plMail.** An IMAP account's folder list
(Settings → Accounts → **Folders**, or the ⋯ menu on the account in the sidebar, or **+ New folder**
at the end of its folders there) gains **New folder**, and each of your own folders a rename and a
delete button. A new folder goes where you pick it in the server's real tree, named in any script;
the server's separator, its namespace and its refusals are respected and shown. The inbox and the
system folders are never renamed or deleted, and only an empty folder can be deleted. Making a
label still never makes a folder, and in the sidebar's LABELS section a label that is an IMAP
folder now wears a folder instead of a dot. A folder sync also stopped re-deriving every folder's
label from its path, which had undone label renames and let two folders that differ only by INBOX
take one label from each other, and a folder plMail creates for a move is no longer encoded to
modified UTF-7 twice when its name is not plain ASCII. No schema change.
[#221](https://github.com/karatektus/pl_mail/issues/221), by @phdelodder.
