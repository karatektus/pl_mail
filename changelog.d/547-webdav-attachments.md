**Attach from and save to any WebDAV server.** WebDAV joins the file services under Settings →
Integrations, for a file server that is neither Nextcloud nor OpenCloud: FileBrowser Quantum, a
Synology NAS, `rclone serve webdav`, Apache mod_dav. You give the full address of the folder to open,
path included (on FileBrowser Quantum, `/dav/` and the source name), a username if the server wants
one, and a password or API token. Browse, attach copies, save attachments and make folders; WebDAV
has no links, previews or search. Administrators: a server on your own network has to be allowed
under Admin → Integrations → Servers on a private network, as for the other self-hosted services. No
schema change.
[#547](https://github.com/karatektus/pl_mail/issues/547), requested by @laserhill.
