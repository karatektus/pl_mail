**A Gmail message deleted before plMail fetched it is no longer logged as a warning.** Every
autosave of a draft written in Gmail replaces the one before it, and mail a filter or another
device deletes on arrival goes the same way, so Gmail sometimes names a message that is gone by the
time it is asked for. That showed in the admin log as "messages permanently unfetchable" about twice
a day. It is now an info line, "messages deleted in Gmail before they were fetched". Any other
refusal still warns, and now names the HTTP status Gmail answered for each message.
[#751](https://github.com/karatektus/pl_mail/issues/751).
