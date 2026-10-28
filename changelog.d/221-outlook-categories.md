**Outlook categories set or cleared in Outlook can follow into plMail.** A Microsoft account's row
has a new switch, **Follow Outlook categories** (the tags icon), off by default. Switched on, a
category put on or taken off a message in Outlook puts the label on or takes it off in plMail at the
next sync, and a category plMail has not seen becomes a new label; only changes count, so labels
given in plMail are never stripped. Turning it on lists the account's folders once more. Labels
still never move mail, and labels were already written to Outlook as categories. Renaming a
category-backed label now also works where Exchange refuses to rename the category in place: plMail
makes the new one and deletes the old. The label dialog now names Microsoft accounts too. Adds a
nullable column, `message.graph_categories` (migration `Version20261028110000`).
[#221](https://github.com/karatektus/pl_mail/issues/221), by @phdelodder.
