**Nextcloud calendars come with the Nextcloud connection.** A Nextcloud connected for files under
Settings → Integrations is now listed in Settings → Calendars, and **Find calendars on…** lists its
calendars with the same app password. There is no second address or password to type. The address
is the connection's own, or the one an administrator pinned in Admin → Integrations; a pinned
address on a private network now works for calendars too, where before it was refused as a private
address. Disconnecting Nextcloud removes its calendars as well, and the confirmation says how many;
events that came from mail are moved to the default calendar first, as when a CalDAV server is
disconnected. A calendar already mirrored through a separate CalDAV connection to the same
Nextcloud shows as *Already mirrored through …* and cannot be ticked a second time. Existing CalDAV
connections are left as they are. No schema change.
[#236](https://github.com/karatektus/pl_mail/issues/236), by @jo2904.
