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
| User password | stretched in the browser (PBKDF2-SHA256), then the Symfony `auto` hasher (Argon2/bcrypt) over the result | low-entropy, chosen by a human — key stretching is the whole point; see the next section for why it happens twice |
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

## Passwords are stretched in the browser

The server never receives a user's password. `assets/password_kdf.js` derives, in the browser,

```
masterKey = PBKDF2-SHA256(password, kdfSalt, kdfIterations)   // never leaves the browser
authHash  = PBKDF2-SHA256(masterKey, password, 1)             // what is posted
```

— the Bitwarden construction — and the server stores the `auto` hasher's hash of `authHash`
exactly as it would a password. The point is not the login: it is that the end-to-end encryption
of issue #28 will derive the key that unlocks a user's private keys from `masterKey`, and a server
that never sees the password cannot derive it. `App\Security\PasswordKdf` is the server's half,
and carries a PHP `derive()` that the test suite and the console use in place of a browser;
`PasswordKdfTest` pins it to an independently computed vector, and `tests/e2e/auth.spec.ts` signs a
real browser into an account whose hash PHP made, so the two cannot drift apart.

**Salt and iterations are per user** (`User::$kdfSalt`, `$kdfIterations`), handed out before a
sign-in by `POST /login/prelogin`. The count is stored rather than read from configuration so that
raising `app.password_kdf_iterations` later changes only passwords set from then on. An unknown
address is answered, not refused, with an HMAC of the address under the kernel secret as its salt
— deterministic, so asking twice does not reveal it as unknown.

**Accounts move over on their next sign-in.** A null `kdfSalt` means the stored hash is of the
password itself: every account from before this, and any whose password was last set by something
with no browser — the install wizard, the admin create form, `app:user:password`, the seeds. For
those the prelogin answers `migrate: true`, the form posts the password once more beside an auth
hash stretched with the placeholder salt, and `PasswordKdfUpgradeListener` swaps the hash over
after the password has been checked. It runs on `CheckPassportEvent`, not `LoginSuccessEvent`,
because the remember-me cookie is signed over the hash during the latter.

**What a moved account refuses** is the point of all this: its stored hash is of an auth hash, so
posting the password itself no longer signs it in (`PasswordKdfLoginTest` holds that line). The
form still accepts a bare `password` for an account that has not moved — a browser without
JavaScript, or a login page cached across the update — and never moves it on that basis.

**What the server can no longer check** is the new password: its length and the repeat are
enforced in the browser (`password_form_controller.js`). Bypassing that can only give somebody a
weak password of their own choosing. The iteration count *is* checked, because a lower one weakens
the table for whoever steals it, and that is not the person choosing.

**A password somebody else chose works once.** `User::$passwordChangeRequired` is set by the admin
create form and by `app:user:password` (unless `--no-change-required`), and
`PasswordChangeRequiredListener` keeps the person on `/password/required` until they have chosen
their own — on the `main` firewall only, so app passwords keep syncing.

## The keyring

Phase 2 of issue #28. Everything cryptographic happens in the browser (`assets/crypto/keyring.js`);
the server stores blobs it cannot open and holds the rules a stale tab could break
(`App\Service\Crypto\Keyring`).

```
wrapKey      = HKDF-SHA256(masterKey, "plmail/user-key-wrap")      // see the section above
user key     = 32 random bytes, AES-256-GCM-wrapped under wrapKey   // UserKey
address key  = OpenPGP key, locked with a random token              // AddressKey
token        = AES-256-GCM-wrapped under the user key
phrase copy  = user key wrapped under HKDF(BIP39 entropy)           // UserKeyCopy, kind phrase
device copy  = user key wrapped under a non-extractable IndexedDB key  // kind device
```

Every wrapping is `v1.<iv>.<ciphertext>` with its purpose bound in as associated data
(`App\Security\Crypto\Wrapped` checks the shape). The user key is symmetric, where the concept
sketched an OpenPGP key: it is never handed to anybody and only wraps tokens, which AES-GCM does
with integrity included.

**The session stays unlocked** by splitting it: at sign-in the browser wraps `wrapKey` under a random
unlock key, keeps the result in localStorage, and posts only the unlock key (`unlock_key`), which
`SessionUnlock` keeps in the session. A later page fetches it back from `GET /keyring/unlock`.
Neither half opens anything alone, and the browser's half is worthless once the session ends. A
session not opened by the login form — remember-me, the install wizard — is locked until `POST
/keyring/unlock` proves the password like a sign-in (throttled by `keyring_unlock`).

**Rules the server holds:** one keyring per person while one is active; keys only for addresses
the person owns; one primary key per address; a generated key nobody has seen (`firstUsedAt` null)
is deleted rather than kept when another becomes primary, unless its user key is locked; a
password change must carry every active user key re-wrapped (`PasswordChanger` refuses it
otherwise, in the same flush); a password nobody here chose — `app:user:password`, the forced
change — locks every key instead. Removing a person deletes their keyring.

**On the main thread, not in a worker.** Module workers do not see the page's import map, and
nothing in this phase is slow enough to block the page. Decrypting large mail is when a worker
earns its keep.

### Two roots

Phase 6e. `wrapKey` is derived from one secret, the keyring's **root** (`User::$keyringRoot`,
`KeyringRoot`):

```
password    masterKey = PBKDF2-SHA256(password,   kdfSalt,        kdfIterations)
passphrase  masterKey = PBKDF2-SHA256(passphrase, passphraseSalt, passphraseIterations)
            wrapKey   = HKDF-SHA256(masterKey, "plmail/user-key-wrap")   // either way
```

The password for every account that has one. An account without a usable password — the empty
hash `OidcUserResolver` writes for an account single sign-on creates — has nothing to hang a
keyring off: the provider's password never reaches plMail, and a keyring hung off the provider's
say-so would be one the provider could open. Its root is an **encryption passphrase**, chosen in
Settings → Encryption and stretched exactly like a password, with its own salt and count
(`passphrase_salt`, `passphrase_iterations`). Its own salt so that a passphrase equal to a later
password never stretches to the bytes whose auth hash the server sees. No auth hash is ever derived
from the passphrase, so the server has nothing to check it against; whether it is right shows only
in whether a user key opens (AES-GCM). Everything below `wrapKey` — address keys, copies, S/MIME,
pairing, sealed drafts — is the same on both.

`User::$keyringRootInEffect` is the passphrase once set up, and also before for an account without
a usable password; `Keyring::create()` writes the root in the same flush as the first user key,
requires the passphrase's parameters on that root and refuses them on the password. Its iteration
count is at least the installation's password count, so a browser cannot talk the server into a
cheaper root.

**The roots never cross.** Everything that happens to the login applies only to a keyring on the
password: `applyPasswordChange()` and `lockAll()` do nothing on the passphrase, so a password
change neither re-wraps nor refuses over it, and giving a single sign-on account a password
(`app:user:password`, then the forced change) locks nothing. The passphrase changes through `POST
/keyring/passphrase` (`Keyring::changePassphrase()`), with every active user key re-wrapped and the
same all-or-nothing rule. Switching an existing keyring from one root to the other is not offered.

### Unlocking when needed

Only the login form opens the keyring on the way in. Every other session — remember-me, single
sign-on (always remembered, see `OidcAuthenticator`), an account without a password — starts shut
and is asked nothing at sign-in. `Keyring.requireOpen()` asks through one shared dialog
(`crypto/_unlock_dialog.html.twig`, `assets/crypto/unlock_dialog.js`) when something needs the open
keyring: an encrypted message or quote, a draft about to be sealed or built, a sealed draft,
Settings → Encryption, pairing. One dialog however many ask at once; after it, the open keyring is
announced (`crypto:keyring`) so the background decrypter — which never asks itself — and messages
that showed themselves locked carry on. The split unlock then holds for the session as before.

**How the session's half is kept depends on the root.** On the password, `POST /keyring/unlock`
checks the stretched password like a sign-in, throttled by `keyring_unlock`. On the passphrase,
`POST /keyring/unlock/session` keeps the browser's random unlock key **without a proof**, once a
keyring exists (`UnlockController::session()`). That is safe because the half opens nothing on its
own: it wraps the passphrase-derived key in that browser's localStorage, and the server never sees
what it wraps. A session that registers a half of its own gets a key that unwraps nothing it did not
already hold; reaching `wrapKey` still takes the passphrase, checked by the user key opening in the
browser. The wrapped user keys go to every session through `GET /keyring` anyway, so an offline
guess at the passphrase is exactly as possible as an offline guess at the password — the proof on the
password is the server checking a secret it holds a hash of, which has no counterpart here. A
keyring on the password cannot use the proof-free route: it would bypass the throttle.

## Reading encrypted and signed mail

Phase 3 of issue #28. `MessageCryptoDetector` classifies every message in each provider's builder,
**before the row is first written** — `search_vector` is generated from `body_text` on insert, so a
body blanked any later would already be indexed. It reads the top-level Content-Type and its
`protocol=` / `smime-type=` parameters (parsed by hand: webklex's extension attributes are
first-come across every header and turn `-` into `_`), falls back to the attachment list only for
Graph, and to armor at the start of a line in the body. Encrypted kinds get an empty body; the
parts that are only wrapping (`signature.asc`, `smime.p7s`, the ciphertext) are not stored as
attachments.

Signed mail is checked after the commit, on the `enrich` transport (`VerifySignatureMessage`):
S/MIME by OpenSSL, first against the system CAs (Valid) and then with `PKCS7_NOVERIFY` (Untrusted);
PGP by the `gpg` binary in a GnuPG home made for one check and deleted after it (`GnupgHome`), read
from its `--status-fd` lines, against the
person's own address keys (Valid), their keys for the sender (see below) and an Autocrypt key in the
message whose `addr=` is `From`. A signature by an address
other than `From` is Invalid either way. `PgpMimeSplitter` takes the signed bytes out of the raw
source by hand, because every MIME parser hands back something decoded.

Encrypted PGP mail is opened in the browser (`assets/crypto/message.js`): the byte-exact raw source
from `GET /mail/message/{id}/raw`, openpgp.js with the keyring's address keys, postal-mime for the
inner entity, DOMPurify for the HTML. The result goes into the same sandboxed frame, built from the
same document (`mail/_frame_document.html.twig`, rendered with a `<!--plmail:body-->` placeholder),
so its CSP and the hash-allowed frame script are byte for byte the server's. Remote images are
replaced, not proxied: the proxy would learn the URLs the sender encrypted.

## Contacts' keys and key discovery

Phase 4 of issue #28. `ContactKey` is one row per person, address and key; `ContactKeys` is its only
writer and holds the trust rule, **trust on first use**:

- The first usable key for an address is `current`. Every different key after it is `changed` and
  is used for nothing until the person accepts it — not when it arrives in mail, not from WKD, not
  from an import. A partial unique index (`uniq_contact_key_current`, `WHERE status = 'current'`) is
  the guard when two workers collect keys at once; the read-then-write in PHP is not.
- An address with any keys at all — say a removed `current` beside a waiting `changed` — gets no new
  `current` by arrival. The decision stays the person's.
- `pinned` is the person's word that they compared the fingerprint. There is no web of trust:
  certifications on a key count for nothing.
- What counts as a usable key is gpg's verdict (`PublicKeyReader`, in a throwaway home): a user ID
  for the address with a valid self-signature, not revoked, not expired. Private key material is
  refused before gpg sees it.

`SignatureVerifier` imports the person's own keys, every key they hold for `From`, and the message's
Autocrypt key. Own key or pinned `current`: Valid. Unpinned `current`: Untrusted. A `changed` key, an
Autocrypt key while the address already has keys, or a missing key while it has a `current` one:
**KeyChanged**. The browser applies the same table to a signature inside encrypted mail
(`assets/crypto/message.js`, with the keys from `GET /contact-keys/sender`). Every change to a
sender's keys queues `VerifySignatureMessage` for their newest two hundred PGP-signed messages
(`SignatureRecheck`); the banner actions check the open message on the spot.

**Collected from incoming mail** by `CollectContactKeysStep` → `CollectContactKeysMessage` on
`enrich`: the Autocrypt header only when `addr=` is `From` (and only when it is the one valid
header, with no unknown critical attribute), attached keys from the raw source only with a user ID
for `From`, never for mail from the person's own addresses. A message can carry anybody's key; the
sender vouching for their own is the only claim it can make.

**WKD through the server** (`WkdLookup`), because the page may only connect to its own origin. It is
an endpoint that fetches URLs derived from user input, so:

- The URL is built, never taken: `https://openpgpkey.<domain>/.well-known/openpgpkey/<domain>/hu/<z-base-32 SHA-1 of the lower-cased local part>?l=<local>`,
  then `https://<domain>/.well-known/openpgpkey/hu/…`. The domain must be an IDNA-encoded DNS name
  of two labels or more — no IP literal, no port, no path, no bare container name.
- The host is resolved first; any private or reserved address among its A and AAAA records and
  nothing is asked. The checked address is pinned (`resolve`) and `NoPrivateNetworkHttpClient` checks
  the address actually connected to.
- No redirects (`max_redirects: 0`), a 4-second timeout and 8-second ceiling, and a 256 KiB limit
  enforced while the body streams.
- Answers are cached in `cache.app`, a day when found and an hour when not, and only lookups that go
  out count against the per-person `wkd_lookup` limiter (30 in 10 minutes).
- Only keys with a user ID for the address asked about are kept.

keys.openpgp.org (VKS) is not asked; the concept leaves it as an optional instance setting.

## Writing and sending encrypted mail

Phase 5 of issue #28. The server never builds, signs or encrypts anything: it holds no private key.
The compose window decides (`assets/controllers/compose/encryption_controller.js`, from the keys
`POST /contact-keys/recipients` names), and once a draft is to be encrypted it is **sealed**:

- every autosave encrypts what was typed to the person's own active keys and posts it as
  `sealed[draft]`, with the body fields empty. `SealedDrafts` keeps it on disk
  (`SealedMessageStorage`, `<storage>/sealed/`), sets `Message::$sealedDraft`, and from then on
  blanks the body of every save of that row whatever it carries — once sealed, always sealed;
- attachments are encrypted in the browser before upload (`MessagePart::$sealed`): no thumbnail,
  no preview, served only as `application/octet-stream`;
- inline images stay inside the sealed draft as `data:` URIs;
- a reply quotes encrypted mail as a `data-quote-sealed` marker, which the browser fills from the
  raw source.

On Send the browser builds the whole MIME entity (`assets/crypto/mime.js`) and encrypts it
(PGP/MIME, signed inside, to every recipient and the sender) or signs it (multipart/signed), or
leaves it plain for a sealed draft going out unencrypted, and posts it as `sealed[entity]` with its
kind, the fingerprints it was encrypted to and the signer. `MessageSendService` puts it on the wire
through `SealedEntityPart` byte for byte, with the outer headers — From, To, Subject, Date,
Message-ID, Autocrypt — written by `Email` as for any mail, so all three senders, undo and scheduled
send work unchanged. Afterwards the bytes that went out become the row's raw source, which the
reading pane decrypts like any encrypted mail, and the sealed files go.

`SealGuard` is asked when the entity arrives and again when the send job runs: the recipients must
still be the ones it was built for, and each must still have a usable key (`PublicKeyReader`: not
revoked, not expired, encryption-capable) that is among the fingerprints. Otherwise the send fails
and the person is told; nothing is rebuilt on the server.

`Autocrypt:` goes on every outgoing message with the sender's active key for the From address
(`AutocryptHeader`), unless the person switched it off.

## Decrypted mail kept on the server

Phase 3b of issue #28, off unless a person chooses it for an account (`Account::$keepDecrypted`),
and refused for the whole installation by `APP_E2E_SERVER_PLAINTEXT=forbid` (`DecryptedMailPolicy`).

A browser with the keyring open decrypts and posts the **inner MIME message whole** to
`POST /mail/message/{id}/plaintext` — in the background (`crypto--decrypted-mail`, which works
through `GET /mail/decrypted/pending`) and on reading (`mail--encrypted-message`). `DecryptedMail`
trusts none of it: it parses the MIME with the parser sync uses, sanitises the HTML itself, stores
attachments as sync does, keeps the decrypted message beside the raw source
(`SealedMessageStorage::DECRYPTED`; the raw source stays the ciphertext) and marks the row
`plaintext_source = client`. Then what ingest would have done: the search vector (generated from
`body_text`), insights, date proposals, events from `.ics` parts, and the rules — only while unread.

The models are a separate consent (`Account::$keepDecryptedAi`). `DecryptedMailPolicy::allowsAi()` is
asked by the four places that hand mail text to a model — `MessageEmbedder`, `ClassifyMailHandler`,
`ThreadTranscript` (summaries), `ReplyContextReader` (the writing assistant) — and says yes for a
client-supplied body only when the feature's model host resolves entirely into private or loopback
ranges, or equals the host the person consented to by name.

Switching off queues `ForgetDecryptedMailMessage` on `bulk`: bodies, attachments, the decrypted copy,
embeddings, thread summaries, insights and proposals of every such message go. Calendar events
already created stay.

## Encrypted subjects

Phase 6b of issue #28, RFC 9788 header protection. The composer puts `Subject`, `From`, `To` and
`Cc` inside the encrypted entity and marks it `protected-headers="v1"` (`assets/crypto/mime.js`),
and posts `sealed[protectedSubject]`; `MessageSendService` then writes the outer subject as `...`.
The row keeps the real one: the server already knew it from the draft. Reading,
`openEncrypted()` returns the inner subject, which the banner and a placeholder heading show and a
reply's `Re: ...` is completed with; `DecryptedMail` takes it for the row when the account keeps
decrypted mail. Header protection is for encrypted mail only — a signed message's inside is as
readable as its outside.

## S/MIME

Phase 6c of issue #28. The same division of labour as OpenPGP: the browser does the cryptography
(`assets/crypto/smime.js`, PKI.js for the structures), the server stores what it cannot open and
holds the rules.

```
own certificate  = X.509 PEM + chain, from a PKCS#12 file opened in the browser   // SmimeCertificate
private key      = PKCS#8, AES-256-GCM-wrapped under the user key, purpose plmail/smime-key
contact cert     = X.509 PEM from a good signature, current or changed            // ContactCertificate
```

**Import.** `POST /keyring/smime` (keyring token) takes the certificate, its chain and the wrapped
key. `SmimeCertificates` reads the certificate with OpenSSL itself — fingerprint (SHA-256 of the DER),
addresses (subjectAltName, emailAddress), dates — rather than taking the browser's word, and refuses
a non-RSA key, a certificate for none of the person's addresses, and a wrapping that is not
`v1.…`. A newer certificate covering the same addresses retires the older (`retired`: it decrypts,
it never signs and is never encrypted to). The wrapped key sits under a user key, so a password
change re-wraps nothing here, and removing a user key or the person removes it (`ON DELETE
CASCADE`). The PKCS#12 password and the file never leave the browser.

**Contacts' certificates.** Kept only from a signature that checked out and names the sender:
`SignatureVerifier` (OpenSSL, signed mail) and `POST /contact-keys/message/{id}/smime-signer` (the
browser, for a signature inside encrypted mail, which it verified with PKI.js; the server judges only
the certificate it is sent, for the row's own sender, after the ownership voter). Trust
(`ContactCertificates`): a certificate that chains to the system's authorities
(`CertificateReader`, `openssl_get_cert_locations()` plus `SMIME_CA_FILE`) is `verified`, and a
newer verified certificate replaces a verified or expired current one; everything else is trust on
first use with the same partial unique index as contact keys (`uniq_contact_certificate_current`).
There is no CRL or OCSP check.

**Reading.** `openEncrypted()` takes the EnvelopedData (`smime.p7m`), opens it with the keyring's
S/MIME keys and hands the inner entity to the same path OpenPGP uses: postal-mime, DOMPurify, the
sandboxed frame, the decrypted-mail hand-over. RSA PKCS#1 v1.5 key transport is done with BigInt
arithmetic, blinded, because WebCrypto has no PKCS#1 v1.5 encryption and it is what Outlook and
Thunderbird send; RSA-OAEP goes through WebCrypto; content AES-CBC (128/192/256) through
@noble/ciphers. 3DES and AuthEnvelopedData are not opened.

**Sending.** The composer chooses OpenPGP when every recipient has a key, S/MIME when every
recipient has a usable certificate, the sender has one for the From address and there is no Bcc. It
signs (detached SignedData, RSA-SHA256, signer certificate and chain inside, multipart/signed with
`micalg=sha-256`) and then envelopes to every recipient and the sender (AES-256-CBC, RSA PKCS#1 v1.5,
recipients by issuer and serial number) and posts the entity with `sealed[scheme]=smime`.
`SealedDrafts` refuses an entity whose outside is not S/MIME of the claimed kind
(`MessageCryptoDetector`), and `SealGuard` checks — on arrival and when the send job runs — that
every recipient's certificate is their current one and has not expired, and that the signer is the
person's active, unexpired certificate for the From address. Drafts stay sealed with OpenPGP: only
the person ever opens them.

## Native clients and the keyring

Phase 6 of issue #28: `urn:plmail:params:jmap:keys` gives an app password what the browser session
has — the keyring still wrapped (`Keyring/get`, without the recovery copies), contacts' keys and the
composer's lookup (`RecipientKeys`, shared with the web), the decrypted-mail hand-over
(`DecryptedMail`, same rules) — and Email gets its crypto fields. Nothing readable is added.

**`Keyring/get` names the root** (`root`: `password` | `passphrase`) and how to stretch it
(`rootKdf`: `salt`, `iterations`; a null salt on the passphrase means it is not set up yet), so an
app asks for the passphrase on an account single sign-on made — never for a password it does not
have.

**Pairing hands over the user keys without the server seeing them.** The browser seals them under a
fresh 32-byte transfer key (AES-GCM, purpose `plmail/pairing-transfer`), posts only the sealed copy,
and puts the transfer key in the pairing URI's fragment; the browser draws that QR code itself,
because a server-drawn one would need the key. The sealed copy lives beside the code in the cache for
its two minutes and goes out with the app password on redemption. Whoever photographs the QR code
can pair anyway; the keys add nothing a pairing did not already give.

**`Email/import` is how a client-built message is sent.** A `$draft` import keeps its Content-*
headers and body as the outgoing entity — the web composer's path from phase 5 — and an encrypted
one names the fingerprints it was encrypted to, checked by `SealGuard` at import and at sending.

## Publishing keys: the Web Key Directory

Phase 6d of issue #28. `WkdController` answers the draft's two shapes —
`/.well-known/openpgpkey/<domain>/hu/<hash>` (advanced) and `/.well-known/openpgpkey/hu/<hash>`
(direct, domain from the `Host` header) — and both `policy` files. `WkdDirectory` decides; it is the
other side of `WkdLookup`, and shares its hash (`WkdLookup::hash()`) and domain shape
(`WkdLookup::domain()`), so what is served is found under the name every client computes.

**Public, with no session.** The prefix has a firewall of its own with `security: false`, like
`/branding/`: no token, no session, no CSRF, no login redirect. Nothing a caller sends is written;
the one write is `AddressKey::$firstUsedAt` the first time a key is handed out, so it can no longer
be deleted as unseen.

**Three layers decide, each able only to say no:**

- **The installation's domains** (`APP_WKD_DOMAINS`, empty by default). Never "every domain somebody
  has an address on": a person with a Gmail account here gives plMail no say over `gmail.com`.
- **Only the sign-in address.** `User::$email` is unique and set by an administrator. Every other
  address is the person's own claim — a manual alias, an IMAP account's typed address and even its
  server — and publishing those would let anybody with an account put a key under somebody else's
  address.
- **The person's switch** (`User::$wkdUnpublished`, in the config backup).

**The key** is `SendingKey`'s: active, the primary one for the address, unexpired. A replaced,
revoked or expired key stops being served at once. `WkdKeyExport` reduces it with the gpg binary in
a throwaway home (`--export-options export-minimal --export-filter keep-uid=uid =~ <addr>`), reads
the result back with `PublicKeyReader`, and serves it only if gpg still accepts it for that address;
the result is cached by armor and address.

**No enumeration beyond the one fact.** An unknown hash, a switched-off address, an address without
a key, an alias, a domain not served, the directory switched off: one 404, same headers, empty
body. The `?l=` hint is ignored — only the hash finds an address, so a hint naming somebody else
fetches nothing of theirs. What cannot be hidden is that a published address has a key; the
`wkd_serve` limiter (300 an hour per IP) slows walking a list of names.

**The settings check** (`POST /settings/encryption/wkd/check`) goes through `WkdLookup` with every
rule it has — HTTPS only, no redirects, no private addresses, the per-person limit — past its cache.
Both settings actions take an address from the form, and both refuse (404) one that is not the
person's own on a served domain.

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

**`main` carries login throttling**, three limits in `App\Security\LoginRateLimiter`: 5 per
15 minutes per username and address; 25 per address, the backstop for one client spraying a
password across many accounts, looser so a household behind one NAT address cannot lock itself
out; and 20 per username whatever the address, so guesses spread across many addresses still
hit a ceiling. Symfony's default has only the first two — this page used to call that "per
username", which it is not. Remember-me keeps its tokens on the server (`rememberme_token`, Symfony's
DoctrineTokenProvider), one row per device, with a 60-day lifetime. Signing out deletes this
device's row, so a cookie on a request that set off before the sign-out and arrives after it opens
nothing — the page's own background requests used to sign the person straight back in with a
signature-only cookie. Changing the password still ends every one of them.

**Single sign-on is a second authenticator on `main`, not a second firewall.**
`App\Security\OidcAuthenticator` ends in the same token for the same `User` as the password form, so
the user checker, the throttle above and two-factor apply to it unchanged — see
[Single sign-on](../providers/oidc.md) for the operator's side. Four decisions are worth knowing:

- **The ID token's signature is not verified.** The token is only ever taken from the provider's
  token endpoint, over TLS, in exchange for the code and the client secret, and OpenID Connect Core
  § 3.1.3.7 allows the TLS connection to stand in for the signature in exactly that case. That is why
  the issuer and its endpoints must be `https://`, and why no request to the provider follows a
  redirect. A change that accepts an ID token from anywhere else has to add JWKS verification first;
  `IdTokenValidator` says so.
- **An account is linked by issuer and subject**, never matched by address after the first sign-in.
  The first link is by address, and only when the provider says the address is verified — otherwise
  anybody who can edit their own address at the provider could claim an account.
- **It is configured under Admin → SSO, with the environment as the fallback** — a stored provider
  wins, whole, and the `OIDC_*` variables are used when none is stored (`OidcSettings`). That is a
  deliberate exception to the rule that an admin session must not become a second way into every
  mailbox: this setting decides who may sign in, and an administrator who points it at a provider of
  their own can sign in as any account without a second factor, since a new issuer relinks every
  account by address. Two-factor authentication still holds, because the code is asked for after
  the provider has answered. The first version read the environment only for exactly this reason;
  being able to set it up without a restart was judged worth it.
- **It moves trust to the provider.** Whoever runs the provider can sign in as anybody, which is what
  single sign-on means. The less obvious half: Admin → Users can change an account's address, and an
  unlinked account is found by address, so an administrator with a second login at the provider can
  reach an account that has not been linked yet. `app:user:oidc-unlink` is console-only for the same
  reason.

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

The Web Key Directory (`/.well-known/openpgpkey/`) is not in the list: like `/branding/` it has a
firewall of its own with `security: false`, because its answers are the same for everybody and must
not touch a session. What it answers is in [Publishing keys](#publishing-keys-the-web-key-directory).

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
   the normal case for Nextcloud, Immich and Paperless-ngx, so this flag will often be set — the
   point is that plaintext credentials over the wire become a deliberate admin decision rather
   than a silent default.
3. **Loopback, link-local and private ranges are refused** unless the host appears in
   `INTEGRATIONS_ALLOWED_HOSTS`. The host is **resolved**, and every address it resolves to is
   checked against `IpUtils::PRIVATE_SUBNETS` — loopback, RFC1918, link-local (including the
   cloud metadata endpoint at 169.254.169.254), carrier-grade NAT, the reserved ranges, and
   IPv4-mapped IPv6 such as `::ffff:127.0.0.1`. A string check could not see container names
   (`database`, `mercure`), wildcard DNS (`127.0.0.1.nip.io`) or the IPv4 spellings libc accepts
   (`127.1`, `2130706433`); the resolver answers for all of them.

Credentials in the URL are refused outright, because they would be logged wherever the URL is
and would silently override the ones on the connection.

**The validator is not the enforcement.** It runs when an address is saved, to give a readable
error. The requests go through `App\Infrastructure\Http\UserUrlHttpClient`, which wraps
Symfony's `NoPrivateNetworkHttpClient`: it resolves the host itself, pins the address it checked
with the `resolve` option, and re-checks every redirect hop. That closes the two holes the
validator alone left — DNS rebinding between the check and the connection, and redirects, which
the Nextcloud, Paperless-ngx and Immich drivers used to follow twenty deep without looking. The
exemption comes from the same place as the validator's: a host in `INTEGRATIONS_ALLOWED_HOSTS`,
or the host of a server address an admin pinned, may be reached on its own addresses for that
request, and nothing else private may be.

Push endpoints go through a second instance of the same client with its own exemption list,
`PUSH_ALLOWED_HOSTS`, and never follow redirects; `App\Jmap\Push\PushEndpointPolicy` refuses a
private endpoint when it is registered, from the web app or through `PushSubscription/set`.

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

**Mail the reader sent is not blocked.** The block keeps a stranger from learning that their
message was opened, and asking it of your own mail is asking whether you trust yourself. "Your
own" is read off the Sent label, which only the account's own session puts on a message, and
never off the From line — a forged mail in the inbox carries your address there as easily as any
other. The images still load through the proxy, and Spam still wins.

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

**Settings → Mail → Images** can widen the allowlist to every sender (`images.always_show` in the
user's settings, off unless switched on, #124). It is read in `MessageRenderer` beside the
allowlist and stops where the allowlist stops: Spam still wins, and images still go through the
proxy, so a sender learns that a message was opened but not the reader's address. It is kept
apart from the allowlist on purpose, so no "Stop showing" line is drawn for a sender nobody
trusted, and switching it off leaves the per-sender entries as they were.

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

**A new place that needs the keyring must ask through `requireOpen()`, not `ensure()`.** `ensure()`
opens only what the session can open silently; on a remember-me or single sign-on session that is
nothing, and the feature would look broken to exactly the people who never typed a password. The
background decrypter is the one deliberate exception: a dialog nobody asked for is worse than mail
decrypted a little later.

**The proof-free session route must stay passphrase-only.** Letting a password keyring register its
half there would skip the throttle `POST /keyring/unlock` puts on guessing the password.

**Anything that acts on "the password" must check the root.** A key locked or re-wrapped because the
login changed, on a keyring that hangs off the passphrase, is a person locked out of their mail for
having been given a password.

**An aborted request is not a lost session.** `session.js` clears its stored half only when the
server answers 404 for the unlock key. Clearing on any failure locked the keyring of everybody
who clicked on before the first page had finished opening it — the browser abandons the request.

**PKCS#1 v1.5 decryption is a padding oracle wherever its failures can be told apart.** In
`smime.js` they cannot from outside: a message that does not open says only that it does not, to
the person reading it. Keep it that way — no server round trip, no distinct error per padding
failure.

**A contact certificate is not re-checked against the authorities.** `verified` is what was true
when it was last seen. An authority removed from the system bundle later does not unverify it.

**A crypto kind set after the first flush is too late.** `search_vector` is a generated column, so
the INSERT that wrote a ciphertext body indexed it. Keep `MessageCryptoDetector::seal()` in the
builders, before `persist()`.

**Editing `_frame_document.html.twig` changes both paths, and that is the point.** The frame script
is allowed by the hash of its exact text; a second copy of the document in JavaScript would stop
running the day either changed.

**Nothing may re-encode the entity of a message built in the browser.** A signature covers its exact
bytes, and nothing on the server could encrypt it again. Keep it in `SealedEntityPart`; a body set
any other way is a message the server rebuilt.

**A sealed draft has no body, so every server feature that reads drafts sees none.** That is the
point, and it holds only while nothing writes a body back: `SealedDrafts::accept()` blanks it on
every save of a sealed row, not only on the ones that carried `sealed[draft]`.

**`SealGuard` runs twice because a scheduled send can wait days.** Checking only when the entity
arrives would send a message encrypted to a key replaced or revoked in between.

**Encrypting to Bcc recipients uses wildcard key ids.** A PKESK per recipient names their key on the
outside, which would tell everybody who else got the message.

**A new place that hands mail text to a model must ask `DecryptedMailPolicy::allowsAi()`.** Nothing
else stops decrypted mail reaching it: to every other reader a client-supplied body is a body.

**Locality is decided by DNS when asked.** A model host that resolves privately today and publicly
tomorrow is external tomorrow; a consent is stored for a host name, not an address.

**A changed key that becomes `current` by any path but `ContactKeys::accept()` undoes TOFU.** The
partial index stops two `current` rows, not a write that demotes the known key first. Every writer
goes through `ContactKeys`.

**An Autocrypt header is read only when `addr=` is `From`.** Phase 3 read any; a header naming
somebody else would now plant a key for them on first use.

**WKD URLs are built from a validated domain, never from the input.** A lookup endpoint that took a
URL, or a domain with a port or path in it, is an SSRF hole however well the client below it checks
addresses.

**A WKD miss must stay one response.** Any new reason for "no key here" goes through
`WkdDirectory::find()` returning null and the controller's single 404 — not an exception, a
redirect or a message, each of which would tell the reasons apart.

**gpg's `mail` filter property matches nothing in `keep-uid`** (gpg 2.4). `WkdKeyExport` filters
on `uid` instead; changed back, every key would go out without a user ID and the read-back check
would publish nothing.

**`MockHttpClient` follows no redirects whatever it is told**, and `NoPrivateNetworkHttpClient`
follows them itself, never the client beneath. A test cannot show `max_redirects: 0` working; the
option in `WkdLookup::fetch()` is the guard, and it says why.

**The prelogin tells one thing apart: an account that has not moved over yet.** Its `migrate: true`
is the only difference between a known and an unknown address, and it lasts until that account's
next sign-in. The `login_prelogin` limiter bounds walking a list of addresses; it sits above
`login_ip` so a person mistyping hits the login limit, with its message, first.

**Moving an account over ends its other sessions.** Symfony logs a session out when the stored hash
it was opened against changes, and the remember-me signature covers it too. Once per account, on the
first sign-in after the update — the changelog says so.

**`crypto.subtle` does not exist on plain `http://`.** `password_kdf.js` falls back to
`@noble/hashes` there; without that, nobody on a LAN install reached by IP could sign in. The
fallback is several times slower, about a second or two at 600 000 iterations.

**Setting `User::$password` directly is for rehashing only.** A new password goes through
`useServerHashedPassword()` or `useClientDerivedPassword()`, which keep the salt in step. A salt left
on a row whose hash is of the password itself has the browser stretch a password the hash was never
made from, and the account refuses its owner.

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
