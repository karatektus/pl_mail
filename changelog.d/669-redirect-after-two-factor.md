**Signing in with a second factor no longer lands on the server's internal address.** Behind a
reverse proxy that forwards the internal address as the host (Authentik, for one), the code form
redirected there. Every redirect after signing in is now a path on the address the browser is
already on. [#669](https://github.com/karatektus/pl_mail/issues/669).
