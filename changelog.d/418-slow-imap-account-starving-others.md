**One slow IMAP account no longer holds up every other account's new mail.** When an IMAP server
kept a folder's sync running into the five-minute limit, as Gmail's IMAP did on some installs, the
folder asked for a new sync every few seconds while each one took five minutes to fail and was then
tried five more times. The queue grew faster than the worker could empty it, and other accounts'
new mail waited behind it for hours. A folder now has at most one sync waiting at a time, and a sync
cut off by the limit is recorded on the account but not retried, because the next IDLE notice or
poll asks for it again anyway. An account sync cut off this way is no longer recorded as a success,
and a folder that loses its connection no longer fails every folder after it: the rest get a fresh
connection. **Settings → Account health** now says when an account is behind on new mail, with
the times a sync last went through, last started and was last asked for, so an account with no
error is no longer taken for one that is up to date. No schema change.
[#418](https://github.com/karatektus/pl_mail/issues/418), reported by @CorrectRoadH.
