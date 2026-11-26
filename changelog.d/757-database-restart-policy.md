**The database comes back after Docker restarts.** The `database` service in `compose.yaml` had no
restart policy, so when the Docker engine restarted (a package upgrade, a reboot) it stayed stopped
while `php` and `worker` came back and kept restarting, unable to resolve `database`, until somebody
started it by hand. It now restarts `unless-stopped`, like the services that depend on it.
[#757](https://github.com/karatektus/pl_mail/issues/757), by @dzonder.

**Migration:** no schema change. `docker compose pull` does not update `compose.yaml`, so fetch the
new one first (`git pull` in the clone, or copy it again), then `docker compose up -d --wait`
recreates `database` with the policy. To fix a running install without recreating it,
`docker update --restart unless-stopped <database container>` does the same.
