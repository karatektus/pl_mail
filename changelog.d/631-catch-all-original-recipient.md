**A reply to catch-all mail comes from the address it was sent to, on Purelymail and Soverin
too.** These providers deliver catch-all mail to the mailbox under its own address and write that
into `Delivered-To`, so plMail answered from the primary address. A delivery header that names the
mailbox itself no longer counts as the catch-all address; the original recipient is read from
`X-Pm-Original-To`, `X-Original-To` or a `Received` hop, and otherwise from To and Cc.
[#631](https://github.com/karatektus/pl_mail/issues/631).
