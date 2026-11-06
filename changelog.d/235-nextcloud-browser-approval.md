<!-- highlight -->
**Connect Nextcloud by approving in your browser.** The Nextcloud connect form has **Connect with
browser approval**: it opens your Nextcloud, you log in there (two-factor sign-in and single sign-on
work as usual) and approve plMail, and the connection is saved by itself, with no app password to
create and paste. It works from Settings → Integrations, from the edit form of an existing connection
to replace a revoked app password, and in the setup wizard. The manual fields stay as they were. No
schema change. [#235](https://github.com/karatektus/pl_mail/issues/235), designed by @phdelodder.
