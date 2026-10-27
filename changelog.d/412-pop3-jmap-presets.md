**The provider list fills in POP3 and JMAP servers too, and a JMAP account sends through its
server.** Choose **POP3** or **JMAP** on the account form and then a provider, and plMail fills in
that provider's POP3 or JMAP server rather than switching the form back to IMAP; a provider without
one, such as iCloud for POP3, still switches it. Fastmail comes with its JMAP server, its session
URL and the API-token box ticked, and **plMail** is in the list too, for holding another plMail's
mail: it chooses JMAP and leaves the host to you. Under JMAP the form shows only what JMAP needs: no
encryption to choose, the **Session URL** behind a link until a server needs it, and **Outgoing —
JMAP** in place of SMTP, since the server sends your mail itself; **My server does not send over
JMAP — use SMTP** brings the SMTP fields back for a server that does not. No schema change.
[#412](https://github.com/karatektus/pl_mail/issues/412).
