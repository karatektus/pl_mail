**The "New mail" notification no longer fires for mail a rule has already read or filed away.** The
arrival was recorded before the rules ran, so the installed app and the browsers announced a
message that a "mark as read", archive, Trash or Spam rule had dealt with a moment later. The push
is now decided after the rules: only unread mail that is still in the Inbox counts, which also
leaves out mail that was already read on another device before plMail fetched it. No schema
change. [#368](https://github.com/karatektus/pl_mail/issues/368), by @phdelodder.
