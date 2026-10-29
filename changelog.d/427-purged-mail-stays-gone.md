**Mail deleted forever stays deleted, and Trash emptied elsewhere empties here.** On IMAP, a
trashed message pointed at Trash with no address until the server's answer came back, so "Delete
forever" in that moment removed it here and left it on the server, and the next sync brought it
back; mail deleted from Trash in webmail stayed in plMail. A message now keeps its real address
until the server has moved it, a purged copy is never imported again while the server catches up,
and a delete the server refused is asked again by the next sync. Waking a snoozed conversation
also moves it back into the inbox on the server, where before it stayed in the archive folder.
[#427](https://github.com/karatektus/pl_mail/issues/427), [#415](https://github.com/karatektus/pl_mail/issues/415).
