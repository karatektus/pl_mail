# Demo mode

A switch that turns a plMail install into a public demo: a mailbox anyone can look around, on a
server you own, with nothing real behind it.

It exists because plMail is hard to show. It is a mail client, so the interesting parts only happen
when there is mail — and a stranger cannot be asked to hand a demo server their IMAP password to
see them. Demo mode fills that gap with mail that is not anyone's, and gives the visitor a button
that makes more of it arrive.

> **Never set `APP_DEMO_MODE` on an install holding real mail.** It is instance-wide. With it on,
> mail sync stops, sending goes nowhere, and `/demo` hands anyone who asks a working session.

## What a visitor gets

Following `/demo` provisions a throwaway user, signs them in and drops them in an inbox:

- **A mailbox of their own.** One user per visitor, not one shared account. Two people arriving at
  once would otherwise be marking each other's mail read and reading each other's drafts, and the
  product would look broken while working perfectly.
- **Ten seeded threads**, with labels, contacts and dates — the same mailbox the README screenshots
  are taken against, so the demo and the pictures of it cannot drift apart.
- **A bar at the foot of the page** saying what this is, with a **Receive mail** button.
- **Two hours**, by default. After that `app:demo:reap` deletes the user and everything they own.

Nothing is asked of them: no address, no sign-up, no password. The login page grows a **Start the
demo** button, and the credentials form stays behind it for anyone coming back to a session they
were already given.

## Making mail arrive

The button walks a scripted queue — a reply, an invoice with a real attachment, a delayed train, an
HTML newsletter, a note from Mum — and wraps round at the end. Each one is a different part of the
product rather than the same mail six times: a button that delivers one canned message teaches a
visitor within two presses that it is fake.

Delivery goes through the same ingest pipeline a real IMAP sync uses, so what the visitor sees is
the real thing: the conversation threads, the category is decided, any filter they just built runs
against it, and it appears without a reload over Mercure.

## Sending

Compose works, and nothing leaves the building. `DemoMailSender` sits above every real sender in
the registry and claims every account while demo mode is on, so the SMTP sender is never asked —
"a demo cannot reach a relay" is a property of the wiring rather than a rule each sender has to
remember.

A few seconds after a visitor presses Send, a reply arrives from whoever they addressed, on the
thread they are still looking at. That round trip is the thing no amount of pre-seeded inbox can
show.

## What is switched off

| Off | Why |
|---|---|
| Adding, editing or deleting a mail account | A stranger typing their real address and app password into a box on a server they do not control has handed their mail to whoever runs it. There is no version of that form that is safe to show here. |
| OAuth connect, CalDAV connect, calendar subscribe | Same reason, plus these bounce through a real provider — leaving them open puts your client id in front of an authorisation screen naming a demo nobody vetted. |
| `app:mail:sync`, `app:push:renew`, `app:calendar:sync`, `app:calendar:push` | A demo's accounts point at documentation domains that resolve nowhere, so every run would be a worker spending its retry ladder on nothing. The scheduler still fires them; they return immediately. |

Everything else stays clickable on purpose. The instinct is to lock down everything that is not
mail, and it produces a demo where the 41 themes cannot be tried and the filter builder cannot be
opened — a tour of a product with the interesting doors locked.

## Running one

`compose.demo.yaml` is the overlay:

```bash
SERVER_NAME=demo.example.com docker compose -f compose.yaml -f compose.demo.yaml up -d --wait
```

It layers over `compose.yaml` rather than `compose.prod.yaml` — that one builds the image from
source, and a demo has no reason to.

The overlay exists rather than being a line in this page because `docker compose` reads the `.env`
beside it, which is Symfony's and says `APP_DEMO_MODE=0`. Passing the variable on the command line
works; writing `${APP_DEMO_MODE:-1}` into a compose file does not, and fails in the worst way — the
stack comes up healthy and simply is not a demo. So the overlay hardcodes it, and
`ComposeEnvironmentTest` keeps it that way.

It also parks `imap-supervisor`, which would otherwise spend the whole time failing to connect to
mailboxes a demo does not have.

The install still needs a first user before anything is reachable — `/install` or `app:setup`, as
on any other deployment. That user is yours, not a visitor's: the reaper only ever deletes
addresses matching `demo-…@plmail.invalid`, so an administrator signed into their own demo instance
is left alone.

Point people at `/demo` rather than the root. The root is the inbox, which for somebody with no
session is the login page.

### Keeping it clean

`app:demo:reap` runs every ten minutes on a demo instance and deletes visitors whose stamped expiry
has passed, along with their accounts, mail, labels, contacts and calendars. To see what it would
take without taking it:

```bash
docker compose exec php php bin/console app:demo:reap --dry-run
```

A user with no expiry stamp is left alone rather than treated as overdue — the safe direction for
the failure that matters, which is a reaper that deletes the administrator.

### Sizing it

Each visitor costs one user, one account, ten threads and ten contacts. Provisioning is rate
limited to 30 an hour per address, which is set for a room sharing one NAT address — an office, a
conference hall, a classroom — rather than for one visitor, who needs exactly one.

## The legal pages

A publicly hosted demo in Germany needs an Impressum (§ 5 TMG) and a privacy
notice, and the second is the one people skip: it is required even though this
instance asks for nothing, because a web server writes the caller's IP address
into a log and an IP address is personal data.

Both are served only in demo mode, both without a session, and both are linked
from the login page:

| Page | Needs |
|---|---|
| `/impressum` | `APP_DEMO_IMPRESSUM_NAME`, `APP_DEMO_IMPRESSUM_ADDRESS`, `APP_DEMO_IMPRESSUM_EMAIL` |
| `/datenschutz` | the three above, plus `APP_DEMO_PRIVACY_HOST` |

Unset, each renders a visible warning naming the missing variable rather than a
blank heading — a legal notice naming nobody looks compliant from a distance
and is not.

The retention period in the privacy notice is read from `APP_DEMO_TTL` rather
than written into the text, so tuning the one cannot leave the other claiming
something the reaper does not do.

Two things the software cannot know and you must check: that you hold a data
processing agreement with whoever runs the machine, and that the notice matches
what you actually run. It describes plMail's own behaviour accurately — the
three cookies it sets, and a content security policy that permits no third-party
connections — but it is a description, not legal advice.

## Things that bite

**`APP_DEMO_MODE` is instance-wide, not per-user.** There is no way to run a demo mailbox beside
real ones on the same install. That is deliberate — the promise is that nothing reaches the
network, and a per-account flag would move that promise into every outbound path individually,
where it holds until the first one that forgets to ask.

**Turning it on stops mail syncing, and nothing says so on the page.** The scheduler still fires
`app:mail:sync` every fifteen minutes and it returns immediately. If mail has quietly stopped
arriving on an install you thought was normal, check this variable first.

**Sent mail on a demo is not sent.** It lands in Sent and looks exactly like mail that left. This
matters if you use a demo instance to reproduce a bug and wonder why nobody received anything.

**A visitor's mailbox disappears after `APP_DEMO_TTL`.** Somebody who leaves a tab open over lunch
comes back to a session whose user no longer exists. They are sent to the login page, where the
Start the demo button gives them a new one — but anything they typed is gone.

**The reaper is matched on the address, not on a flag.** Only `demo-…@plmail.invalid` is ever
deleted. Renaming a demo user to something else strands them permanently; giving a real user an
address in that shape gets them deleted within ten minutes.

**Passing `APP_DEMO_MODE=1` to `docker compose` is not the same as setting it in `.env`.** The
first works; the second is read by compose *and* by Symfony, and `.env` is committed — so editing it
turns the demo on for your checkout too. Use the overlay.

**`/demo` creates rows on a GET.** It is the link people arrive on, so it has to work when pasted
into a chat window — but it means a crawler that follows it provisions a user. The rate limiter is
what bounds that, and expired users are reaped; there is nothing else stopping it.

## Related

- [Configuration reference](configuration.md) — `APP_DEMO_MODE` and `APP_DEMO_TTL` in the table
- [Docker Compose](docker.md) — the stack these variables go into
