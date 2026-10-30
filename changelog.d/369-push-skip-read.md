**A browser notification can skip mail that is already read.** With web push on in more than one
browser, every browser said "New mail" for every message, and kept doing so after the message was
read or deleted on a phone. Settings → Notifications has a new switch, "Skip mail that is already
read", for the whole account rather than one device: when it is on, a browser waits twenty seconds
after the push, asks whether the Inbox still has anything unread, and shows nothing when it does
not, taking down a notification it had shown for earlier mail. Off by default, so nothing changes
until you turn it on. Choosing which category notifies (Primary only) is not part of this. No schema
change. [#369](https://github.com/karatektus/pl_mail/issues/369), by @phdelodder.
