**A CalDAV, CardDAV or file server on your own network can be allowed from the admin page.** plMail
refuses a server address that resolves to a private address, so nobody signed in can aim it at the
database or anything else on its network, and the way to make an exception was the
`INTEGRATIONS_ALLOWED_HOSTS` variable. A Docker Compose install could not set it: `compose.yaml` never
passed it into the containers, so a Baïkal at home stayed refused whatever was in `.env`. Admin →
Integrations now has **Servers on a private network**, a list of hosts an administrator allows, each
with its own tick for plain `http://`. The error a user sees names that page, a change applies at
once, and the list travels in a config backup. Plain http is per host now instead of the
install-wide `INTEGRATIONS_ALLOW_HTTP`. Neither variable is read any more; a migration creates the
new table and copies what they held into it, and with `INTEGRATIONS_ALLOW_HTTP` on it also allows
plain http for the host of every connection already saved with an `http://` address, so those keep
working.
[#564](https://github.com/karatektus/pl_mail/issues/564), reported by @sbach89.
