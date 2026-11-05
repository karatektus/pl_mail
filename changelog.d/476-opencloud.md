<!-- highlight -->
**Attach from and save to OpenCloud.** OpenCloud joins the file services under Settings →
Integrations: browse your personal space, your project spaces and the files shared with you, search
all of them by file name, attach a copy or a link, and save attachments into them, with previews in
the picker. You connect with your OpenCloud username and an app token from your account settings →
App Tokens; give the token a long expiry. Administrators: OpenCloud must accept app tokens
(`auth-app` running, `PROXY_ENABLE_APP_AUTH=true`), and links need
`OC_SHARING_PUBLIC_SHARE_MUST_HAVE_PASSWORD=false`. No schema change.
[#476](https://github.com/karatektus/pl_mail/issues/476), requested by @SpaceFrags.
