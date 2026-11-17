<!-- highlight -->
**Single emails instead of conversations (#615).** Conversation view can be switched off, and back,
at any time: **Conversation view** in any list's sort menu, `t`, or **Settings → Inbox → Message
list → Conversation view**. With it off every list has one row per email, filed by that email's own
folders (your replies are in Sent, not in the Inbox), opening a row shows that email with a link to
the whole conversation, and every action, pin and snooze acts on that email. Unread badges count
emails. Pins and snoozes are kept where they were made and each view shows the other's: a pinned
email pins its conversation, a pinned conversation puts its newest received email on top, and
taking a pin or snooze off takes off everything that put the row there. The apps learn the choice
from `urn:plmail:params:jmap:listview`. [#615](https://github.com/karatektus/pl_mail/issues/615).
