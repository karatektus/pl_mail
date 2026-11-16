**Encrypted and signed mail from a Gmail account opens again at the other end (#651).** Gmail's API
rewrites every message it sends, and turned a PGP/MIME message into an ordinary one with the
ciphertext as its text: the recipient's program could not open it, while the copy in Sent looked
fine. plMail now sends signed and encrypted mail from Gmail accounts through Gmail's SMTP server,
signed in with the same Google sign-in, which delivers the message as it was written. Other mail
still goes through the API. An installation that may only reach Google over HTTPS needs
`smtp.gmail.com:465` allowed as well.
[#651](https://github.com/karatektus/pl_mail/issues/651).
