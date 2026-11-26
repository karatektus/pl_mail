**Files attached in Apple Mail show up as attachments.** Apple Mail sends a file it attaches as
`Content-Disposition: inline` with no `Content-ID`, and plMail stored such a part as an inline
image. Nothing in the body pointed at it, so it was shown nowhere: no chip under the message and
no paperclip in the list, while Gmail listed it. A part is now inline only when the message's HTML
embeds it by its `cid:`, whatever its disposition says. Mail already stored gets its attachments
back from `app:backfill attachment-unreferenced-inline`, which also runs as an upgrade task;
calendar invites stay as they are. No schema change.
[#753](https://github.com/karatektus/pl_mail/issues/753).
