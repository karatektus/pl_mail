**The database comes back after Docker restarts.** The `database` service in `compose.yaml` had no
restart policy, so when the Docker engine restarted (a package upgrade, a reboot) it stayed stopped
while `php` and `worker` came back and kept restarting, unable to resolve `database`, until somebody
started it by hand. It now restarts `unless-stopped`, like the services that depend on it. No schema
change. [#757](https://github.com/karatektus/pl_mail/issues/757).
