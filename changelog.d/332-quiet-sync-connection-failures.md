**A sync that can't reach Gmail or Outlook no longer fills the log with stack traces.** When the
worker could not connect to the provider at all, every retry of the account sync was logged as a
warning with three nested stack traces. An outage that lasted ended at CRITICAL, with the job parked
on the failed queue. Now the sync waits and tries again on the same schedule as before, with one
info line per attempt. If the provider is still unreachable after about eight minutes, the sync is
dropped with a single warning. Nothing is lost: the next sync resumes from the same point. Fetching
particular messages still retries and fails as before, because nothing would list those again.
[#332](https://github.com/karatektus/pl_mail/issues/332)
