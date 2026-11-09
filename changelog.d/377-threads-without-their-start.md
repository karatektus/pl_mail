**Replies to a message you never received are one conversation again.** GitHub mails every
comment on an issue as a reply to the post that opened it, and doesn't send that post to the
person who wrote it, so each comment showed up as a conversation of its own. Mail that refers to
the same missing first message is now grouped, and conversations already split this way come
together when their next message arrives (`app:backfill rethread` regroups them all at once).
IMAP and POP3 accounts only: Gmail, Outlook and JMAP accounts already took the provider's
grouping. No schema change. [#377](https://github.com/karatektus/pl_mail/issues/377), reported by
@phdelodder.
