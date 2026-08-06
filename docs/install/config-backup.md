# Configuration backup

An installation's *configuration* is not the same thing as its *data*, and the two are lost in
different ways. Losing the data is a disaster; losing the configuration is a Tuesday afternoon spent
finding out where a Firebase key went, which Google project the OAuth client belonged to, and what
`APP_PUBLIC_URL` used to be. [Backup and restore](backup-restore.md) covers the data. This page
covers the other one: **Admin → Backup**, which puts every setting and credential this installation
runs on into a single password-encrypted file, and takes one back.

Not one message, contact, calendar or user account is in it. That is deliberate — the file is
kilobytes, so it fits in a password manager, and restoring it onto a fresh install is a safe thing to
do rather than a thing you do once and never again.

## Where configuration actually lives

Three places, and knowing which is which is most of understanding what the import can and cannot do.

| Where | What is there | Can plMail write it? |
|---|---|---|
| **The environment** | `APP_SECRET`, `APP_ENCRYPTION_KEY`, `DATABASE_URL`, `MAILER_DSN`, the VAPID keys, the Mercure secrets, the OAuth client credentials — set in `.env.local`, in compose, or generated once into `var/secrets/generated.env` | **No.** A running process cannot change the variables it was started with |
| **The secrets volume** | `jwt/private.pem`, `jwt/public.pem`, `postgres_password` — the files under `var/secrets/`, on the `app_secrets` volume every service mounts | **Sometimes.** Measured per file, per install |
| **The database** | The Firebase project, the mail OAuth registrations, the integration provider settings — everything an admin typed into a form rather than into a file | **Yes** |

The export carries all three. The import applies the third, offers the second where the filesystem
allows it, and hands the first back as lines to paste. It never pretends otherwise; see
[What the import can and cannot do](#what-the-import-can-and-cannot-do).

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
APP_ENCRYPTION_KEY  APP_SECRET  DATABASE_URL  POSTGRES_PASSWORD
MERCURE_JWT_SECRET  MERCURE_PUBLIC_URL  JWT_PASSPHRASE
MAILER_DSN  MESSENGER_TRANSPORT_DSN  APP_PUBLIC_URL
VAPID_SUBJECT  VAPID_PUBLIC_KEY  VAPID_PRIVATE_KEY
GOOGLE_OAUTH_CLIENT_ID  GOOGLE_OAUTH_CLIENT_SECRET
GMAIL_PUBSUB_TOPIC  GMAIL_PUBSUB_VERIFICATION_TOKEN
MICROSOFT_OAUTH_CLIENT_ID  MICROSOFT_OAUTH_CLIENT_SECRET  MICROSOFT_OAUTH_TENANT
INTEGRATIONS_ALLOW_HTTP  INTEGRATIONS_ALLOWED_HOSTS
TRUSTED_PROXIES  APP_DEFAULT_TIMEZONE  APP_DB_LOG_LEVEL  DEFAULT_URI
```

**Deliberately not exported**, because they describe the machine rather than the installation, and
carrying them would break the target rather than configure it: `APP_ENV`, `APP_DEBUG`, the
`APP_DEV_USER_*` fixtures, `APP_CONTAINER_NAME`, `APP_SECRETS_FILE`, `JWT_SECRET_KEY`,
`JWT_PUBLIC_KEY`, `APP_STORAGE_DIR`, `APP_SHARE_DIR` and `MERCURE_URL`. The JWT keys' *contents*
travel; the paths they live at belong to whichever install is reading them, and `MERCURE_URL` is the
in-network address of a sibling container. Every one of these is described in the
[configuration reference](configuration.md).

**Files**, addressed by a logical name rather than by a path, so the target puts them where its own
configuration says they go:

```
jwt/private.pem  jwt/public.pem  postgres_password
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

**These are exported decrypted.** In the database they sit in `encrypted_string` columns, readable
only with the `APP_ENCRYPTION_KEY` that wrote them — and the whole point of a config backup is that
it is opened somewhere else, by an install with a different key. Ciphertext would be dead weight.
The envelope's password is the protection; the column encryption is a different protection against a
different threat, and stacking them would produce a file that is safe and useless.

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
  "version": 1,
  "exportedAt": "2026-08-06T12:00:00+00:00",
  "instance": "https://mail.example.com",
  "env": { "APP_SECRET": "…" },
  "files": { "jwt/private.pem": "<base64>" },
  "database": { "fcmConfig": { "serviceAccountJson": "…" } }
}
```

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

**Nothing is written by the review.** It reads the file and shows two lists: what plMail is going to
do, and what it cannot. Every line says whether it is new here, whether it replaces something
different, or whether it already matches — that middle state is the one worth stopping at, because
restoring onto a running install means replacing live credentials with other live credentials.

Pressing **Apply the writable parts** asks for the password once more and then executes exactly the
list that was shown. The database writes happen in one transaction, so a document with a broken
Firebase key does not leave three provider registrations from someone else's install behind.

The uploaded file is never stored — not in a temporary file, not in the session. Between the review
and the apply it travels back through the page as the same ciphertext you uploaded, which is why the
password has to be typed a second time.

### What the import can and cannot do

| Section | Applied by plMail | Why |
|---|---|---|
| `database` (Firebase, mail OAuth, integrations) | **Yes**, re-encrypted with *this* install's `APP_ENCRYPTION_KEY` | plMail owns these rows outright |
| `files` → `jwt/private.pem`, `jwt/public.pem` | **Where the process can write them.** Checked with `is_writable` at review time, not assumed | The secrets volume can legitimately be mounted read-only |
| `files` → `postgres_password` | **Never** | The string in that file has to agree with the password of a role inside Postgres, and plMail cannot change the role. Writing one without the other gives you an install that will not start |
| `env` → `APP_ENCRYPTION_KEY` | **Never** | Writing it would make every credential already stored here — including the ones the import just wrote — unreadable on the next start |
| `env` → everything else | **Never** | A running PHP process cannot change its own environment, and writing a file the entrypoint reads at the *next* container start is not what "applied" means |

Everything in the last three rows comes back as an exact line to paste or an exact path to write to.
That is the honest answer, and the review says which of the four reasons applies to each value so you
can tell "cannot" from "must not".

## Restoring onto a new installation

The order that works:

1. **Bring the stack up empty.** It generates its own `APP_SECRET`, `APP_ENCRYPTION_KEY`,
   `POSTGRES_PASSWORD` and `MERCURE_JWT_SECRET` into `var/secrets/generated.env` on first start.
2. **Open `/install`.** Below the account form there is *Restore a configuration backup first* —
   upload the file, type the password, review, apply.
3. **Create the administrator account.** The restore does not do this: a config backup carries
   configuration, never people. The page leads back here when it is done.
4. **Paste the environment lines the review listed**, into `.env.local` or your compose file, and
   restart the stack.

Steps 2 and 3 are in that order because `/install` closes for good the moment the first account
exists — and because the setup wizard's two administrator steps ask "is anything configured yet?" to
decide whether they apply, so a restore done first walks the new administrator straight past them.

The restore entry point is guarded by exactly the same thing `/install` is: the installation having
no users. The moment one exists it answers 404, and from then on the page is **Admin → Backup**.

### About `APP_ENCRYPTION_KEY` specifically

You have two choices, and they are not equivalent.

- **Keep the new install's key** (the default if you do nothing). The credentials in the backup are
  re-encrypted with it as they are written. This is the case the whole decrypted-envelope design
  exists for and it is the one that just works.
- **Carry the old key over**, by putting the exported `APP_ENCRYPTION_KEY` into `.env.local` **before
  anything has been stored** and restarting. Do this only if you are also restoring the old
  *database*, whose rows are encrypted with it — see [Backup and restore](backup-restore.md).

Doing the second one *after* an import has already written credentials leaves you with rows written
under one key and a process holding another, which `app:secrets:init` detects on the next start and
refuses to run on.

## Things that bite

**The backup is not a data backup and does not pretend to be.** No mail, no calendars, no users, no
attachments. If you restore one onto a fresh host and stop there, you have a correctly configured
plMail with nothing in it. `app:backup` is the other half; both pages are linked from the index for
a reason.

**Lose the password and the file is gone.** There is no recovery, no hint and no reset. Argon2id makes
guessing expensive; it does not make it possible. Store the password where you store the file, or
somewhere you will still have in a year.

**A wrong password and a corrupted file are the same error.** Poly1305 authenticates the whole
ciphertext, so plMail genuinely cannot tell you which it was. If you are certain of the password,
suspect the transfer that produced the file.

**Restoring onto a *running* install replaces credentials.** The review marks those lines "replaces a
different value" rather than "not set here yet". Read that column: an import is not a merge, and the
Firebase key that gets overwritten is the one the devices in people's pockets are registered against.

**Applying does not restart anything.** The database half takes effect immediately. The environment
half takes effect when you have pasted it in *and* restarted the stack — and until you do, the
install is running on the new machine's generated secrets, which is a working state that looks like
a finished restore.

**`postgres_password` in the backup is not the password of the database you are restoring into.** It
is the one from the old host. It is carried because a restore of the old *volume* needs it byte for
byte; pasting it into a new stack whose Postgres was initialised with a different one gets you
"password authentication failed" on the next start and nothing else.

**Exporting is not free of consequence.** The file that comes out is a complete, offline, unlimited-
attempt copy of every secret the installation has. A backup left in a downloads folder is a worse
exposure than anything this feature protects against.
