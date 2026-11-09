**A Google holiday calendar is no longer read from scratch every fifteen minutes.** Google refuses
every sync token it hands out for its public holiday calendars, so each sweep found the stored
token refused and read the whole calendar again. A read-only calendar whose token is refused on
two runs in a row is now read every six hours instead; one accepted token puts it back on the
fifteen-minute sweep. Adds the `calendar.sync_token_rejections` column (default 0).
[#523](https://github.com/karatektus/pl_mail/issues/523).
