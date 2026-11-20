**Mail sent from a Google account is listed once in Sent.** Gmail gives mail sent through its API a
Message-ID of its own, so the copy it files in Sent did not match the row plMail wrote when it sent
the mail: every message appeared twice, the second time in a conversation of its own, and answers
named an id no row had. plMail now reads back the Message-ID Gmail gave the mail and keeps that one,
so the Sent copy is merged into the row and answers find their conversation. Messages already listed
twice stay that way. No schema change. [#702](https://github.com/karatektus/pl_mail/issues/702),
reported by @wbilger.
