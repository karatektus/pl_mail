**Settings → Accounts shows POP3 and JMAP accounts as what they are.** The badge on an account's
row said IMAP for every account that signs in with a password, so a POP3 account looked as if the
protocol choice had not been saved. It now shows the incoming protocol, in the same words as the
account form. The System page's "How late mail arrives" table made the same mistake and is fixed
too. Display only: the accounts always synced over the protocol they were set to. No schema
change. [#528](https://github.com/karatektus/pl_mail/issues/528), reported by @ohnese.
