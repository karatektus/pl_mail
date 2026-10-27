**Conversations no longer show every message twice on Proton Bridge.** An IMAP account whose
server marks a folder as a view of mail kept elsewhere — All Mail beside an Archive folder, and
Starred — no longer syncs that folder by default, and the copies it already stored are removed over
the next few syncs. Mail that only All Mail holds stays. Gmail over IMAP keeps All Mail on, as it is
where archived mail lives there. You can switch either folder back on under the account's folders.
Schema change: one new column on `mailbox`.
[#414](https://github.com/karatektus/pl_mail/issues/414).
