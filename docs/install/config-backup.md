# Configuration backup

An installation's *configuration* is not the same thing as its *data*, and the two are lost in
different ways. Losing the data is a disaster; losing the configuration is a Tuesday afternoon spent
finding out where a Firebase key went, which Google project the OAuth client belonged to, and what
`APP_PUBLIC_URL` used to be. [Backup and restore](backup-restore.md) covers the data. This page
covers the other one: **Admin → Backup**, which puts every setting and credential this installation
runs on into a single password-encrypted file, and takes one back.

Not one message, contact or calendar entry is in it. The **people** are, though — each with their
password, their second factor, their mailboxes and everything else they configured — because a
backup that cannot name its own operator is a backup of a server rather than of an installation.
Until v0.0.20 they were not, and a restore ended by asking you to invent an administrator and
rebuild every mailbox by hand.

The file is still kilobytes on an ordinary install, so it fits in a password manager, and restoring
it onto a fresh one is a safe thing to do rather than a thing you do once and never again.

## Where configuration actually lives

Four, and knowing which is which is most of understanding what an import does and when it takes
effect. The last two are both the database; they are separate rows because a restore treats them
oppositely — it overwrites the operator's settings and it never overwrites a person.

| Where | What is there | Can plMail write it? |
|---|---|---|
| **The generated secrets file** | `APP_ENCRYPTION_KEY`, the VAPID keys, the OAuth client credentials, `APP_PUBLIC_URL` — `var/secrets/generated.env`, minted on first start and loaded by the entrypoint before anything else runs. (`APP_SECRET` and `MERCURE_JWT_SECRET` live here too but stay out of the backup — each machine keeps its own) | **Yes**, and the values take effect at the next container start |
| **The secrets volume** | `jwt/private.pem`, `jwt/public.pem` — files beside that one, on the `app_secrets` volume every service mounts | **Yes.** Measured per file, per install |
| **The database** | The Firebase project, the mail OAuth registrations, the integration provider settings — everything an admin typed into a form rather than into a file | **Yes**, immediately |
| **The database, again** | The users, and per user their mail accounts and credentials, aliases, integrations, filters, labels, calendars and published links | **Yes**, immediately — but only ones this install does not already have. See [Users](#users) |

**This is not the same claim plMail used to make.** Earlier versions listed every environment value
as something only the operator could set, and printed two dozen lines to paste into `.env.local`.
That was built on a premise that is wrong for the way plMail is deployed: nobody hand-edits these.
They are *generated on first run* by `frankenphp/generate-secrets.sh` into `var/secrets/generated.env`,
every service mounts the volume that file is on, and the app process can write it. So the import
writes it, and what it owes you afterwards is one sentence — restart the stack — rather than a list
of chores.

### The precedence that decides all of it

Set out in full in the [configuration reference](configuration.md#where-a-value-comes-from); the
short version, highest first:

1. **a real environment variable** — compose, your shell, `docker run -e`;
2. **`var/secrets/generated.env`**;
3. `.env.local`, then `.env`.

An empty value counts as absent at every level, because compose passes `${APP_SECRET:-}` through as
an empty string when nobody set one. Both readers apply exactly this rule: the entrypoint's
`load_generated_secrets` skips any name `printenv` already answers for, and
`config/bootstrap_generated_secrets.php` skips any name `$_SERVER` already has.

A restored value therefore takes effect at the next start **unless something in the real process
environment sets the same name to something non-empty** — which is the one case the review still
warns about, and the whole of what is left of the old instruction wall. On the stock `compose.yaml`
that is now **one** name: `MERCURE_PUBLIC_URL`. It pins three to a non-empty default — the other two
are `MAILER_DSN` and `MESSENGER_TRANSPORT_DSN` — but those two are no longer exported at all, for
the reason given under [Why the deployment's own DSNs are not in the backup](#why-the-deployments-own-dsns-are-not-in-the-backup).
Everything else it passes through as `${NAME:-}`.

`truenas.compose.yaml` is the exception and deliberately so: it is a hand-edited file that sets
`APP_SECRET`, `APP_ENCRYPTION_KEY`, `DATABASE_URL`, `MERCURE_JWT_SECRET` and the rest from YAML
anchors, because that platform has no `.env` beside the compose file. On that deployment you are
managing those values yourself, and the review will say so for each of them — which is correct
rather than a failure of the restore, and the lines it hands back are the ones to put in the anchors.

## Exporting

**Admin → Backup → Export configuration.** Type a password twice and press **Download backup**. You
get `plmail-config-<date>.backup`.

The password is not stored anywhere and there is no recovery for it. That is why it is typed twice:
a mistyped password produces a file that looks fine and is discovered to be unopenable on the day it
is needed.

The file is built in memory and streamed straight to the browser — nothing decrypted is ever written
to disk on the server, and the response is marked `no-store` so no proxy keeps a copy.

### What is in it

Names only; the values are yours.

**Environment** — every one of these that this installation actually has a value set for. Empty ones
are omitted rather than exported as blanks.

```
APP_ENCRYPTION_KEY
MERCURE_PUBLIC_URL  JWT_PASSPHRASE
APP_PUBLIC_URL
VAPID_SUBJECT  VAPID_PUBLIC_KEY  VAPID_PRIVATE_KEY
GOOGLE_OAUTH_CLIENT_ID  GOOGLE_OAUTH_CLIENT_SECRET
GMAIL_PUBSUB_TOPIC  GMAIL_PUBSUB_VERIFICATION_TOKEN
MICROSOFT_OAUTH_CLIENT_ID  MICROSOFT_OAUTH_CLIENT_SECRET  MICROSOFT_OAUTH_TENANT
INTEGRATIONS_ALLOW_HTTP  INTEGRATIONS_ALLOWED_HOSTS
APP_DEFAULT_TIMEZONE  APP_DB_LOG_LEVEL  DEFAULT_URI
```

**Deliberately not exported**, because they describe the machine rather than the installation, and
carrying them would break the target rather than configure it: `APP_ENV`, `APP_DEBUG`, the
`APP_DEV_USER_*` fixtures, `APP_CONTAINER_NAME`, `APP_SECRETS_FILE`, `JWT_SECRET_KEY`,
`JWT_PUBLIC_KEY`, `APP_STORAGE_DIR`, `APP_SHARE_DIR`, `MERCURE_URL`, `DATABASE_URL`,
`POSTGRES_PASSWORD`, `MAILER_DSN`, `MESSENGER_TRANSPORT_DSN`, `MERCURE_JWT_SECRET`,
`TRUSTED_PROXIES` and `APP_SECRET`. The JWT
keys' *contents* travel; the paths they live at belong to whichever install is reading them, and
`MERCURE_URL` is the in-network address of a sibling container. `MERCURE_JWT_SECRET` exists to
make the app and the Mercure hub *beside it* agree — the hub reads it once, at container start, so
a restored value re-keys one half of a running pair and every live update dies until the whole
stack restarts; a fresh install mints its own and both halves agree from the first frame.
`TRUSTED_PROXIES` names the addresses whose `X-Forwarded-*` headers this install will believe,
which is a fact about what sits in front of *this* container. The four before those are the
deployment's own infrastructure —
see [Why the database credentials are not in the backup at all](#why-the-database-credentials-are-not-in-the-backup-at-all)
and [Why the deployment's own DSNs are not in the backup](#why-the-deployments-own-dsns-are-not-in-the-backup).
`APP_SECRET` is the one that left for a reason of its own —
see [Why APP_SECRET is not in the backup](#why-app_secret-is-not-in-the-backup).
Every one of these is described in the [configuration reference](configuration.md).

**Files**, addressed by a logical name rather than by a path, so the target puts them where its own
configuration says they go:

```
jwt/private.pem  jwt/public.pem
```

**Database**:

```
fcmConfig             the Firebase project: the service-account key, the client
                      configuration parsed out of google-services.json, and
                      whether push is switched on
mailProviders         per provider (google, microsoft): client id, client secret,
                      the Pub/Sub verification token, the settings bag
integrationProviders  per provider (nextcloud, immich, googleDrive, …): enabled,
                      base URL, client id, client secret, the settings bag
```

<a id="users"></a>
**Users** — an object keyed by email address, because that is what an import matches on. Per person:

```
the account          display name, password HASH (never a password — the
                     plaintext exists nowhere), admin role, locale, timezone,
                     appearance, interface preferences, onboarding state
two-factor           the TOTP secret, its confirmation date, and the unused
                     recovery codes (already SHA-256 digests)
app passwords        name, hint and hash per credential, with lastUsedAt and
                     revokedAt. These are what keeps JMAP clients signed in
mail accounts        IMAP/SMTP host, port and encryption, username, password,
                     OAuth provider and its access/refresh tokens, plus each
                     account's aliases
integrations         per connection: provider, base URL, username, secret or
                     OAuth tokens, the settings bag
filters              conditions and actions, with the label, account and
                     integration ids inside them rewritten on import
labels               the whole tree: names, roles, colours, parents, order
calendars            name, colour, time zone, role, and which mail account or
                     integration each one is a mirror of
published links      calendar share links and booking pages, by token digest —
                     carrying it is what keeps a URL you have already sent out
                     working after the move
```

**Not carried, per person**, and each for a stated reason: **trusted devices** and **push
subscriptions**, which are grants to one browser or one phone and, in the trusted device's case, a
*skipped second factor* — restoring those would weaken the account rather than move it; **label
bindings**, a label's identity on a provider, which the first sync re-derives; **sync state** —
cursors, history ids, watch registrations, calendar push channels, backfill counters — which belongs
to the host that did the syncing; and **avatars and background images**, which are filenames in a
storage volume this file does not carry. Soft-deleted users are not exported: `deletedAt` is a
decision, and a restore honours it.

Mail, calendar entries, contacts and logs are not here at all. That boundary has not moved.

**These are exported decrypted.** In the database they sit in `encrypted_string` columns, readable
only with the `APP_ENCRYPTION_KEY` that wrote them — and the whole point of a config backup is that
it is opened somewhere else, by an install with a different key. Ciphertext would be dead weight.
The envelope's password is the protection; the column encryption is a different protection against a
different threat, and stacking them would produce a file that is safe and useless. That applies to
the TOTP secrets, mailbox passwords, OAuth tokens and integration secrets in the users section
exactly as it does to the Firebase key.

Password hashes, recovery codes and app-password hashes travel as they are stored, because they are
already one-way. They are no less sensitive for it: a hash is an offline guessing target, and this
file is every one the installation has.

Everything above is inside the encrypted envelope. The only thing readable without the password is
the envelope's own header — the format name, the version, the KDF parameters, the salt and the
nonce. There is no plaintext manifest, so nothing about any user is visible in the file until it is
opened.

Which is why: **the file is every secret this installation has, and the password you type is the only
thing protecting it.** Treat it exactly as you treat `APP_ENCRYPTION_KEY`.

## The file format

Documented here so that the file is never hostage to plMail being able to run. It is one JSON object:

```json
{
  "format": "plmail-config-backup",
  "version": 1,
  "kdf": {
    "name": "argon2id",
    "opslimit": 3,
    "memlimit": 67108864,
    "salt": "<base64, 16 bytes>"
  },
  "cipher": {
    "name": "xsalsa20poly1305",
    "nonce": "<base64, 24 bytes>"
  },
  "ciphertext": "<base64 of crypto_secretbox(document, nonce, key)>"
}
```

- **`key` = `crypto_pwhash(32, password, salt, opslimit, memlimit, ALG_ARGON2ID13)`** — libsodium's
  Argon2id, with the parameters read from the file rather than assumed, so raising them later leaves
  old backups openable.
- **`ciphertext` = `crypto_secretbox(plaintext, nonce, key)`** — XSalsa20-Poly1305. The Poly1305 tag
  is why a tampered file fails to open rather than decrypting to rubbish, and why a wrong password
  and an altered file are reported as the same thing: they are indistinguishable.
- **`opslimit` 3 with `memlimit` 64 MiB** is libsodium's MODERATE iteration count with its
  INTERACTIVE memory. plMail's reference deployment is a Raspberry Pi, where a 256 MiB allocation is
  a quarter of the machine and turns a slow page into an OOM-killed worker; iterations only cost
  wall-clock time, so that half is raised to buy back some of what the memory half gives up.

The plaintext inside is a second JSON object, and it carries its own `format` and `version` — the
envelope's version describes how the bytes are encrypted, the document's describes what the fields
mean, and a future plMail can bump either alone:

```json
{
  "format": "plmail-config-backup",
  "version": 2,
  "exportedAt": "2026-08-06T12:00:00+00:00",
  "instance": "https://mail.example.com",
  "env": { "APP_SECRET": "…" },
  "files": { "jwt/private.pem": "<base64>" },
  "database": { "fcmConfig": { "serviceAccountJson": "…" } },
  "users": { "anna@example.com": { "password": "$2y$…", "accounts": [] } }
}
```

**Document version 2 added `users`; nothing else moved.** A version 1 file — anything exported
before users were part of the format — has no `users` key at all, and imports exactly as it always
did: a missing section is read as an empty one. A version 2 file opened by an older plMail is
refused outright rather than half-restored, which is the right answer, since that build would apply
the configuration and silently drop every account in the file. The envelope's version is still 1:
how the bytes are encrypted has not changed, and that is precisely the split the two version numbers
exist for.

### Opening one without plMail

Any libsodium binding will do. With the PHP that is already in the container:

```sh
php -r '
$e = json_decode(file_get_contents($argv[1]), true);
$k = sodium_crypto_pwhash(
    SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $argv[2],
    base64_decode($e["kdf"]["salt"]),
    $e["kdf"]["opslimit"], $e["kdf"]["memlimit"],
    SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
echo sodium_crypto_secretbox_open(
    base64_decode($e["ciphertext"]),
    base64_decode($e["cipher"]["nonce"]), $k), "\n";
' plmail-config-2026-08-06.backup 'your password'
```

Pipe it through `jq` if you want it readable. Note that this prints every credential the
installation has to your terminal and, depending on your shell, into its history along with the
password — do it in a directory you are about to leave.

## Importing

**Admin → Backup → Import configuration.** Choose the file, type its password, press **Review this
backup**.

**Nothing is written by the review.** It reads the file and shows what would happen: what plMail
writes itself — which is nearly all of it — then anything still left for you, then anything merely
worth knowing. Every line says whether it is new here, whether it replaces something different, or
whether it already matches. That middle state is the one worth stopping at, because restoring onto a
running install means replacing live credentials with other live credentials.

Pressing **Apply this backup** asks for the password once more and then executes exactly the list
that was shown. The database writes happen in one transaction, so a document with a broken Firebase
key does not leave three provider registrations from someone else's install behind. The generated
secrets are written afterwards, in a single pass under the same lock `generate-secrets.sh` takes:
names in the file are updated in place, names it does not have are appended, and **anything the
backup says nothing about is left exactly where it was.**

The uploaded file is never stored — not in a temporary file, not in the session. Between the review
and the apply it travels back through the page as the same ciphertext you uploaded, which is why the
password has to be typed a second time.

### What the review says about each value

Six words, and each one is a different fate. The review page tags every line with one of them.

| Word | Means | Reaches you as |
|---|---|---|
| **applied** | Written and in force now | Nothing to do |
| **takes effect on next restart** | Written into `var/secrets/generated.env`, or over a file beside it, and read when the stack next starts | One restart notice for the whole plan |
| **shadowed by compose** | Written — and a non-empty value for the same name in the process environment will win over it at the next start anyway | The line, to change or remove in your compose file (or the `.env` beside it) |
| **external** | Not written, because the other half of the change lives in a system plMail is only a client of | The line, plus what else has to change |
| **kept deliberately** | Not written, and that is the correct outcome. `APP_ENCRYPTION_KEY`, `APP_SECRET`, and any user this install already has | A note, and the line for the one case that needs it |
| **not writable** | The path refused the write — read-only secrets volume, wrong uid, full disk | The line or the path, as before |

Section by section:

| Section | What happens |
|---|---|
| `database` (Firebase, mail OAuth, integrations) | **applied**, re-encrypted with *this* install's `APP_ENCRYPTION_KEY`. plMail owns these rows outright |
| `files` → `jwt/private.pem`, `jwt/public.pem` | **takes effect on next restart**, where the process can write them. Checked with `is_writable` at review time, not assumed — a read-only secrets mount is a supported deployment. The bytes land at once; lexik parses the key once per process, so the tokens *this* container signs stay signed with the old one until it restarts |
| `env` → `APP_ENCRYPTION_KEY` | **kept deliberately.** See [About `APP_ENCRYPTION_KEY` specifically](#about-app_encryption_key-specifically) |
| `env` → `APP_SECRET`, in an old backup | **kept deliberately.** Current backups do not carry it. See [Why `APP_SECRET` is not in the backup](#why-app_secret-is-not-in-the-backup) |
| `env` → everything else | **takes effect on next restart**, or **shadowed by compose** where something pins the same name |
| `users` → somebody this install does not have | **applied**, with everything they configured, re-encrypted with *this* install's key |
| `users` → somebody it does | **kept deliberately.** Nothing about them is touched. See [Users](#users-on-import) |

**Values that already match are not listed as work.** If a restored value is byte-for-byte what this
environment already has, it is not something for you to do, whoever is nominally responsible for it —
so it is dropped from *These are yours to do* and counted in one muted line instead. It is still in
the review's own account of the file, under what plMail writes; what is filtered is the chore list,
and a chore that is already done is not a chore. If nothing at all is left, the page says so and puts
the way out first rather than framing an empty list as work.

<a id="users-on-import"></a>
#### Users: created, or left completely alone

Matched by email address. There are exactly two outcomes and no third.

- **The address is not here** → the person is created, with their password hash, second factor,
  recovery codes, app passwords, mailboxes, aliases, integrations, filters, labels, calendars and
  published links. They can sign in immediately with the password they already know, their
  authenticator app keeps working, and their existing app passwords keep their JMAP clients
  connected.
- **The address is here** → nothing happens. Not the password, not the second factor, not the
  mailboxes, not one setting. The review lists them under *Already here — kept as they are*, and
  says whether the file agrees with the live account or differs from it.

**Skipping is deliberate and it is all-or-nothing.** A backup is a photograph of a moment that has
passed. Applying a three-month-old one to a live account would reset today's password to February's,
revoke every app password made since, and restore a TOTP secret whose owner re-enrolled in March —
silently, with the person locked out of their own mail and nothing on any page saying why. There is
no undo, because the plaintext of a password exists nowhere.

Merging would be worse than either extreme, which is why "just the mailboxes they don't have" is not
offered: the subtree in the file is internally consistent — filters point at labels, calendars at
integrations, links at calendars — and half of it grafted onto a live user's half is a shape neither
installation ever had.

A **soft-deleted** user counts as already here. They hold the address against a unique index, and
`deletedAt` is somebody's decision.

If you genuinely want a user's old configuration back on a running install, delete or rename the live
account first and import again — or, far better, take the one thing you need out of the decrypted
document by hand (see [Opening one without plMail](#opening-one-without-plmail)).

**The ids inside a filter are rewritten.** A rule that says "apply label 41" refers to row 41 of the
*source* database. On import every reference — the account a rule is scoped to, the `hasLabel` and
`notLabel` conditions, the `labelId` and `integrationId` on the actions, the calendar a booking page
writes into — is pointed at the row that replaced it. A reference to something the file did not carry
is dropped rather than guessed at: a filter that tests one thing fewer is a much smaller wrong than
one that applies somebody else's label.

<a id="why-the-deployments-own-dsns-are-not-in-the-backup"></a>
#### Why the deployment's own DSNs are not in the backup

`MAILER_DSN` and `MESSENGER_TRANSPORT_DSN` are machine-local deployment choices, the same category
as `DATABASE_URL` below. plMail's `compose.yaml` supplies a default for both, so every install has
one whether or not anybody chose it — and the target's is the one that matches the containers
actually running beside it: its relay, its queue. Carrying the source's meant every plan on a stock
stack opened with two rows whose only honest instruction was "change this in the compose file you
already control", which is not a task, and two non-tasks at the top of a list teach a reader that the
list can be skipped.

An old backup that still contains them imports fine: they are classified **external** and left alone,
and if their value happens to equal what this environment already has — which on a stock stack it
does — they are not even listed. If you do want to carry a relay's configuration between installs,
it belongs in the compose file you copy across, not in this file.

<a id="why-app_secret-is-not-in-the-backup"></a>
#### Why `APP_SECRET` is not in the backup

Every other name left the export because it describes the machine. This one left because of what a
restored copy would *do*.

Take the inventory of what plMail actually derives from `APP_SECRET`. Remember-me uses Symfony's
signature-based handler, so the sixty-day cookie is signed with it. Sessions and CSRF tokens are
bound to a session, which does not survive a restore in any case. JMAP's tokens are signed with the
JWT keypair, whose contents travel separately. URI signing and uuid47 are not used. Nothing on disk
and nothing in the database is encrypted under it — that is `APP_ENCRYPTION_KEY`'s job, and that
one does travel.

So restoring it changed exactly one thing: a `REMEMBERME` cookie minted by the *source* install kept
verifying on the *target*. Both halves of that check are in a v2 backup — the signature covers the
app secret and the user's password hash, and users now travel — so a browser that was signed in to
the machine the backup came from was signed in to the machine it was restored onto, without anybody
deciding that. Two stacks on one host make it concrete, because cookies are not scoped by port:
`localhost:80` and `localhost:8002` would hand each other's browsers straight through.

The target keeps the secret it generated at first boot. Nothing it protects is older than that
install, so there is nothing for the backup's copy to unlock but somebody else's cookies. An old
backup that still carries `APP_SECRET` imports fine — it is classified **kept deliberately**, and its
value is not handed back as a line to paste, because there is no case in which you want it.

This is not what makes a restore safe on its own. A browser that still holds cookies from whatever
was on that address before a restore is a browser holding credentials for an installation that no
longer exists; clearing the site's cookies once after a restore is the reliable way to be sure of
what you are signed in as.

#### Why the database credentials are not in the backup at all

`POSTGRES_PASSWORD`, the `postgres_password` file and `DATABASE_URL` are machine-local
infrastructure: minted before the first user exists, consumed by the Postgres image at **initdb**
— when the data directory is created and never again — and assembled from the *source's* password
and the *source's* host. A restore target's database already has its own working credentials, so
there is nothing an operator could ever do with the old ones except break the new instance with
them. Earlier versions exported them anyway and every review carried two "external" rows nobody
could act on; now they are simply not part of a backup. An old backup that still contains them
imports fine — they are classified as external and left alone.

The one scenario the old export theoretically served — the secrets volume lost while the database
volume survived — is handled by Postgres, not plMail: reset the role's password with
`ALTER ROLE app PASSWORD …` as the database superuser and write the same value into
`generated.env`.

#### The one shadow the review cannot see

Detection compares the live value against what `generated.env` holds, because the entrypoint exports
that file's own contents into the environment before it starts the server — so "is it in `getenv`"
answers yes for both and distinguishes nothing. A live value that differs from the file's, or a name
the file has never held, is a pin; a live value equal to the file's is the entrypoint's own export.

The gap: if you pinned a name in compose to the *exact string* the generated file already holds, the
two are indistinguishable from inside the app, and the restored value would be shadowed without a
warning. That takes having copied a generated secret into your compose file by hand. If you have
done that, the values you manage yourself are the ones to re-check after a restore.

## Restoring onto a new installation

Upload the file and type its password. That is the job.

1. **Bring the stack up empty.** It generates its own `APP_SECRET`, `APP_ENCRYPTION_KEY`,
   `POSTGRES_PASSWORD` and `MERCURE_JWT_SECRET` into `var/secrets/generated.env` on first start.
2. **Open `/install`.** Below the account form there is *Restore a configuration backup first* —
   upload the file, type the password, review, apply. Everything the backup carries goes into this
   instance's own secrets file and database.
3. **Sign in.** If the backup carried users — every backup from v0.0.21 onwards does — the install is
   finished at the end of step 2, and the page offers a sign-in link and names the administrator it
   restored. Use the password you already had; it did not change. *If the file predates users, or
   carried none, the page instead leads back to the account form: create the administrator there, as
   before.*
4. **Restart the stack**, once. The instance then comes up as the one the backup was made from.

The restore has to be step 2 rather than something you do afterwards, because `/install` and the
restore page are both open only while the install has no users — and the restore is now usually the
thing that ends that. Both doors answer 404 from the next request onwards; from then on the page is
**Admin → Backup**.

It also pays off downstream: the setup wizard's two administrator steps ask "is anything configured
yet?" to decide whether they apply, so a restored install walks its administrator straight past both
instead of asking for credentials the file already carried.

Step 4 is needed because the first boot has already happened: the entrypoint minted this instance's
own secrets and loaded them into the running processes before you ever reached `/install`, and those
processes read their environment once. The restored values are on disk from the moment you press
apply; the restart is what puts them in force. It can wait until after the account, and the review
page says so.

If the review listed anything under *These are yours to do*, that is your remaining work and each
line says why. On a stock stack that heading should not appear at all: `MERCURE_PUBLIC_URL` is the
one name `compose.yaml` still pins that a backup carries, and if the two agree it is counted rather
than listed. If the page says there is nothing left to do, there is nothing left to do.

### About `APP_ENCRYPTION_KEY` specifically

You have two choices, and they are not equivalent.

- **Keep the new install's key** (the default if you do nothing). The credentials in the backup are
  re-encrypted with it as they are written. This is the case the whole decrypted-envelope design
  exists for and it is the one that just works.
- **Carry the old key over**, by putting the exported `APP_ENCRYPTION_KEY` into
  `var/secrets/generated.env` (or your compose file) **before anything has been stored** and
  restarting. Do this only if you are also restoring the old *database*, whose rows are encrypted
  with it — see [Backup and restore](backup-restore.md). The review lists the line under *Worth
  knowing* for exactly this case; it is the one value the import never writes for you, because the
  credentials it has just written are encrypted with the key currently in force and swapping it
  underneath them would make them unreadable.

Doing the second one *after* an import has already written credentials leaves you with rows written
under one key and a process holding another, which `app:secrets:init` detects on the next start and
refuses to run on.

## Things that bite

**The backup is not a data backup and does not pretend to be.** No mail, no calendar entries, no
contacts, no attachments. It does carry the people and everything they configured, so if you restore
one onto a fresh host and stop there you have a working installation that everybody can sign in to —
with empty mailboxes, until the first sync pulls their mail down again from the servers whose
credentials the file restored. `app:backup` is still the other half; both pages are linked from the
index for a reason.

**Restoring does not touch a user who is already here.** Not their password, not their second
factor, not one setting. That is the correct behaviour and it is stated in full under
[Users](#users-on-import) — but it does mean a config backup is not a way to roll one person's
settings back.

**Lose the password and the file is gone.** There is no recovery, no hint and no reset. Argon2id makes
guessing expensive; it does not make it possible. Store the password where you store the file, or
somewhere you will still have in a year.

**A wrong password and a corrupted file are the same error.** Poly1305 authenticates the whole
ciphertext, so plMail genuinely cannot tell you which it was. If you are certain of the password,
suspect the transfer that produced the file.

**Restoring onto a *running* install replaces credentials — the operator's, not the users'.** The
review marks those lines "replaces a different value" rather than "not set here yet". Read that
column: for the environment, the secrets files and the provider registrations an import is not a
merge, and the Firebase key that gets overwritten is the one the devices in people's pockets are
registered against. Users are the exception and the only one: an existing account is never written
over.

**Applying does not restart anything.** The database half takes effect immediately. Everything that
lives in the secrets volume is on disk at once and *in force* only after a restart — and until you
restart, the install goes on running on the new machine's generated secrets, which is a working
state that looks like a finished restore.

**Exporting is not free of consequence, and it got heavier.** The file that comes out is a complete,
offline, unlimited-attempt copy of every secret the installation has — and that now includes every
user's password hash, TOTP secret and mailbox password, not only the administrator's. An admin who
exports one is holding everybody's credentials in a single file. A backup left in a downloads folder
is a worse exposure than anything this feature protects against.
