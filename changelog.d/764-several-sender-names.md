**One address can send under several names.** Under each address in **Settings → Aliases**,
**Other names** adds more: "A", "a" and "Arnaud" all writing from `a@mail.com`. Each is a line of
its own in the From menu, a draft reopens on the one it was written under, and the mail goes out
under it. The names share the address's signature and default Bcc, and over JMAP each is an
identity of its own, so the Android app lists them too.
[#764](https://github.com/karatektus/pl_mail/issues/764), by @jo2904.

**Migration:** adds `email_alias.extra_names` (JSON, empty) and `email_alias.extra_name_seq`. No
deployment change.
