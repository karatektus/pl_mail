**iCloud calendars show the events made on iCloud, and iCloud contacts sync.** An iCloud calendar
connected over CalDAV took events made in plMail, but never showed the ones made on iCloud: it
looked synced, with no error, and stayed empty. iCloud address books failed with "unexpected
response (400)". plMail asked iCloud for those events and contacts by their full URLs, and iCloud
only answers to the path. It now asks by path, and a request that returns none of what it asked
for shows as a sync error instead of an empty calendar.
[#637](https://github.com/karatektus/pl_mail/issues/637), reported and diagnosed by @Kawadian.

**Migration:** iCloud calendars and address books forget their sync position, so their next sync
reads them in full once and brings in the events that were missed.
