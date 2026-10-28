**A refused sync batch no longer reads as an error in the log.** When the database refused a batch
of fetched mail, usually because a move made in the app filed one of its UIDs while the sync was
still fetching, the log showed a warning with a full stack trace, although the one-at-a-time retry
stored everything. That line is a notice now. A message that really cannot be stored is still
logged as an error of its own. [#352](https://github.com/karatektus/pl_mail/issues/352).
