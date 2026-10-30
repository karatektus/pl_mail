**A file service that stops answering no longer crashes the file picker.** A Paperless, Immich or
Nextcloud server that accepted the connection and then said nothing left the page waiting until PHP
gave up, which showed the generic error page and took a server thread with it. The picker now says
it could not reach the service after ten seconds. Copying a file to or from a service has no time
limit at all as long as data keeps moving, in either direction: a large attachment is no longer cut
off at PHP's 30 seconds, and a transfer is only given up on after a minute with no data moving.
Connecting a CalDAV or CardDAV server or an ICS feed that does not answer now fails the same way,
and renewing a Google or Microsoft token gives up after ten seconds instead of waiting for ever. No
schema change. [#449](https://github.com/karatektus/pl_mail/issues/449), reported by @f-liva.
