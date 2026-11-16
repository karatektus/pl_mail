<!-- highlight -->
**plMail can be its users' Libravatar server (#642).** Turn it on under **Admin → Sender pictures**,
point your domains' DNS at it, and mail programs, forges and websites that use Libravatar show the
profile picture your users chose here. The card lists every domain your users have addresses at,
with the exact SRV record each one needs, and a **Check DNS** button that says which are right. It
also says what DNS cannot check: an https address with a real certificate, reachable from outside.
Each user still decides for themselves under **Settings → Profile**; until they do, plMail answers
as if it did not know their address.

**Migration:** `sender_picture_settings` gains `libravatar_enabled`, off. `/avatar/` is a new public
route; behind a reverse proxy, let it through if you turn this on.
