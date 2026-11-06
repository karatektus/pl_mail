**A reply a filter sends to Spam no longer brings its conversation back to the Inbox as new mail.**
When somebody you had filtered as spam answered into a conversation that was still in the Inbox,
the conversation jumped to the top, turned bold, counted towards the Inbox badge, was marked new and
could even move to another tab — for a message that was in Spam. The filter now runs before the
reply is allowed to move its conversation, and mail in Spam or the bin no longer counts as unread
in a conversation that has other mail. A conversation that is all spam still sorts and counts in
Spam as before. No schema change. [#470](https://github.com/karatektus/pl_mail/issues/470).
