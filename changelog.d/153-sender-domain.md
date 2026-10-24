<!-- highlight -->
**An open message shows who it really comes from.** A display name is whatever the sender typed,
so `"PayPal" <service@evil.example>` read exactly like the real thing. The address is now as large
and dark as the name, and wraps instead of being cut off at the end, where the domain is.

When a message's `Reply-To` points at a different organisation than its `From`, the header says so
(*Replies go to …*). That is a line of fact, not a warning, on purpose: newsletters and ticket
systems answer elsewhere all the time, and a banner on ordinary mail teaches people to click
through the one that matters. The existing "sender's name does not match its address" banner is
unchanged. On for everyone; there is no setting. No migration.
[#153](https://github.com/karatektus/pl_mail/issues/153), by @Flouss.
