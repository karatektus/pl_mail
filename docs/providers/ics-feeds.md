# ICS feeds

An ICS feed is a calendar published as a file at an address: a country's public holidays, a league's
fixture list, a colleague's availability, or the "secret address in iCal format" that Google and
Outlook hand out for a calendar they will not grant API access to. plMail can follow one, re-read it
in the background, and show it beside your own calendars.

This is the cheapest calendar connection there is. There is nothing to register, no administrator
step, no credential and no consent screen — one address is the whole configuration.

## Subscribing

**Settings → Calendars → Subscribe to an address**, or the same button from the calendar itself.

**Calendar address** is the only field that matters: the address the calendar is published at,
usually ending in `.ics`. A `webcal://` link works too — it is the same address under another name,
and plMail rewrites it before anything else sees it.

**Name** is optional. Left empty, the calendar names itself from the feed's own title, and failing
that from the file name in the address. A name already in use gains a number rather than being
refused, because two feeds you did not name are not a collision you should have to resolve.

There is no "which calendars?" step, because a feed *is* one calendar. Subscribing shows a single
row, which is the honest picture of what a published address offers.

The feed is fetched once, at that moment. If the address turns out not to be a calendar, nothing is
left behind — the connection is removed again rather than sitting in your settings as a permanently
broken row with nothing to correct but the address itself. Which is the most likely failure by some
distance: a **Subscribe** button copies a link to a *web page* about as often as it copies a link to
an `.ics`, and the two are indistinguishable until something tries to parse one.

## `webcal://` is https under another name

Apple registered the scheme; no client has ever spoken a protocol by that name. A `webcal://` URL is
fetched by replacing the scheme and doing an ordinary GET, and plMail maps it to **https**, not to
the http the original note implied. Two reasons, in order: every publisher still offering webcal
today also serves the same path over TLS, and mapping to http would run the address into the
plaintext refusal below — so a user pasting exactly the link their calendar gave them would be told
to ask their administrator for permission. `webcals://` exists in the wild too and means the same
thing.

Only the scheme is rewritten, not the rest of the URL. Feed addresses routinely carry a query string
with its own `://` inside a percent-encoded parameter, and a rewrite that matched anywhere would
mangle that instead.

## Read-only, always

A subscribed calendar cannot be edited here, and this is a fact about what an ICS feed is rather
than a setting somebody could change. The far end is a static file: there is no method that would
write to it and no server that would accept one. Every other calendar plMail connects to is asked
whether it accepts writes — CalDAV reads the collection's privileges, Google reads the access role —
because those remotes have an opinion. A file at a URL does not.

The practical consequence: you can see the events, they appear in your views, and the calendar is
marked read-only in the interface. Events cannot be added to it, and nothing you do here can ever
reach the publisher.

**The address is the credential.** For a published calendar there is nobody to authenticate as, so
anyone holding the address can read the calendar — which is exactly why Google and Outlook call
theirs a *secret* address. plMail warns about this on the subscribe form and does not render the
stored address back afterwards. Treat one like a password, and regenerate it at the publisher if it
gets out.

## How often it is re-read

Feeds are re-read by the scheduled calendar sweep, which runs every fifteen minutes and picks up any
calendar that has not been synced in the last fourteen. There is no push and there is nothing to
configure: an ICS feed has no notification mechanism to register for, so nothing about a public
address, a reverse proxy or a webhook applies here.

Re-reading a whole calendar every fifteen minutes sounds expensive and is not, because a feed has no
delta mechanism and HTTP has validators. plMail stores the `ETag` and `Last-Modified` of the last
successful read and sends them back as `If-None-Match` and `If-Modified-Since`, and an unchanged
calendar answers `304` with no body. That is the answer to almost every poll of a holiday calendar.

When the feed *has* changed, plMail re-reads it from scratch — two downloads for one actual change.
That is deliberate. A feed states what exists and says nothing about what was removed, so applying
it as a delta would keep every cancelled fixture forever; the full re-read is what makes a deletion
land.

To pull one immediately rather than waiting:

```bash
docker compose exec php php bin/console app:calendar:sync CALENDAR_ID
```

## Why some addresses are refused

plMail fetches this address server-side, on a schedule, from a container that can reach its own
database, its message broker and its workers. An address a user types is therefore checked before
anything requests it — the same guard that protects every other user-supplied server address, and
for the same reason.

Refused outright:

| | |
|---|---|
| A scheme that is not `http` or `https` | `ftp://`, or a bare `example.com/feed.ics` with no scheme at all |
| `http://` | unless `INTEGRATIONS_ALLOW_HTTP` is on |
| A username or password in the URL | it would be logged wherever the URL is |
| `localhost`, or any host ending `.localhost` | |
| Loopback, link-local and private ranges | `127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.0.0/16`, `100.64.0.0/10`, `0.0.0.0/8`, and the IPv6 equivalents `::1`, `fc00::/7`, `fe80::/10` |

The private-range block is the one that catches self-hosters, and it is the check that stops
somebody aiming plMail at its own database container or at a cloud metadata endpoint at
`169.254.169.254`. **A feed on your own LAN is refused until you allow it**, by naming the host in
`INTEGRATIONS_ALLOWED_HOSTS` — for example `nextcloud.lan` or `10.0.0.5` — and, if the server has no
certificate, by also setting `INTEGRATIONS_ALLOW_HTTP`. Both are in the
[configuration reference](../install/configuration.md).

Understand what each costs. `INTEGRATIONS_ALLOWED_HOSTS` exempts exactly the hosts you name and
nothing else, which is narrow and is the right tool for one feed on one internal machine.
`INTEGRATIONS_ALLOW_HTTP` is global: it makes every integration and every feed in the installation
able to use plaintext, so a credential can cross your network in the clear. It is off by default so
that sending one stays a deliberate choice rather than an accident.

**Redirects are re-checked, every hop.** plMail follows up to three, by hand, and puts every one
back through the same validation before fetching it. Letting the HTTP client chase them would mean a
perfectly public feed host answering `302 Location: http://169.254.169.254/…` turns the scheduled
poll into a read of the cloud metadata endpoint. The redirect limit itself covers the shapes that
actually occur — http to https, an apex to a `www` host, a short link.

**A feed larger than 8 MB is refused while it downloads**, not after. That is far past the point
where a feed is plausibly a calendar — a national holiday feed is under 60 KB, a fifteen-year
corporate room calendar a few hundred — and it exists so an address a user typed cannot kill a
worker with an unbounded response.

Once a feed is reachable, the far end can still say no, and plMail distinguishes what is worth
retrying:

| Answer | What plMail does |
|---|---|
| `401` | Gives up permanently. A feed has no sign-in; use the secret or public address instead. |
| `404`, `410` | Gives up permanently. There is no calendar there any more. |
| `429`, `503` | Backs off and retries, honouring `Retry-After` when it is given in seconds. |
| `403` | Retries. Unlike a CalDAV server there is no credential here to have been rejected, and a CDN in front of a feed answers 403 for a rate limit, a geo rule and a bot filter alike. |
| A dead host, a TLS error, a timeout | Retries. A publisher that is down comes back. |

## Things that bite

**The link the Subscribe button gave you may be a web page.** Publishers put a human-readable
calendar page and an `.ics` behind buttons that look the same. If subscribing fails with a message
about the address not holding a calendar, open it in a browser: if you get a page, the `.ics` is
somewhere else on it.

**A feed on your own network is refused, and that is the SSRF guard rather than a bug.** Name the
host in `INTEGRATIONS_ALLOWED_HOSTS`. Turning off the private-range block entirely is not an option
on purpose, and `INTEGRATIONS_ALLOW_HTTP` is a bigger hammer than most people need.

**The address is the credential and is not shown again.** Anyone holding it can read the calendar.
If a secret address leaks, regenerate it at the publisher — there is nothing to revoke on this side.

**A subscribed calendar cannot be edited, and no permission fixes that.** If you need to write to a
Google or Outlook calendar, connect the account itself rather than its published address; see
[Google](google.md) and [Microsoft](microsoft.md).

**Nothing pushes.** A change at the publisher appears here within fifteen minutes, and no amount of
reverse-proxy configuration makes it faster. This is the one calendar source where that is not a
degraded state.

**A feed with no `X-WR-CALNAME` names itself after its file.** `feiertage-deutschland` in your
sidebar is that, and typing a name when you subscribe is how to avoid it.

**Two downloads per change is expected.** A poll that finds the feed changed re-reads it from
scratch, because that is the only way a deletion in a published file can be detected at all.
