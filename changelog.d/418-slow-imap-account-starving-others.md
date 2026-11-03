**One slow IMAP account no longer holds up every other account's new mail.** When an IMAP server
kept a folder's sync running into the five-minute limit, as Gmail's IMAP did on some installs, the
folder asked for a new sync every few seconds while each one took five minutes to fail and was then
tried five more times. The queue grew faster than the worker could empty it, and other accounts'
new mail waited behind it for hours. A folder now has at most one sync waiting at a time, and a sync
cut off by the limit is recorded on the account but not retried, because the next IDLE notice or
poll asks for it again anyway. No schema change.
[#418](https://github.com/karatektus/pl_mail/issues/418), reported by @CorrectRoadH.
