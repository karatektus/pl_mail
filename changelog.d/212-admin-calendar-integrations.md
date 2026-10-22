**Admin → Integrations lists the calendar services, so a CalDAV server pinned during setup can be
changed later.** The setup wizard could pin one CalDAV server address for everybody, and once setup
was finished nothing showed that address again. CalDAV and calendar feeds now have their own group
under the file services, with the same row, setup notes and **Configure** form, where the address
can be changed or cleared. A change applies the next time calendars are found on a connection;
calendars already mirrored keep the address they were found at. Both services are offered to every
user as before, so their rows say so and the form has no on/off switch. Once people have connected,
the CalDAV form says how many connections a change reaches. A calendar feed has no server to pin, so
its row offers nothing to configure unless an older address is stored, which it shows so it can be
cleared. Nextcloud stays with the file services, where its calendars' address is already set. The
server address field now accepts only an http or https address. No schema change.
[#212](https://github.com/karatektus/pl_mail/issues/212), by @phdelodder.
