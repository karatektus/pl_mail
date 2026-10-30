**Browser notifications can be limited to Primary mail, and skip mail you have already read.**
With web push on in more than one browser, every browser said "New mail" for every message that
reached the Inbox, whatever its tab, and kept doing so after the message was read or deleted on a
phone. Settings → Notifications has two new choices, for your whole account rather than one device.
**Notify for** is "All of the Inbox" (as before) or "Primary only"; plMail decides on the server,
and mail the assistant holds back is announced once it has been put in Primary. **Only notify for
unread mail** makes a browser wait twenty seconds after the push and show nothing if the Inbox has
nothing unread by then, taking down a notification it had shown for earlier mail. Both are off by
default, so nothing changes until you turn them on. The Android app already narrows to Primary by
itself and is not affected. No schema change.
[#369](https://github.com/karatektus/pl_mail/issues/369), by @phdelodder.
