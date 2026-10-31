**A list of addresses pasted into To, Cc or Bcc becomes one recipient per address.** Separated by
semicolons, commas, new lines or spaces, with or without names, as Outlook and Gmail copy them:
`jo@jo.fr; Ann <ann@example.org>` or `jo@jo.fr jo2@jo.fr`. A space inside a name does not split it,
and a pasted name with no address in it is still searched for. An address already in the field is
not added twice, and what is not an address stays in the box with the usual error. No schema
change. [#379](https://github.com/karatektus/pl_mail/issues/379), by @jo2904.
