**plMail can run as a single container, for Unraid.** With `APP_ALL_IN_ONE=1` the container runs
the web server, the queue workers, the scheduler, the IMAP supervisor and the Mercure hub itself,
next to a Postgres container of your own. That is the shape an Unraid Community Applications app
needs. "Restart app" and "Restart workers" both work in it, and compose and TrueNAS installs are
unchanged. See [Unraid](docs/install/unraid.md).
[#387](https://github.com/karatektus/pl_mail/issues/387).
