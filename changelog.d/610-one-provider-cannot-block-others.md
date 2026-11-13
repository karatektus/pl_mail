**A mail provider that stops answering no longer holds up every other account.** All mail
fetching ran through one worker, one job at a time, so a job stuck on a provider that was down
held up every account on the installation: during a Microsoft outage, Gmail and IMAP accounts
stopped syncing too. `worker-ingest` now handles each job in a process of its own, several
accounts at once. Jobs for the same account still run one after another, and a job that runs past
six minutes is ended. How many run at once is set on **Admin → Performance** (four by default).
Calls to Microsoft Graph and the Gmail API also have a time limit now, and an Outlook sync during
a Microsoft outage waits quietly instead of logging a warning with stack traces on every attempt.
[#610](https://github.com/karatektus/pl_mail/issues/610).
