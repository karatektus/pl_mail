<!-- highlight -->
**Pin conversations to the top of the list (#654).** One click on the thumbtack beside a row's star,
the same button in an open conversation, `p`, a swipe or **Pin** above a selection keeps a
conversation at the top of every folder or label list it is in, above a thin line. The row moves up
at once and the rest of the list stays where it was. **Settings → Inbox → Message list → Pinned
conversations** sets when pinning asks "pin anyway?" (20 unless changed; it never refuses), how
long a pin lasts (forever, or a number of days), and whether moving a conversation keeps its pin.
The bin and spam always take a pin off. Pins belong to plMail and are not written to the mail
server; the apps see them as the `$pinned` keyword (`urn:plmail:params:jmap:pins`).
[#654](https://github.com/karatektus/pl_mail/issues/654).
