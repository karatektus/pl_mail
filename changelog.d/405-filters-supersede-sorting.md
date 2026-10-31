**A filter files everything it matches, and your replies no longer take a conversation out of its
tab.** File in category used to file only mail whose sender the receiving server had verified. That
left out your own sent mail and mail a platform sends on somebody's behalf, such as an answer to a
job application from a recruiting system. A filter now outranks every other sorting whatever that
check says. Instead, filters leave mail in Spam alone unless they name it with **Has label** *Spam*;
"Test my filters" says when it skipped one for that. A conversation now follows the newest message
you *received* in it: your own replies and drafts move it up the list but not into another tab.
The "not filed: the receiving server did not verify the sender" line under a filter is gone. Adds
`message_thread.category_decided_at` and drops `mail_rule.run_unfiled`; the migration re-resolves
every conversation nobody dragged onto a tab, once. Mail a filter refused before is filed when you
press **Apply to existing mail** on that filter again.
[#405](https://github.com/karatektus/pl_mail/issues/405).
