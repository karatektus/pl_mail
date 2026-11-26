<!-- highlight -->
**Workspaces can keep to some of your accounts, and have a sidebar of their own.** In edit mode,
**Groups & sidebar** chooses which account groups a workspace shows: the mail list, Starred, Unread
and the rest, the sidebar's rows and counts, and Compose's first From keep to their accounts.
**Settings → Inbox → Account groups** (the unified inboxes) now lets an account be in several
groups; **Other** is the rest, accounts in none of yours, and is no longer filled or renamed by
hand. A workspace picks the built-in Mail sidebar, none, or one of your own, made of fixed links,
headings, the clock and rows that grow by themselves, and shared between the workspaces that use
it. Each workspace tab shows how much arrived since you last looked, and a dot for what starts
within the hour. The calendar and tasks panes show the workspace's calendars and lists, the others
faint, or all alike; a calendar or list without an account belongs to one workspace. `space:` and
**Settings → Search** limit search to a workspace. Switching between workspaces with other accounts
draws everything in one go. See [Workspaces](docs/features/workspaces.md).
[#783](https://github.com/karatektus/pl_mail/issues/783).

**The clock is no longer a sidebar placement:** the Mail sidebar has a clock switch of its own, and a
sidebar of your own a Clock row. Whoever had the clock in the sidebar keeps it there.

**Migration:** two migrations. One lets an account be in several unified inboxes (the unique index
on `unified_inbox_account.account_id` becomes one on the inbox and account pair); the other adds an
index on `message (account_id, created_at)` for the new counts, which takes a moment on a large
mailbox. No data moves.
