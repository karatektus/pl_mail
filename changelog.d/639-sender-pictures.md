<!-- highlight screenshot=sender-pictures -->
**Senders have pictures (#639).** The circle beside a sender shows a picture where plMail has one,
and the initial where it does not. A colleague on the same server shows their own profile picture,
and a contact shows the photo on their card. If an administrator allows it under **Admin → Sender
pictures**, plMail also looks up companies' BIMI logos and website icons and, for anyone who
switches it on under **Settings → Reading & writing → Reading**, Gravatar and Libravatar. The
lookups run in the background on the maintenance worker, and your browser never contacts these
services. A company logo only appears on mail your server confirmed came from that company. Mail in Spam or Trash never starts a lookup. Pictures
found later replace the initials on screen as they arrive, and the apps get them through JMAP
(`senderPicture` on `Email/get`).

**Migration:** adds the `sender_picture` and `sender_picture_settings` tables and a nullable
`message.sender_verified` column, which Postgres adds without rewriting the message table. Lookups
are off until an administrator turns them on. Pictures are stored under `uploads/sender-pictures`
in the existing storage volume.
