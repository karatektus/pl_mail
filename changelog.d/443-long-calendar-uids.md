**A calendar event with a very long identifier no longer stops its calendar from syncing.** An event
whose UID or address was too long for its column or index failed every sync of the whole calendar,
and an emailed invitation with a UID over about 124 characters failed too. Calendar and event
identifiers now store at any length. Widens seven calendar columns to `TEXT`, rebuilds their indexes
around hashes and adds three generated columns (migration `Version20261030120000`). The migration
rewrites `calendar_event`, which blocks calendar writes and reads while it runs.
[#443](https://github.com/karatektus/pl_mail/issues/443), reported by @jeremyames-gmail.
