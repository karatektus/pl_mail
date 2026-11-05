<!-- highlight -->
**Several unified inboxes, each with its own name, icon and accounts.** Settings → **Unified
inboxes** adds inboxes beside the one you have — "Work" and "Personal", say — lets you name them,
give each an icon and drag them into order, and under **Accounts** chooses which inbox each
account's mail is listed in. Every inbox is its own row in the sidebar with its own unread badge,
"new" dot and tabs, and **Select all** in it stays inside it. An account taken out of all of yours
goes to **Other**, which appears in the sidebar once it holds one, so nothing becomes harder to
reach; deleting an inbox moves its accounts there too, and no mail is touched. Until you change
anything there is one **Inbox** over every account, as before. Phone apps can read the inboxes over
JMAP (`UnifiedInbox/get`, `urn:plmail:params:jmap:unifiedinboxes`), and a configuration backup
carries them. Two new tables, made by the migration; nothing to do. [#265](https://github.com/karatektus/pl_mail/issues/265).
