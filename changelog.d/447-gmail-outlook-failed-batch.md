**One Gmail or Outlook message that fails to import no longer fails a later batch.** When building a
message failed partway, the attachments already handled stayed queued for the database, pointing at
a message that would never be saved. The next sync batch to write anything then failed on them,
with an error naming messages that were fine. It recovered on the retry and nothing was lost, but
the log pointed at the wrong mail. No schema change.
[#447](https://github.com/karatektus/pl_mail/issues/447), by @f-liva.
