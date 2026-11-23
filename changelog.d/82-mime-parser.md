**Mail that arrives as bytes is read by a maintained MIME parser.** IMAP, POP3, JMAP import and
decryption parsed mail with webklex/php-imap, which is no longer maintained, and most IMAP bugs of
the last months were its parsing mistakes, each with a correction of its own. They now go through
zbateson/mail-mime-parser behind plMail's own `MessageParser`, and the corrections are gone with
the library. What a reader notices: an attachment named only in its `Content-Type` keeps its name
instead of an eight-character checksum, so it downloads with its extension and a PDF gets its
preview ([#718](https://github.com/karatektus/pl_mail/issues/718)); an attachment with no name at
all is called after its type (`attachment.pdf`); and a message the old parser could not read at
all, such as a multipart message without a boundary, is stored instead of retried. Attachments
already stored under a checksum keep it. No schema change.
[#82](https://github.com/karatektus/pl_mail/issues/82)
