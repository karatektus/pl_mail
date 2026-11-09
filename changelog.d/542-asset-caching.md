**Pages are ready sooner after the first visit, and the first visit loads less.** The browser was
never told it could keep plMail's scripts, so every full page load asked again for each of about
two hundred modules; on a NAS install the inbox HTML arrived after 0.6 seconds and the page was
ready after 4.7. Compiled assets now carry a one-year `Cache-Control: immutable`: their file names
change with their content, so an update still reaches the browser at once. The controllers of
screens the mail list does not use (admin, calendar, contacts, settings, rules, sign-in, setup,
onboarding, What's new) now load when such a screen opens rather than on every page. No schema
change; the header is set by the image's own Caddy, so an install behind a reverse proxy gets it as
long as the proxy passes it on.
[#542](https://github.com/karatektus/pl_mail/issues/542).
