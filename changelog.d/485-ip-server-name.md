**`SERVER_NAME` can be the machine's IP address.** Set to an address such as `192.168.2.45`, the
web server issued a certificate for it but then offered a browser a `localhost` certificate it did
not have, so every visit ended in an SSL protocol error that cannot be clicked through. It now
offers the address's own self-signed certificate, and the browser shows its usual warning. The
stock default, a domain, and `:80` behind a reverse proxy are unchanged. See
[Configuration → `SERVER_NAME`](docs/install/configuration.md).
[#485](https://github.com/karatektus/pl_mail/issues/485), reported by @ohnese.
