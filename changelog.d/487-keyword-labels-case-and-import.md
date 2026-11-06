**Labels as keywords: a keyword finds your label whatever its capitals, and switching it on reads the keywords mail already has.**
A keyword another tool sets in lower case — Paperless-ngx's `paperless`, say — now puts your existing
label **Paperless** on instead of making a second label `paperless`, as IMAP keywords do not care
about capitals. And switching **Labels as keywords** on now reads, once and in the background, the
keywords the account's mail already carried; before, mail that had a keyword before the switch went
on never got its label. A keyword no longer gives a system label such as Archive. No schema change.
[#487](https://github.com/karatektus/pl_mail/issues/487).
