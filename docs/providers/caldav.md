# CalDAV

CalDAV is the standard protocol for calendars on a server, and plMail speaks it to any server that
implements RFC 4791: Nextcloud, Radicale, Baïkal, Fastmail, iCloud, a Synology box in a cupboard.
There is nothing to register with anybody and no administrator step — a user connects a server from
their own settings with an address, a username and a password.

**Gmail and Outlook calendars do not come in this way.** They arrive with the mail account, on the
grant it already holds, and need only the calendar permission ticked on the app registration — see
[Google](google.md) and [Microsoft](microsoft.md). plMail's own connect screen says so, because
pointing it at Google's CalDAV endpoint is the first thing somebody tries.

What happens after a server is connected — ticking which calendars to mirror, two-way sync,
read-only calendars — is in [Connected calendars](../features/calendar-sync.md).

## Connecting a server

**Settings → Calendars → Connect a CalDAV server.** Four fields, in the order the questions actually
arise:

**Server address.** The CalDAV address your server shows you, or just its domain. Both work, and
the difference is only how many requests the discovery takes. Nextcloud shows the address under
**Calendar → Settings → "Copy primary CalDAV address"**, and it usually ends in `/remote.php/dav`.
It is emphatically *not* the address of the web interface, which is the mistake the error message is
written for: *"Enter the CalDAV address your server shows you — often something like
`…/remote.php/dav` — rather than the address of its web interface."*

**Name.** What to call this connection in your settings. Only you see it.

**Username.** The account name the server knows you by. On some servers this is your email address
and on others it is a short login name; CalDAV has no opinion, so this is whatever the server's own
documentation says.

**App password.** Create one on your server rather than using your login password. App passwords are
individually revocable and work alongside two-factor authentication, and iCloud and Fastmail accept
nothing else. Where to make one:

| Server | Where |
|---|---|
| Nextcloud | Settings → Security → Devices & sessions |
| iCloud | appleid.apple.com |
| Fastmail | Settings → Password & Security |

There is a fifth control, **"Use the password from one of my mail accounts"**, and it is off by
default and stays off unless you tick it deliberately. It exists for the case where the CalDAV
server and a mailbox are the same account — a Fastmail or a self-hosted setup — and it is not the
default for a reason worth reading before ticking it: the address above was typed by you and checked
against nothing, so sending a password you gave plMail for your mailbox to it is a decision, not an
assumption. Most servers want an app-specific password anyway.

Saving connects immediately and then offers the calendars it found, so a wrong address or a rejected
password is reported on the form rather than discovered later.

## Discovery, and the `.well-known` dance

plMail finds your calendars the way RFC 6764 says to, with one deliberate deviation.

The address you pasted is tried **first**, exactly as given, whenever it names anything more specific
than a bare host. This is the deviation, and it matters because what people paste is usually not a
domain: every calendar client shows a "CalDAV URL" somewhere, and depending on the client it is the
server root, the principal, the calendar home, or one single calendar. All four turn up here, and
trying `.well-known` first would mean a correct, specific URL being replaced by whatever the
server's front page redirects to — which on shared hosting is somebody's marketing site.

Only when the pasted address teaches plMail nothing does the standard bootstrap run:

1. `PROPFIND` on `/.well-known/caldav` at the address's origin, following where it points.
2. Read `current-user-principal` from the answer, and `PROPFIND` that for `calendar-home-set`.
3. List the home.

Each probe asks for `resourcetype`, `displayname`, `current-user-principal` and `calendar-home-set`
together, because a server that can answer all four in one round trip saves two — and most can. If
`.well-known` is not served either, the origin itself is tried last: a server that neither serves
the well-known URI nor was given a usable path can still answer the bootstrap at its root, and that
is one request rather than a support ticket.

Redirects are followed by hand, up to three hops, and every hop is re-validated before it is
requested. A redirect target is a URL the *server* chose, and following one into a private address
is the entire SSRF attack, so the check is repeated rather than trusted for having come from a host
that passed it.

A `403`, `404`, `405` or `501` at any step means "nothing here, try the next address" — plenty of
servers refuse a `PROPFIND` on the web root while serving CalDAV perfectly at `/dav`. A **401** is
treated differently and deliberately: that one really is your credentials, and it surfaces as itself
rather than being reported three steps later as "no calendar service found".

## Addresses plMail will refuse

A CalDAV address is something a user types, and plMail fetches it server-side from a container that
sits on the same network as its own database and workers. So the address goes through the same guard
as every other user-supplied server address:

- The scheme must be `http` or `https`.
- `http://` is refused unless `INTEGRATIONS_ALLOW_HTTP` is on.
- A username or password embedded in the URL is refused outright — it would be logged wherever the
  URL is, and would silently override the credentials on the connection.
- Anything resolving into loopback, link-local or a private range is refused unless the host is
  listed in `INTEGRATIONS_ALLOWED_HOSTS`.

That last rule is the one a self-hoster meets. A Nextcloud on your LAN at `192.168.1.10` or
`nextcloud.lan` is refused until you allow it, and a Nextcloud with no certificate needs the http
flag as well. Both are in the [configuration reference](../install/configuration.md), and both cost
something: the private-range block is what stops a user pointing plMail at its own database
container or at a cloud metadata endpoint, and allowing plain http means an app password crossing
your network in the clear. Naming one host in `INTEGRATIONS_ALLOWED_HOSTS` is much narrower than
turning the block off, which is why it is a list rather than a switch.

## Sync, and what has actually been tested

There is no push for CalDAV. The protocol has no notification mechanism plMail could register for,
so connected calendars are read by the scheduled sweep every fifteen minutes — which is also the
only mechanism, rather than a fallback, and is why nothing on this page mentions a public address or
a callback URL.

Changes are read one of two ways, decided by asking the server rather than by knowing which server
it is:

**`sync-collection` (RFC 6578) where the server advertises it.** One report carrying the stored
token comes back with what changed and a `404` status for each thing removed. It is the only
mechanism here that can express a deletion incrementally, so it is preferred wherever it exists.

**`getctag` plus a calendar query where it does not**, which is not a rare fallback — Radicale, older
Baïkal and several appliance servers advertise no `sync-collection` at all. The ctag is one value
for the whole collection: unchanged means nothing anywhere has changed, which is the answer to most
polls and costs one request. When it *has* moved, plMail re-reads the calendar from scratch rather
than trusting the listing, because a calendar query says what exists and nothing about what was
deleted.

Whether a calendar accepts changes is asked too, from `current-user-privilege-set`, rather than
assumed. A collection you can only read is mirrored read-only and marked as such in the interface.

**There are no per-vendor branches anywhere in the CalDAV driver.** That is the design, and it is
what "tested" means here: capabilities are probed, not looked up, so a server nobody involved has
ever heard of works the day it is pointed at. The servers named in the code as the intended targets
are Nextcloud, Radicale, Baïkal, Fastmail, iCloud and Synology. The automated test suite covers the
protocol behaviours rather than the products — both change-reading mechanisms, the discovery shapes
including the Radicale-style collection with no `displayname`, and the paged and truncated responses
— which is the level at which servers actually differ.

Every request identifies itself as `plMail-CalDAV/1.0`. That is not decoration: several servers,
Radicale among them, refuse or mishandle requests from a client that does not name itself, and a
support thread about a failing sync starts with the server's access log.

## Things that bite

**The web interface address is not the CalDAV address.** `https://cloud.example.com` happens to work
because plMail will bootstrap from the domain, but the URL you copied out of a browser tab while
looking at your calendar almost certainly does not. Use the address the server offers for copying.

**A 401 is your credentials and is reported as such**, but a 403 on the web root is not — plMail
treats it as "not here" and keeps looking. So "no calendar service answered" can mean a genuinely
wrong path *or* a server that only serves CalDAV under a subpath you have not given it.

**A LAN server is refused by default.** This is the SSRF guard doing its job, not a bug. Add the
host to `INTEGRATIONS_ALLOWED_HOSTS`, and add `INTEGRATIONS_ALLOW_HTTP` only if the server really has
no certificate.

**Reusing a mail account's password sends it to an address you typed.** The tick box is off by
default for that reason. If the server would accept an app password, use one.

**iCloud and Fastmail reject login passwords outright.** An app-specific password is not optional
there, and the refusal looks identical to a typo.

**There is no push, so a change made elsewhere takes up to fifteen minutes to appear.** That is the
design rather than a degraded state; nothing about a public address or a reverse proxy changes it.

**Disconnecting a server removes every calendar mirrored from it.** Events that came from the server
are copies and are gone with it; events that were never on the server — anything plMail extracted
from your mail onto one of those calendars — are moved to your default calendar first rather than
deleted.
