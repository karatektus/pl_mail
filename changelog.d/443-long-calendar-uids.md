**A calendar event with a very long identifier no longer stops its calendar from syncing.** An event
whose UID was over 255 characters failed every sync of the whole calendar. Such UIDs now store
normally. An event whose identifier is too long to store at all (over 2,048 bytes) is skipped with a
warning in the log, so the rest of the calendar still syncs. Widens six calendar columns to `TEXT`
(migration `Version20261030120000`), with no table rewrite.
[#443](https://github.com/karatektus/pl_mail/issues/443), reported by @jeremyames-gmail.
