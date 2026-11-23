**Hebrew mail from Outlook shows its text and its sender's name.** Outlook and Microsoft 365 label
Hebrew mail ISO-8859-8-I, a name neither of plMail's charset converters knew: the message was stored
with an empty body, and the sender's name came out as "éùøàì éùøàìé". The label is now read as the
Hebrew charset it is. The text of mail in the other Hebrew and Arabic labels that name a text
direction (ISO-8859-8-E, ISO-8859-6-I, ISO-8859-6-E) is read the same way. Mail that arrived before
the update stays as it was stored. No schema change.
[#714](https://github.com/karatektus/pl_mail/issues/714), by @swell-d.
