# Security

Your mailbox is the account worth protecting most: anyone who reaches it can reset the password on
everything else you own, because that is where the reset links arrive. plMail says so on the page
itself, and this is what it offers.

Everything here lives under **Settings → Security**, except app passwords, which have their own
section.

## Two-factor authentication

Opt-in, per user, TOTP — the six-digit code any authenticator app produces. The setup wizard offers
it as a step; **Set up two-factor authentication** in Settings does the same thing later.

Enrolment is three steps and the page walks them:

1. Install an authenticator app if you have not got one. Google Authenticator, Aegis, 1Password and
   Bitwarden all work.
2. Scan the QR code. **Can't scan? Enter a key instead** gives you the setup key to type.
3. Enter the six-digit code the app is showing, and **Confirm**.

The secret is not active until a code minted from it has been checked. Enrolment abandoned halfway
leaves an account that still opens with its password, not one nobody can get into. Rejecting a code
does not mint a new secret either — the QR you scanned a moment ago stays valid, so a second attempt
is against the same entry.

plMail tolerates fifteen seconds of clock drift in either direction. It runs on home servers and
NAS boxes where a drifted clock is an ordinary Tuesday, and the failure it causes — "my code is
always wrong" — is near-impossible to diagnose from the outside.

The entry appears in your authenticator app under **plMail**, labelled with your address.

The code form has its own rate limit: five attempts per fifteen minutes, keyed to the account. That
is tighter than the password form deliberately. By the time the code form is reachable the password
has already been accepted, so somebody holding a stolen password arrives with six digits between
them and the mailbox, and a million is not a large number against an unthrottled form.

## Recovery codes

Confirming enrolment produces **eight** recovery codes, each four dash-separated groups of hex, each
usable once. They are shown exactly once — only their digests are stored, so there is no screen that
can show them again. Keep them somewhere reachable without your phone: a password manager, or paper
in a drawer.

The Security section shows how many remain unused. **Generate new codes** replaces the whole set, so
anything you wrote down before stops working.

A recovery code proves possession the same way a live code does, so it is enough to turn 2FA off or
to regenerate the set. It is consumed on the way past, so a leaked one cannot be replayed against a
second action.

## Turning it off

**Turn off** requires a current code or an unused recovery code. Whoever is sitting at an unlocked
session should not be able to strip the second factor off the account without holding the second
factor.

Turning it off deletes your recovery codes and stops trusting every remembered device.

## Remembered devices

The code form offers **Don't ask on this device again**. Ticking it skips the prompt on that device
for **30 days**, renewed as it is used.

Every remembered device is listed in Settings with a label plMail works out from the browser —
"Firefox on macOS" — plus when it was last used, from which address, and when the trust expires.
**Revoke** drops one; **Revoke every remembered device** drops them all, this one included.

Revoking takes effect on that device's **very next request**, not whenever a cookie happens to
expire. That is the reason the grant is a database row rather than a signed cookie: the stock
approach puts the whole grant in a JWT, which is stateless and fast and cannot be taken back — a
stolen cookie stays valid for its full lifetime, and the only revocation on offer is invalidating
every device the user owns at once. Here the cookie is an opaque 32-byte secret, only a SHA-256 of
it is stored, and revoking one device is one row.

Revoking the device you are sitting at also clears its cookie, so the browser stops presenting a
secret that will never be honoured again.

Confirming a **new** enrolment also revokes every remembered device, on the grounds that a device
trusted under the old secret would otherwise keep skipping the prompt.

## Signing in

The login form throttles at five attempts per fifteen minutes per address, with a looser
per-address-of-origin backstop for somebody spraying one password across many accounts — looser so
a household behind one NAT address cannot lock itself out.

**Keep me logged in** issues a cookie lasting 60 days. It is signature-based rather than stored,
which means changing your password invalidates every one that was issued. It does **not** walk past
the second factor: a remembered session is still challenged for a code.

There is no session list and no "sign out everywhere" button. What plMail can withdraw is a
remembered device, an app password, or — by changing the password — every remember-me cookie at
once.

**There is no password reset flow.** The login form says so rather than offering a link that goes
nowhere: recovery runs from the server console, and an administrator can set a new password there.

## App passwords

A third-party mail client cannot present a six-digit code, so it gets a credential of its own.
**Settings → App passwords → Generate** mints one, named for whatever you are connecting — the
placeholder suggests *iPhone — Sterna*.

The secret looks like `plmail_` followed by 64 hex characters and is shown **once**, on the response
that created it. Only a SHA-256 digest is stored, plus the first six characters so the list can show
which is which. If you miss it, revoke it and generate another; there is nothing to recover.

Each app password is user-scoped, not account-scoped: one credential reaches every mail account you
have connected. **Revoke** kills one on its own, and any app using it is signed out immediately. The
list also shows when each was last used, updated at most every five minutes — a coarse "recently
active" signal, not an audit trail.

**Pair a device** in the same section is the shortcut: it shows a QR code that a plMail app scans
and exchanges for its own app password. The code works once, expires in two minutes, and does not
itself contain a credential.

Using an app password to connect a client is covered in [Other clients](clients.md).

## Locked out

Losing the phone and the recovery codes is recoverable, because plMail runs on hardware you own:

```
docker compose exec php php bin/console app:user:2fa-disable you@example.com
```

This is deliberately **not** exposed to administrators through the web UI. An admin who could strip
another user's second factor from a browser would be a second way into every mailbox on the install,
reachable with nothing but a stolen admin session.

## Where to read further

- [Other clients](clients.md) — what an app password is for.
- [Security model](../internals/security-model.md) — encryption at rest, the secrets file, tokens,
  and what a public link can reach.
- [Administration](admin.md) — what an administrator can and cannot do to another account.
- [Troubleshooting](../install/troubleshooting.md) — when something will not let you in.

## Things that bite

**Two-factor authentication does not cover `/jmap`.** It cannot: app passwords exist precisely
because an IMAP or JMAP client has no way to present a code. Withdrawing a client's access is the
app password list, not this page.

**Turning 2FA off and back on does not re-trust anything.** Both disabling and confirming a new
enrolment revoke every remembered device, so every machine is asked for a code again — which is the
point, since a device trusted under the old secret would otherwise skip the prompt under the new
one.

**A recovery code is spent whether or not the action succeeds in the way you meant.** It is consumed
as soon as it is accepted, so a mistyped follow-up costs you a code.

**Generating new recovery codes invalidates the old set immediately.** If you had them written down,
throw the paper away in the same motion.

**An app password cannot be shown twice.** Not by an administrator, not from the database, not by
any screen. The recovery is to revoke and generate another.

**A pairing code cannot be told apart from an expired or spent one.** All three answer the same way,
because distinguishing them would confirm which codes had once been real.

**An administrator cannot reset your password or your second factor from the panel.** Both are
console operations, and that is a design decision rather than a missing feature.
