<!-- highlight screenshot=idle-folders -->
**Choose which folders are watched, and how often each account is checked.** In Settings → Mail
accounts, the folders list of an IMAP account has a bolt beside each folder: lit, the folder is
watched with IDLE and new mail in it shows up at once; unlit, it is checked at the account's
interval. Only the inbox is lit by default. Switch the inbox off too and the account is
polling-only. The new clock icon on every account's row sets that interval: 1, 5, 15, 30 or 60
minutes, 15 by default as before.

What you may notice: Junk folders are no longer watched. The upgrade switches IDLE off on them,
which frees one connection per account; light the bolt again if you want spam to show up at once.
[#202](https://github.com/karatektus/pl_mail/issues/202).
