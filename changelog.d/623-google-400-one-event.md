**One Google event that Google refuses no longer stops the whole calendar syncing.** When Google
answered an edit with 400 (a request it will never accept, such as a birthday event with the wrong
visibility), the sync job failed, was retried against the same event, and ended up on the failed
queue, so nothing else on that calendar synced either. That event is now given up on and logged,
and the rest of the calendar carries on. [#623](https://github.com/karatektus/pl_mail/issues/623).
