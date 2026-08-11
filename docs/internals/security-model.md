# Security model

Encryption at rest and the probe that refuses to boot without a usable key, the generated
secrets file, password and token storage, two-factor and remembered devices, app passwords,
the SSRF guard, and exactly what a public share or booking link can reach. The user-facing
side is [Security](../features/security.md); operational recovery is in
[Backup and restore](../install/backup-restore.md).

The rule that runs through all of it: **make the invariant structural where you can, and
documented where you cannot.** A unique constraint beats a comment saying not to insert
duplicates, a DTO with no title beats remembering to check a flag in a template, and a probe
that refuses to start beats a log line nobody reads.

## Encryption at rest

`App\Infrastructure\Encryption\Encryptor` is libsodium secretbox — XSalsa20-Poly1305, so
confidentiality *plus* integrity: a tampered ciphertext fails to open rather than decrypting
to garbage. A fresh random nonce is generated per encryption and prepended, which is why
encrypting the same value twice yields different output — correct, and the reason **these
columns cannot be searched or compared by value**.

The stored format is self-describing:

```
enc:v1:<base64(nonce || ciphertext)>
```

The `Encryptor::PREFIX` exists so `EncryptedStringType` can tell an encrypted value from a
legacy plaintext one, and so a future algorithm change can bump the version without ambiguity.

The key is validated on **first use, not in the constructor**. An image is built before the
install it will run has a key, and generation happens at container start — validating in the
constructor made `cache:clear` during the Docker build fail on a key that was never supposed
to exist yet. Nothing is weakened: both `encrypt()` and `decrypt()` go through the same
accessor, so a missing or malformed key still fails loudly at the moment it matters, and there
is no path that writes plaintext instead.

`App\Infrastructure\Doctrine\Type\EncryptedStringType` is the Doctrine type
(`type: EncryptedStringType::NAME`, i.e. `encrypted_string`). The column stays `TEXT`, so
adopting it needs no schema migration — only the values change shape. Doctrine instantiates
types through a static registry with no constructor injection, so the container hands the
`Encryptor` over at boot from `Kernel::boot()`, which is why that one service is declared
`public` in `config/services.yaml`.

**Legacy plaintext is passed through on read rather than throwing**, so an instance predating
encryption stays usable — you can still open the accounts page and delete or re-enter the
affected accounts. Nothing backfills them, and the docblock says why: the old values are
readable by definition, and rewriting them silently would suggest a guarantee that the backups
and WAL segments still holding the plaintext do not support.

A decrypt failure surfaces as a Doctrine `ConversionException` naming the column, which
matters when the cause is a changed `APP_ENCRYPTION_KEY` and every credential fails at once.

What goes through the type: mailbox passwords, OAuth refresh tokens, and `User::$totpSecret`.

### The key probe

`App\Infrastructure\Setup\EncryptionKeyProbe` runs once per container start and checks that
the key in force can open the credentials already in the database. Hydration **is** the check:
it loads one account with stored credentials, and `EncryptedStringType` decrypts on read.

It detects the two ways a generated-secret setup goes wrong, both otherwise silent:

1. A service is missing the volume the generated secrets live on, so it mints its own key and
   disagrees with the services that wrote the data.
2. `APP_ENCRYPTION_KEY` was set in the environment, that setting was dropped, and a fresh key
   was generated over the top.

Either keeps working right up until a sync worker tries to log into a mailbox — and re-saving
an account under the wrong key would overwrite data the right key could still have read.
Failing at startup costs one unreadable error message; not failing costs the credentials.

**It is fatal only when starting the server.** A console invocation warns and continues,
because refusing would block the very command that repairs the situation — `app:reset --full`.
That asymmetry is the clearest instance of the codebase's rule on refusing loudly: fail-fast
is applied where the failure is worse than the stop, not as a blanket.

A `DbalException` — no `account` table yet — is swallowed: a database that has not been
migrated has nothing to protect.

## The secrets file

`App\Infrastructure\Setup\GeneratedSecretsFile` is the one file per install holding what
nobody configured by hand, at `APP_SECRETS_FILE` (`var/secrets/generated.env` by default, on a
volume **every** service mounts).

`frankenphp/generate-secrets.sh` creates it and writes `APP_SECRET` and `APP_ENCRYPTION_KEY`
before PHP ever boots, because the kernel itself needs those two. Everything that *can* wait
until PHP is up is added by `app:secrets:init`, so there is still only one place to back up.
`config/bootstrap_generated_secrets.php`, loaded through Composer's `autoload.files`, makes
the values visible to PHP started any other way — `docker compose exec … bin/console` bypasses
the entrypoint entirely.

Writes take an `flock`, and `ensure()` **re-reads under the lock** because another service may
have written the value between the first read and acquiring it. Four services start from the
same image at once, and two of them generating the same secret independently is a failure that
shows up much later, as data one container can read and another cannot.

**Anything set explicitly wins.** Nothing is ever generated over the top of a supplied value —
which is also why `APP_SECRET`, `APP_ENCRYPTION_KEY`, `DATABASE_URL` and `MERCURE_JWT_SECRET`
are blank in the committed `.env`: `docker compose` resolves `${VAR}` from that file, so a
value there becomes a real environment variable in every container and switches generation off
for it.

`App\Infrastructure\Setup\DefaultSecretsGuard` covers the case generation cannot: a deployment
that carries an old compose file or the documented placeholders forward. It holds the literal
shipped values for `APP_SECRET`, `APP_ENCRYPTION_KEY` and `MERCURE_JWT_SECRET`, plus
`SHIPPED_DB_PASSWORD` (`!ChangeMe!`) which appears *inside* `DATABASE_URL` rather than being
the whole value. Those values work perfectly, which is exactly the problem — nothing fails,
and the install stays readable by anyone who has the repository. Checked in `prod` only;
development is expected to run on the committed values, because that is what they are for.

**The key cannot be rotated underneath a running stack.** The other services keep the old key
in process memory until they restart, so for a while half of them cannot read what the other
half writes. That is why `app:reset --full` leaves the secrets alone by default and
`--rotate-secrets` is a separate, loudly warned flag. `POSTGRES_PASSWORD` is never rotated at
all: Postgres was initialised with it and keeps its own copy.

## What is hashed, and with what

Three different answers, and the choice is deliberate each time.

| Secret | Storage | Why |
|---|---|---|
| User password | Symfony `auto` hasher (Argon2/bcrypt) | low-entropy, chosen by a human — key stretching is the whole point |
| App password (`ApiToken`) | SHA-256 of the secret | 32 bytes of CSPRNG; nothing to brute-force |
| Trusted-device cookie | SHA-256 | 32 bytes of CSPRNG |
| Share link / booking page token | SHA-256 | 32 bytes of CSPRNG |
| Device pairing code | SHA-256, as a cache key | 32 bytes of CSPRNG, dead in two minutes |
| TOTP recovery codes | SHA-256 digests, in a jsonb list | 64 bits of CSPRNG each |
| TOTP secret | `encrypted_string` — reversible | the server must be able to *use* it |

`App\Service\Calendar\Sharing\PublicLinkToken` states the reasoning for the CSPRNG cases most
completely: Argon2 exists to make a low-entropy secret expensive to guess, this secret has 256
bits of entropy and no guessing is happening, and **what is needed is a lookup on an indexed
column, which a deliberately slow hash cannot serve** — the digest is the `WHERE` clause, so
every public request would pay the work factor before it could find the row.

The TOTP secret is the exception that proves the shape: it is encrypted rather than hashed
because the server has to mint codes from it, so it belongs in the same bracket as a mailbox
password.

Two smaller details worth copying. `ApiToken::PREFIX` is `plmail_`, so the authenticator can
tell an app password from a JWT by inspection and a leaked one is greppable; and the token's
first few characters are kept in clear as `$hint`, so the settings list can show which
credential is which without being able to reconstruct it.

`User::$backupCodes` is reindexed on every write —
`set (array $codes) => array_values($codes)` — because `array_filter()` leaves holes and a
sparse PHP array encodes as `{"1":"…"}` rather than a list, which comes back the wrong shape.

## Firewalls

`config/packages/security.yaml` declares three: `dev`, `jmap` and `main`.

**`jmap` is stateless** and shares two credential types: long-lived app passwords, and
short-lived JWTs for a future first-party app. `App\Security\ApiTokenAuthenticator::supports()`
has to be exact about which requests are its own — **Basic always is** (JWT has no Basic form)
and **Bearer only when the credential carries the `plmail_` prefix**, since a JWT is base64url
and starts `ey`, so the two can never be confused. `App\Security\JwtBearerTokenExtractor`
decorates lexik's header extractor to keep the JWT authenticator from claiming (and then
failing) an app password.

Both shapes real clients send are accepted:

```
Authorization: Bearer plmail_xxx…                 (ltt.rs)
Authorization: Basic base64(email:plmail_xxx…)    (Sterna, and most IMAP-era clients)
```

The Basic username is **verified against the token's owner** rather than ignored — a client
sending the wrong address is told so, instead of silently operating as whoever the token
belongs to. `lastUsedAt` is rewritten at most every `LAST_USED_TTL_SECONDS` (300), because
clients poll constantly and every JMAP call would otherwise issue a write.

**`main` carries login throttling** at 5 attempts per 15 minutes. The per-username limit is
the one that matters; the global IP limit is the backstop for spraying one password across
many addresses and is deliberately looser, so a household behind one NAT address cannot lock
itself out. Remember-me is signature-based with a 60-day lifetime: no storage, and changing
the password invalidates every cookie issued for it.

### Access control, and the endpoints that cannot hold a session

`security.yaml`'s `access_control` list is annotated per rule, because every `PUBLIC_ACCESS`
entry is a decision:

| Path | Why it is public | What proves the caller |
|---|---|---|
| `/healthz` | Docker healthchecks and uptime monitors hold no session | nothing — it reports verdicts only |
| `/gmail/push` | Google Pub/Sub is the caller | shared secret |
| `/webhook/graph…` | Microsoft is the caller | `clientState` minted per subscription |
| `/webhook/google/calendar` | Google is the caller | channel token in `X-Goog-Channel-Token` |
| `/device/pair` | a device that could authenticate would not need to pair | the pairing code itself |
| `/share/…` | the recipient has no account by definition | the token in the path |
| `/book/…` | same | the token in the path, plus a rate limiter on the POST |
| `/install` | it creates the first administrator, so it cannot require one | `InstallGuard` |
| `/2fa` | the password is in and the second factor is not | `IS_AUTHENTICATED_2FA_IN_PROGRESS` |

`/healthz` answers verdicts and nothing else — no counts, no addresses, no version — because
anyone who can reach the port can read it. It returns 503 only when the database is down,
since that is the one failure where serving is impossible; a backed-up queue stays 200,
because mail is late rather than gone and restarting the container would not help. `HealthTest`
asserts that shape, so an addition that leaks something fails the suite.

`App\Service\Setup\InstallGuard` is one predicate — "are there zero users?" — and it is the
whole security story of `/install`. It throws `NotFoundHttpException` rather than redirecting
to the login page, because **a redirect confirms the endpoint exists and is merely closed**.

## Two-factor authentication

TOTP through `scheb/2fa`, on the `main` firewall only. `/jmap` is deliberately not covered:
app passwords exist precisely because an IMAP or JMAP client cannot present a six-digit code,
and the way to withdraw access there is the app password list.

`RememberMeToken` is listed in `scheb_2fa.yaml`'s `security_tokens`, and that line is
load-bearing: remember-me is a secret the browser stores, so by itself it is still one factor
— without it the 60-day cookie walks straight past the second factor and quietly undoes the
feature.

The TOTP parameters are hard-coded on `User` — `TOTP_ALGORITHM` SHA-1, `TOTP_PERIOD` 30,
`TOTP_DIGITS` 6 — because Google Authenticator ignores the `algorithm` and `digits` parameters
in the `otpauth://` URI and assumes exactly those regardless. Get it wrong and enrolment scans
cleanly, then rejects every code, with nothing on either side saying why;
`TwoFactorEnrolmentTest` generates real codes with otphp to prove the configuration written
into the QR and the one validated against have not drifted apart.

`leeway: 15` tolerates clock drift, which on a home server or a NAS is an ordinary Tuesday. It
**must stay below the period**: otphp throws `The leeway must be lower than the TOTP period`
for anything `>= 30` — not a narrower window, an exception on every verification.

`$totpSecret` and `$totpConfirmedAt` are separate on purpose, and both are `private(set)`.
Enrolment writes the secret first so the QR can be scanned, and a secret that has never been
confirmed must not lock anyone out — `isTotpAuthenticationEnabled()` requires both. The
`private(set)` is what stops a secret being swapped in without the confirmation state being
reset alongside it.

`App\Security\TwoFactor\TwoFactorThrottle` is the throttle the firewall does not provide. The
firewall's `login_throttling` stops at the password form; everything past it — the six-digit
code and the recovery codes — is what an attacker holding a stolen or phished password
actually reaches. Six digits is 10⁶ inside a window otphp widens to about a minute, which
unthrottled is a few hours of requests. `config/packages/rate_limiter.yaml` sets
`two_factor_code` to 5 per 15 minutes, sliding window, **keyed per user**: the secret being
guessed belongs to one account, and an IP key would let anyone sharing an address lock
everyone else out of theirs. Only failures consume tokens and a success clears the count, and
the refusal happens **before** the code is checked.

`app:user:2fa-disable` is console-only and deliberately not exposed to administrators through
the web UI. An admin who could strip another user's second factor from a browser would be a
second way into every mailbox on the install, reachable with nothing but a stolen admin
session.

### Remembered devices are rows, not cookies

scheb ships trusted devices already, and plMail replaces its manager with
`App\Security\TwoFactor\DatabaseTrustedDeviceManager`, wired through `trusted_device.manager`.

The stock one puts the whole grant in the cookie: a JWT holding a username and a version,
signed with `APP_SECRET`. Stateless and fast, and it **cannot be taken back** — a stolen
cookie stays valid for its full lifetime, and the only revocation on offer is bumping the
version, which drops every device the user owns at once.

Here the cookie is an opaque 32-byte secret and the grant is a row in `trusted_device`, so the
settings page can list what is trusted and revoking one takes effect on that device's very
next request. The cost is one indexed lookup per request on a 2FA-enabled account.

`TrustedDevice::$firewall` is stored because a grant issued for the session-backed web login
must not be honoured by anything else that later trusts this table. `$label` — "Firefox on
macOS" — is derived from the user agent at creation and **stored** rather than parsed on every
render: the point is to describe the device as it was when trusted, and a browser that has
since updated should not quietly relabel a row.

`App\Security\TwoFactor\TrustedDeviceCookieJar` is tagged `kernel.reset`, because the pending
cookie must not survive into the next request — under a worker runtime it would be written for
whoever asks next.

## App passwords and pairing

`App\Entity\User\ApiToken` is scoped to the **User**, not to an Account, matching the JMAP
Session object: one credential enumerates every connected mail account. The secret is shown
exactly once at creation and only its digest is stored, with
`uniq_api_token_hash` making the lookup an indexed equality test.

`App\Service\User\DevicePairingService` exists because the alternative is 71 characters of
base16 copied off one screen onto a phone keyboard — the worst moment in onboarding and the
one most likely to be got wrong.

**The QR never contains the app password.** It carries a short-lived single-use pairing code
which the app exchanges for a freshly minted one. That matters because a QR on a laptop screen
is a thing people photograph, screen-share and walk away from: a code dead in
`TTL_SECONDS` (120), and dead immediately once used, cannot hand anybody a permanent key to a
mailbox. Embedding the password would.

Codes live in the cache rather than a table, keyed by `hash('sha256', $code)` rather than by
the code — there is a test named `testTheCacheKeyIsADigestNotTheCodeItself`. A two-minute
single-use secret has no business surviving a restart: losing them on deploy costs one rescan,
and a table would need a migration, an index and a sweeper for data whose whole lifetime is
shorter than a deploy.

`CODE_BYTES` is 32, and that is why `/device/pair` needs no lockout of its own: there is
nothing to brute-force.

## The SSRF guard

Self-hosted integrations let the user name their own server, which means an authenticated user
can aim the outbound HTTP client wherever they like — a real SSRF surface here, because the app
runs in a container network alongside Postgres, Mercure and the workers.

`App\Service\Integration\IntegrationUrlValidator` has three defences, in order of strength:

1. **An admin who pins `baseUrl` on the provider config removes the surface entirely.**
   `resolve()` ignores the user's value when one is pinned, so a stale row from before the pin
   cannot keep reaching elsewhere.
2. **`http://` is refused unless `INTEGRATIONS_ALLOW_HTTP` is on.** Self-hosting on a LAN is
   the normal case for Nextcloud and Immich, so this flag will often be set — the point is that
   plaintext credentials over the wire become a deliberate admin decision rather than a silent
   default.
3. **Loopback, link-local and private ranges are refused** unless the host appears in
   `INTEGRATIONS_ALLOWED_HOSTS`. `BLOCKED_RANGES` covers `127.0.0.0/8`, the three RFC1918
   ranges, `169.254.0.0/16` (link-local, including the cloud metadata endpoint at
   169.254.169.254), `100.64.0.0/10` and `0.0.0.0/8`; IPv6 is checked separately for `::1`,
   `fc00::/7` and `fe80::/10`, including bracketed literals.

Credentials in the URL are refused outright, because they would be logged wherever the URL is
and would silently override the ones on the connection.

**It is deliberately not a full DNS-rebinding defence**, and the docblock says so: a hostname
resolving to a private address at connect time still gets through. The allow-list is the honest
mitigation, and admins pinning `baseUrl` sidestep the question.

> Correction, and a note for whoever hardens this next. This section used to add that closing
> the rebinding hole "needs pinning the resolved IP into the HTTP client, which Symfony's client
> does not expose". That is not true — `HttpClientInterface`'s `resolve` option does exactly
> that, and `ImageProxyFetcher` (below) uses it. What kept the integration validator from
> pinning is not the client; it is that the validator only inspects a URL and never makes the
> request, so it has no request to pin. Moving the check next to the call is the work, and it
> is work nobody has done yet.

The same shape appears in `App\Service\Calendar\Sync\IcsUrl\IcsUrlNormaliser` for subscribed
feeds — see [ICS feeds](../providers/ics-feeds.md) for which addresses are refused and why —
and in `App\Service\Calendar\Push\PushCallbackUrl` in the opposite direction, refusing to hand
Google or Microsoft a callback that is not HTTPS or that names a loopback host.

## What a public link can reach

Two features share one mechanism: a **share link** shows part of a calendar to somebody with
no account, and a **booking page** lets them take an hour of it. Both are gated by a token in
the URL and nothing else. See [Sharing and booking](../features/calendar-sharing.md).

### The token is a credential and is not stored

`calendar_share_link.token_digest` and `booking_page.token_digest` hold a SHA-256, never the
token. `PublicLinkToken::mint()` produces URL-safe base64 rather than hex, so a token is 43
characters instead of 64 and survives being pasted into a chat window, an email client that
wraps long lines, and a QR code. `ROUTE_PATTERN` is declared on the same class rather than
written into two route attributes — it is the alphabet `mint()` produces, and a requirement
that disagreed with it would 404 a freshly minted link rather than fail anywhere a test would
look. Its length bound is what stops a multi-kilobyte path reaching the hash.

`digest()` takes **any** string, including a hostile one, because that is exactly what the
public routes hand it. Hashing rather than validating is what makes that safe: an
attacker-supplied path becomes 64 hex characters before it reaches a query, so there is no
shape of input that is not a digest by the time it is compared.

**The cost is that the address is shown exactly once.** No screen can show it again, because
nothing stored can reconstruct it. The recovery is "regenerate", which mints a new token and
makes the old URL 404 — the right thing to do with a URL that went missing anyway. This is the
first thing somebody will try to "fix"; the second is adding a copy button to the row, which
cannot work.

### The redaction is a DTO

`App\Service\Calendar\Sharing\ShareLinkReader` does two jobs that must not be separated,
because separating them is how a leak gets written: resolving the link, and building the
redacted view. **Nothing else in the application may hand a public template a
`CalendarEvent`**, and the way that is enforced is that this class is the only route from a
token to anything renderable, and what it returns carries no events at all.

`App\Domain\DTO\Calendar\SharedOccurrence` **is** the redaction. The obvious alternative —
handing the template the occurrence and checking `link.reveals(...)` beside every field — is
the one that leaks: a template that forgets a check leaks, a partial that grows a `title`
attribute for a tooltip leaks, a JSON payload assembled for a Stimulus controller leaks, an
`.ics` built from the event leaks. None of those can happen, because the concrete data is not
in the object the renderer can reach. `SharedCalendarLeakTest` asserts it over the whole
response body.

`$uid` on that DTO is **synthetic and is not the event's**. A real UID identifies a meeting
across every calendar and mailbox that holds it — that is exactly what `EventCopyResolver`
relies on — so publishing it would let anybody holding a busy/free link correlate the owner's
diary with an invitation they had already received.

A revoked link, an unknown token and a malformed one all answer null and the controller makes
one 404 out of all three. Distinguishing them would confirm which tokens had once been real.

### Privacy is the ceiling, the ticks are the floor

`App\Domain\Enum\Calendar\ShareDetail` is the list of what a link may add on top of busy/free
— `Title`, `Location`, `Description`, `Participants` — stored as a jsonb list so a link can be
narrowed afterwards without being re-sent. There is deliberately **no `BusyFree` case**: it is
the absence of every case, and a case for it would make the empty set expressible twice. An
unknown value read back from an older or newer install is dropped by `tryFrom()`, which is the
safe direction — a detail plMail does not recognise stays hidden.

`App\Domain\Enum\Calendar\EventPrivacy` is the ceiling, and both halves are asked in the
reader rather than in a template, because a template that forgets is a template that leaks and
there is no test that notices a missing `if`:

- `isShareable()` — `Secret` answers **false** and does not appear at all, not even as an
  anonymous busy block. Its existence is the detail: a block appearing on a Tuesday afternoon
  is what somebody reading the link would act on. The cost is accepted and stated — a calendar
  containing secret events says the owner is free at hours they are not, so the link can be
  used to book over one. That is the trade the word "secret" asks for.
- `mayRevealDetail()` — `Private` shows as a busy block whatever the link's checkboxes say.
  The link is a decision about an audience and the privacy is a decision about one meeting, so
  the narrower wins; otherwise the wider is a way to undo it in bulk.

Both are exhaustive matches with no `default`, so a fourth privacy cannot inherit whichever
answer happened to be last.

The window is resolved in the **owner's** zone, always, never the reader's — a rolling
fortnight means a fortnight of the owner's days, and resolving it in the visitor's zone would
make the same link cover different days depending on where it was opened. The rolling count is
clamped rather than trusted: the form bounds it on the way in, but this is the read side and it
walks days, so a row edited by anything other than that form must not turn one public GET into
a decade of date arithmetic. `MAX_ENTRIES` (2000) bounds the query rather than the loop, so
memory is bounded too, and the page says when it has been reached rather than silently ending
early.

Cancelled occurrences are dropped for a different reason worth separating: a called-off meeting
is not a claim on the owner's time, so leaving it in would make a shared calendar say "busy" at
an hour the owner is free.

### Booking

**Double-booking is stopped by `uniq_calendar_booking_page_start` and by nothing else.** Two
people pressing Book on the same half hour at the same instant both read the slot free, because
both read before either wrote; narrowing that window makes the bug rarer, which is the worst
property a bug of this kind can have. The database is the only participant that sees both
requests.

The constraint is on `(booking_page_id, starts_at)` — a **denormalised start on the booking
row**, not a join to the event. That is what makes it enforceable at all, and it is why
dragging a booked meeting to another day cannot free somebody else's hour: the claim lives on
the booking, so moving the event does not release the slot. The page id leads because every
read is scoped to one page and because two pages offering the same hour is a thing the owner
may legitimately do — it is their diary, through `BookingPage::calendarsToCheck()`, that stops
that, not this index. `starts_at` second so the index also serves "which slots in this window
are taken", the query the availability reader makes on every public GET.
`uniq_calendar_booking_event` is the other direction: one booking per event, because the event
is created by the booking, so a second booking pointing at it would be a bug here rather than a
race between strangers.

`App\Service\Calendar\Booking\BookingService` is written around the refusal rather than around
a check. **The event and the booking go in one flush**, so the constraint's rejection takes the
event with it and the whole booking happens or none of it does — claiming the slot first would
leave a claimed hour with no meeting in it, and writing the meeting first would leave the
loser's meeting on the owner's calendar with nothing pointing at it. Doctrine closes the
EntityManager on a failed flush, which is why this **throws** rather than returning a verdict:
an exception is the one signal a controller cannot accidentally continue past. The controller
answers with a redirect, so the re-offered slot list is built by a fresh request with a fresh
manager.

`BookingPage::calendarsToCheck()` always includes the destination calendar, ticked or not, and
that lives on the entity rather than in the reader because it is an invariant of the page: a
page whose destination was not in its own busy set would double-book itself on the second
request.

The booker's own details live on `CalendarBooking`, **not** on the event, and deliberately not
as `participants` — pushing an attendee list to a provider is how the provider decides to mail
a stranger a meeting request they did not ask for. The event carries
`EventSource::Booking`, which is the only kind of event a person outside the install can cause
to appear, and "which of these did I not put here?" is a question somebody will ask the first
time a page is abused — a query can only answer it if it was written down at the time.
`EventSource::mayBeRewrittenByMail()` answers false for it: a reconciler that could rewrite a
booking would turn the booking page into a way to edit the owner's calendar by email
afterwards.

**The public POST is rate limited and the GET is not.** `booking_attempt` is 6 per hour, fixed
window, keyed per IP — the opposite of the two-factor limiter's per-user key, and for the
opposite reason: there is no account being attacked, so the caller's address is the only handle
on "whoever is filling this diary". A fixed window rather than sliding because the counter is
per address on a public endpoint and the sliding policy keeps a timestamp per hit where this
keeps an integer, which matters when the thing being rate-limited is also the thing that can be
fired at the cache. The GET is deliberately unbounded: a limit there would let one stranger
take a published page off the internet by refreshing it.

**The public pages have their own layout and it must stay that way.**
`templates/sharing/_layout.html.twig` does not extend the app layout, because that one renders
`csrf_token('ajax')` into a meta tag — which starts a session, on every fetch of a public URL,
forever. There is consequently no CSRF token on the booking form, and
`App\Controller\Sharing\BookingController` argues why that is the right trade.

## Rendering somebody else's HTML

A message body is markup written by a stranger, and plMail puts it on screen. Three separate
things had to be true before that is safe, and only one of them was.

### Remote images are blocked, and opting in still does not expose the reader

An `<img src="https://sender.example/open.gif">` in an email is a read receipt. It fires the
moment the message is opened and tells the sender the time, the reader's IP address, their
user agent, and — with a per-recipient URL — exactly which recipient it was. A 526×5 "spacer"
is the usual disguise.

`App\Service\Mail\RemoteContentBlocker` rewrites the body on the way to the template. Absolute
`http(s)` and protocol-relative `img` sources, and remote `url()` references in inline styles
— which is where `MailBodySanitizer`'s CSS flattening puts every `<style>` rule — lose their
reference to a transparent placeholder, and the proxy URL is parked on `data-plmail-src` for
the un-block. `cid:` inline images were resolved to our own attachment route at ingest and are
left alone; so are `data:` URIs, which make no request.

**It runs at render time, not at ingest**, for two reasons that are worth keeping: every
message already in the database was sanitized before this existed, so an ingest-time block
would protect only future mail; and the answer depends on *who is asking*, because
"always show images from this sender" is per user (`trusted_image_sender`). A stored form
cannot carry an answer that varies by reader.

When the reader does opt in, the images load **through `/mail/image-proxy`** rather than
directly — so an opted-in read leaks no IP either. That is the whole reason there is a proxy
and not just an unblock.

### The image proxy's rules

`App\Service\Mail\ImageProxyFetcher` is a service that connects wherever a stranger's email
tells it to, from inside the container network. Its rules, in the order applied:

1. **https only.** An http fetch is a cleartext request our server makes on a schedule the
   sender chooses.
2. **Port 443 only.** A URL is otherwise a fine way to ask a server to port-scan its own
   network; pinning the port removes the class rather than blacklisting the interesting ports.
3. **Every address the host resolves to must be public**, and the connection is then **pinned**
   to a checked address through the client's `resolve` option. Checking and letting the client
   resolve again is the rebinding hole. Refused: RFC1918, loopback, link-local (including
   169.254.169.254), IPv6 ULA and link-local, `100.64.0.0/10`, and IPv4-mapped IPv6 such as
   `::ffff:127.0.0.1`. Names are refused before the resolver for `localhost`, `.internal`,
   `.local`, and any **bare label** — which is how services are named on a container network.
4. **Redirects are followed by hand**, at most three, each hop re-running rules 1–3.
   `max_redirects` in the client follows them past every check, and a public host redirecting
   to the metadata endpoint is the standard exfiltration.
5. **The response must be an image and must stop at 8 MB.**

Referer and Cookie are never sent, because the request is built from scratch. Every failure
answers with the same transparent GIF: distinguishing "refused" from "timed out" against an
internal address is itself the port-scan result rule 2 exists to deny.

The signed URL is **not** the SSRF defence and the docblock says so — anyone with an account
can mail themselves a link and be handed a valid signature. The signature proves the parameter
was not typed by hand; the address checks are what constrain where we connect.

This is deliberately **not** `IntegrationUrlValidator`. That one has an allow-list and an
admin-pinned base URL because a user is naming their own Nextcloud on a LAN; importing its
escape hatches here would mean an admin allowing a LAN host for Immich had also allowed every
sender's mail to reach it.

### The body renders in a sandboxed frame

The sanitized body used to be injected into the app's own DOM. The sanitizer held — but "no
gaps, forever, against every sender" is a claim that has to keep being true, and the cost of
it being wrong once was a same-origin script with the session cookie.

`templates/mail/_message_body.html.twig` renders it into an `<iframe srcdoc>` with
`sandbox="allow-popups allow-popups-to-escape-sandbox allow-scripts"`. The security comes from
what is **absent**: no `allow-same-origin` (an opaque origin — no session cookie, no storage,
no reach into the parent), no `allow-forms`, no `allow-top-navigation`, no `allow-modals`, no
`allow-downloads`. `allow-scripts` is present because height is a fact only the framed document
can measure and a frame without `allow-same-origin` cannot be measured from outside; it is paid
for by the frame's own `<meta>` CSP, which restricts `script-src` to a **per-render nonce** that
our ~30 lines of height-and-hover reporting carry and no markup arriving in an email can.

The CSP's `img-src` names our own origin and `data:` explicitly — not `'self'`, which in an
opaque origin matches nothing. So a remote image the blocker somehow missed is refused by the
browser too: both layers have to fail for a pixel to fire.

**Every warning is drawn outside the frame.** A banner inside the sandbox is a banner mail
markup can paint for itself, and a "Show images" button a message can forge is worse than no
button. The same goes for the hovered-link preview: detected inside the frame, where the links
are, and drawn by the parent, so a message cannot paint a status bar claiming a different
destination.

The print view (`templates/mail/print.html.twig`) is **not** framed, because a print dialog
aimed at a page whose content is in a frame paginates the frame's scrollbox. It buys the same
two properties differently: the same CSP, and no application DOM on the page for mail CSS to
collide with. It blocks remote images on the same allowlist — printing is a read.

### The phishing heuristic, and exactly what sets it off

`App\Service\Mail\SenderIdentityChecker` — two rules, no scoring, no model, no threshold.

- **Rule 1, DomainInName.** The display name contains something shaped like a hostname whose
  registrable domain differs from the From address's.
- **Rule 2, BrandInName.** Runs *only* when the display name carries a legal form as a whole
  word (GmbH, Inc, Ltd, AG, B.V., …), which is what distinguishes an organisation from a
  person. Then: words of 4+ letters, minus the legal form and generic corporate vocabulary
  (Online, Group, Services, Deutschland, …); if at least one survives and **none** occurs in
  the sender's registrable domain with punctuation stripped, the names disagree.

Neither runs when Authentication-Results shows **DKIM passing for the sender's own registrable
domain** — a domain that signed the message may put what it likes in its display name.

A display name without a legal form is never judged, which is what keeps "Jane Cooper"
&lt;jane@gmail.com&gt; quiet. Substring matching is generous on purpose: "PayPal Inc."
&lt;service@paypal-secure.example&gt; is a **known, tested miss**, because catching it means
edit-distance scoring against a brand list and every false positive that bought would be spent
on ordinary mail. A warning that fires on ordinary mail trains the reflex it exists to
interrupt.

The Spam banner is separate and needs no heuristic at all: the message carries a label whose
role is Spam. A trusted sender does **not** get images inside Spam — the allowlist records a
belief about a sender, and a message in Spam is the provider disagreeing about whether this is
really that sender.

## Things that bite

**Adding a service to compose without the `app_secrets` volume mints it a second encryption
key.** It works until it does not, and the probe is what turns that into a refusal at boot
rather than credentials written under a key half the fleet cannot read.

**Rotating `APP_ENCRYPTION_KEY` on a running stack corrupts data in both directions.** The
processes already running keep the old key in memory. `--rotate-secrets` requires restarting
everything immediately afterwards, and there is no recovery from a lost key other than putting
the original back.

**An `encrypted_string` column cannot be queried by value.** The nonce is per encryption, so
two encryptions of the same plaintext differ. Any feature that wants to look one up needs a
digest column beside it, which is what `ApiToken`, `TrustedDevice` and the share tokens all do.

**A public route added under `/share/` or `/book/` inherits `PUBLIC_ACCESS`.** The prefixes are
matched by pattern, so a new action there is anonymous whether or not that was intended, and
the token in the path is the only gate.

**Handing a public template anything other than a `SharedOccurrence` defeats the redaction.**
The property "a busy/free link cannot leak a title" holds because the object being rendered has
not got one; a convenience that passes the event through, even for one field, re-opens every
path at once.

**A cancelled-booking flag plus a plain unique index is a slot nobody can ever rebook.** The
entity says this explicitly: adding `cancelled_at` later means making the index partial —
`WHERE cancelled_at IS NULL` — and that is the sentence to remember.

**Extending the login throttle does not cover the code form**, and extending the code throttle
does not cover the password form. They are two limiters with two keying strategies for two
different attacks, and each one's docblock names the other so neither is mistaken for complete
coverage.

**`InstallGuard` is the only thing between `/install` and an anonymous administrator.** The
route is `PUBLIC_ACCESS` by necessity, and `security.yaml` says in as many words to read that
class before touching the line.

**`Dom\HTMLDocument::createFromString()` throws on a flag it does not know.** Its allowed set
is `LIBXML_NOERROR`, `LIBXML_COMPACT`, `LIBXML_HTML_NOIMPLIED`, `Dom\HTML_NO_DEFAULT_NS` —
passing `LIBXML_NOWARNING` alongside them, which is habit from `DOMDocument`, is a `ValueError`.
`RemoteContentBlocker` fails closed, so the symptom was every message rendering as plain text:
perfectly private and completely unreadable. It also needs the `'UTF-8'` override, because the
sanitized body has no `<meta charset>` — `MailBodySanitizer` drops `<head>`.

**A test that greps for "did the browser contact the sender" must match on the hostname, not
on the URL.** The proxy carries the sender's URL inside its own query string, so the obvious
Playwright glob `**://sender.example/**` matches every *correctly proxied* load and reports a
leak at the exact moment the feature is working. `tests/e2e/rendering-security.spec.ts` uses a
`(url) => url.hostname === …` predicate and says why.

**Anything that renders `bodyHtmlSafe` must go through `message_render()`.** The Twig function
exists so no template has to ask a security question, because the failure mode of forgetting is
silent: the mail renders perfectly and the tracking pixels fire.
