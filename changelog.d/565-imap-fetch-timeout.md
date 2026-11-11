**An IMAP import on a slow connection finishes instead of retrying the same messages for ever.**
Since v0.4.1 every IMAP command had to be over within thirty seconds, however steadily the server
was answering. An import fetches fifty whole messages at a time, and on a slower line (a NAS at
home, for example) fifty messages with their attachments take longer than that: the fetch was cut
with "did not answer UID FETCH in time", nothing of it was stored, and every retry asked for the
same fifty messages. The import of that folder never moved, and leaving the folder out only moved
the problem to the next one. The thirty seconds are now how long a server may go quiet, as they
were before v0.4.1, and the five-minute limit on a whole import step still stops a server that
keeps a fetch alive by trickling. Large uploads, such as the copy of a sent message with its
attachments, are measured the same way. An import that stopped this way starts again by itself
with the account's next sync; the import steps already under Admin → Failed messages can be
retried or deleted, and neither is needed. No schema change, no deployment change.
[#565](https://github.com/karatektus/pl_mail/issues/565).
