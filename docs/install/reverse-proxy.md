# Behind a reverse proxy

Anything reachable from outside your own network wants a real certificate in front of it, and
plMail is written on the assumption that terminating TLS elsewhere is the normal deployment. Three
settings decide whether that works: `APP_PUBLIC_URL`, `TRUSTED_PROXIES` and `MERCURE_PUBLIC_URL`.
Each of them fails quietly when it is wrong, which is the reason for this page.

Defaults and precedence for everything named here are in the
[configuration reference](configuration.md).

## The shape of it

Your proxy holds the certificate and forwards to the `php` container over plain HTTP:

```yaml
services:
  php:
    environment:
      SERVER_NAME: ":80"        # FrankenPHP serves plain HTTP; no certificate here
```

`SERVER_NAME` defaults to `localhost, php:80`, and Caddy inside the container decides from that name
whether to terminate TLS itself. `:80` tells it not to. Publish only the HTTP port then — `HTTP_PORT`
maps the host port to container port 80 — and let the proxy reach it. `truenas.compose.yaml` does
exactly this and publishes `30080`.

The other half is telling the app who it is:

```
APP_PUBLIC_URL=https://mail.example.com
TRUSTED_PROXIES=127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16
MERCURE_PUBLIC_URL=https://mail.example.com/.well-known/mercure
```

**The failure mode is leaving `SERVER_NAME` at its default behind a proxy.** Caddy then tries to
serve `localhost` while the proxy addresses it by container name or IP, and the request does not
match a site block.

## `APP_PUBLIC_URL`

The address plMail is reached at from outside. It is not a cosmetic setting: it is what Google and
Microsoft are told to call back to.

It has no default and cannot be inferred at the moment it is needed, because the process that
registers a push subscription is a long-running worker or a scheduled console command — neither has
a request to read a hostname from. So the setup screen asks for it and writes it into
`var/secrets/generated.env`, the one place a running container can write that every other service
reads. Anything supplied through the environment wins over that stored value.

It is resolved **per call** rather than injected once, which is what lets a worker that booted
before setup see the address an administrator saved afterwards. Saving it also signals every worker
to restart so they pick it up immediately instead of at their next hourly recycle.

Two hard requirements come from the providers, checked locally before a registration is attempted
so that the log says which setting is missing rather than repeating a remote validation error:

- it must start with `https://`
- its host must not be `localhost`, `127.0.0.1` or `::1`

Fail either and calendar push and Microsoft Graph mail push do not register. **That is not an
error condition** — an install with no publicly reachable address simply polls instead, and polling
is unchanged by any of it: `app:mail:sync` runs every 15 minutes and `app:calendar:sync --stale`
every 15 minutes offset off the quarter hour. Push only makes mail and calendar changes arrive
first rather than late.

**The failure mode is a public URL with a trailing path or a trailing slash.** It is trimmed of a
trailing `/` when stored, and webhook URLs are built by appending a generated route path to it — so
anything else in it ends up in the middle of a callback address the provider will happily register
and never successfully call.

## `TRUSTED_PROXIES`

Symfony trusts `X-Forwarded-For`, `X-Forwarded-Proto`, `X-Forwarded-Host` and `X-Forwarded-Port`,
but only from the addresses listed here. The committed default covers loopback and the three RFC1918
ranges, which is right for a proxy on the same Docker network or the same LAN.

Get it wrong in the narrow direction — the proxy's address is not in the list — and Symfony
believes it is serving plain HTTP to whatever address the proxy connects from. Four things follow,
and none of them announces itself:

- **OAuth stops working.** The redirect URI handed to Google and Microsoft is generated as an
  absolute URL from the current request, so it comes out as `http://…/oauth/google/callback` and
  does not match what you registered. The provider refuses with a redirect-URI mismatch.
- **Login throttling counts everyone as one client.** The firewall limits five attempts per
  15 minutes, keyed on the credentials being tried and the client address; with every request
  appearing to come from the proxy, one person guessing passwords consumes an allowance the whole
  install shares.
- **Cookies lose their `secure` flag.** Remember-me is configured `secure: auto`, meaning "secure
  when the request is", and the Mercure subscriber cookie is minted from the request the same way.
  Over a connection Symfony believes is plain HTTP, neither is marked secure.
- **Generated absolute URLs are wrong**, including the share and booking links shown in
  Settings → Sharing, which are built with `ABSOLUTE_URL` from the request.

Get it wrong in the wide direction — trusting everything — and a client can forge `X-Forwarded-For`
and present itself as any address it likes, which defeats the per-address rate limits and puts a
chosen value into your logs.

**The failure mode is diagnosing this as an OAuth problem.** A redirect-URI mismatch names the URI,
and the `http://` in it is the entire clue.

## Mercure's public URL

The hub is proxied on the app's own origin: `frankenphp/Caddyfile` routes `/.well-known/mercure*`
to the `mercure` container, so the browser reaches the hub same-origin, the subscriber cookie is a
first-party cookie, and no CORS configuration exists anywhere.

Two variables, and they point in opposite directions:

- `MERCURE_URL` is where the **app** publishes, inside the Docker network:
  `http://mercure/.well-known/mercure`. It rarely changes.
- `MERCURE_PUBLIC_URL` is where the **browser** subscribes. It must be your public address plus
  `/.well-known/mercure`.

`config/bootstrap_generated_secrets.php` derives the second from `APP_PUBLIC_URL` so there is one
less thing to fill in — but **only when `MERCURE_PUBLIC_URL` is unset or empty**, and the stock
`compose.yaml` sets it unconditionally to `https://localhost/.well-known/mercure`. On a proxied
install, set it explicitly. `truenas.compose.yaml` leaves its own value blank precisely so the
derivation can happen.

The scheme matters as well as the host: Symfony derives the subscriber cookie's `secure` flag from
this URL, so an `https` value on a plain-HTTP install mints a cookie the browser will not send back.

If your hub genuinely lives on another domain, the subscriber cookie cannot be issued at all —
`MercureCookieSubscriber` logs that once at info and the page still renders. Live updates are simply
unavailable, which is the honest outcome rather than a 500.

**The failure mode is an app that works perfectly except that nothing ever updates on its own.** No
error is shown, because the page rendered fine; the stream just never opens. The topbar's
connection indicator is where this becomes visible.

## Proxy configuration itself

Caddy sets the forwarded headers automatically:

```caddy
mail.example.com {
	reverse_proxy plmail:80
}
```

nginx needs them spelled out, and needs one more thing — the Mercure stream is Server-Sent Events,
and a proxy that buffers responses will hold the events instead of passing them through:

```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-Host $host;
    proxy_set_header X-Forwarded-Port $server_port;
}

location /.well-known/mercure {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_buffering off;
    proxy_read_timeout 24h;
}
```

Attachments go through the same path, so a proxy body-size limit has to clear what PHP itself
accepts: `upload_max_filesize` is `25M` and `post_max_size` is `60M` in the image.

**The failure mode is a proxy body limit smaller than PHP's.** The upload is refused before Symfony
sees it, so the message the user gets comes from the proxy and says nothing about attachments.

## Webhooks that must reach you

If push is the point of putting plMail behind a proxy, these are the paths that have to arrive:

| Path | Who calls it | How it proves itself |
|---|---|---|
| `POST /gmail/push` | Google Cloud Pub/Sub | `?token=` matching `GMAIL_PUBSUB_VERIFICATION_TOKEN`, compared with `hash_equals`; **no token configured means everything is refused** |
| `POST /webhook/graph/notify` | Microsoft Graph, mail | `clientState` minted per subscription |
| `POST /webhook/graph/lifecycle` | Microsoft Graph | as above |
| `POST /webhook/graph/calendar` | Microsoft Graph, calendar events | as above |
| `POST /webhook/google/calendar` | Google Calendar watch channel | channel token in `X-Goog-Channel-Token` |

All five are `PUBLIC_ACCESS` in the firewall, because the caller is a provider holding no session.
Their addresses are built from `APP_PUBLIC_URL`, never from the incoming request — see
[Google](../providers/google.md) and [Microsoft](../providers/microsoft.md) for the console side,
and the [security model](../internals/security-model.md) for what each token is doing.

Google additionally will not deliver calendar notifications to an **unverified domain**: the
callback host has to be verified in the Cloud project that owns the OAuth client. Until then every
`events.watch` is refused, which is at least visible as a warning in the log at registration time.

**The failure mode is a proxy that only forwards `GET`.** Every one of these is a `POST`, and a
rule written for a read-only site drops them with a status the provider treats as a delivery
failure and retries with backoff.

## Things that bite

**`MERCURE_PUBLIC_URL` is not derived on the stock compose file.** The derivation from
`APP_PUBLIC_URL` only happens when the variable is unset or empty, and `compose.yaml` sets it to
`https://localhost/.well-known/mercure`. This is the single most likely reason live updates work in
development and not in production.

**Push registration failing is not an error state.** Registration is retried hourly by
`app:calendar:push` rather than being tied to the click that connected a calendar, so an install
whose address or domain verification is fixed starts pushing within the hour without anybody
re-subscribing anything.

**`APP_PUBLIC_URL` set in the environment beats the setup screen permanently.** That is the
intended behaviour for a deployment that manages its own configuration — but it also means an
operator who changes the address in the Compose file and expects the setup screen's value to matter
is editing the wrong one, and vice versa.

**A wrong `TRUSTED_PROXIES` looks like four unrelated bugs.** OAuth mismatches, everyone sharing a
login-attempt allowance, cookies without `secure`, and share links with the wrong scheme are all one
setting.

**Nothing here changes whether mail arrives.** Polling is the backstop and it is unconditional: 15
minutes for mail, 15 for connected calendars, every minute for snooze wake and reminders. An
install with no public address at all is a supported install; it is just never first to know.
