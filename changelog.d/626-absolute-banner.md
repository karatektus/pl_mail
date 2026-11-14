**A mail whose header banner is absolutely positioned no longer covers its own text.** Mail laid
out like a print page, such as the notices some insurers send through Scriptura, puts the banner in
a `position: absolute` box, so the text started at the top of the message and ran underneath the
picture. The reading pane now drops `position: absolute` as the composer already did, which is also
what Gmail does. No schema change. [#626](https://github.com/karatektus/pl_mail/issues/626), by
@phdelodder.
