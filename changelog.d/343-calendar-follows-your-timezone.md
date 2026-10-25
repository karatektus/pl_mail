**The calendar follows your timezone.** After you changed your timezone in Settings, the header
clock moved but the calendar grid, its current-time line and "today" stayed on the old zone, so
events sat hours away from the clock. The calendar is now always drawn on your own clock, and every
event shows at the moment it happens for you. A calendar's own time zone in **Settings →
Calendars** now only decides which zone a new event on it is written in, and it starts as **Follow
plMail**; calendars plMail made for you are set to follow, and ones subscribed from Google or a
CalDAV server keep the zone their server reports. Schema change: `calendar.time_zone` becomes
nullable. [#343](https://github.com/karatektus/pl_mail/issues/343), reported by @KieranBeals.
