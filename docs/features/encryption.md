# Encryption

plMail is building end-to-end encryption with OpenPGP: mail that only you and the people you write
to can read — not the plMail server, not your mail provider. This page covers what is there today:
your keys, how they are kept, and how you get them back. Reading and sending encrypted mail follow
in the next releases.

Everything here lives under **Settings → Encryption**.

## Your keys are made for you

The first time you sign in after the update, your browser makes your keys on its own: one OpenPGP
key per address you send from, and one key above them that holds them together. There is nothing
to set up and nothing to understand about keyrings, fingerprints or trust. A new alias gets its key
the next time you open a page.

Your keys are opened with your password, in your browser, and the server never sees either. What
the server keeps is your keys in locked form: wrapped under a key your browser derives from your
password, which the server cannot derive because it never sees the password (see
[Security](security.md#signing-in)).

## You sign in once

Signing in unlocks your keys for the whole session, in every tab, without being asked again. When a
session ends — you sign out, or it expires — they are locked. A session opened by **Keep me logged
in**, rather than by typing your password, starts locked: the settings page asks for your password
once to unlock it.

## The recovery phrase

Twelve words that open your keys when your password cannot — because you forgot it and an
administrator set a new one. **Settings → Encryption → Recovery phrase** makes them, shows them
once, and offers them as a file to download. The file also holds the revocation certificate of
every key: the one thing left that tells your contacts to stop using a key on the day the password
and the phrase are both gone.

Make a new phrase whenever the old one may have been seen. The new one replaces it.

## Known devices

Every browser you sign in on can keep a small secret of its own that reopens your keys after a
password reset, without the recovery phrase — the comfortable way back. The list shows each
browser, when it was added and when it last helped. Remove one you no longer use. A browser that
blocks site storage cannot keep the secret; the phrase works there too.

## After a password reset

When an administrator sets a new password for you (`app:user:password`), your keys are locked —
nobody knows the old password they were wrapped under. Signing in afterwards:

- on a known device, your keys open again on their own;
- anywhere else, you get a fresh set of keys straight away so you can keep working, and the old
  ones wait. Enter your recovery phrase under **Settings → Encryption** to open them; mail
  encrypted to them becomes readable again.

## Bringing your own key

Already have an OpenPGP key your contacts know? **Import** it — the private key exported from
GnuPG, Thunderbird or Proton, and its passphrase, once. It becomes the key of every address it
names, and your contacts keep encrypting to the key they already have. The passphrase is never
asked again: from then on the key opens with your plMail password. The key plMail had made for
that address is dropped if nobody has seen it yet.

Keys whose secret parts live on a smartcard or security key (YubiKey, OpenPGP card) cannot be
imported: a browser cannot use them.

## Taking your keys elsewhere

**Export** writes every private key — old and replaced ones too, which is what still opens old mail
— into one `.asc` file, locked with a passphrase you choose. GnuPG, Thunderbird, Proton and any
other plMail read it.

Moving a whole installation does not need this: the configuration backup carries your keys in their
locked form along with your password, and they open on the new server exactly as before (see
[Configuration backup](../install/config-backup.md)).

## Renewing and replacing keys

Keys made by plMail do not expire — as with Proton. Under **Show keys**:

- **Renew** makes a new key for the same addresses. The old one stays, marked *replaced*, to open
  mail that was sent to it.
- **Mark as compromised** is for a key you think somebody else has. It stops being used, and its
  addresses get a new one.
- **Make primary** chooses which of several keys an address uses.

## Things that bite

**Without the recovery phrase or a known device, a forgotten password loses your keys.** Nobody can
help with that — not an administrator, not the server, not a backup — because nobody but you ever
had what opens them. Make the phrase.

**The first sign-in after the update makes your keys in that browser.** If you have a key you want
to keep using, import it then: plMail drops its own key for that address only while nobody has
seen it.

**A session reopened by Keep me logged in starts locked.** Your mail is all there; only your keys
wait for your password, once.

**Encryption needs HTTPS.** On a plain `http://` address — a LAN IP, say — the browser offers no
cryptography of its own, so no keys are made or opened there, and Settings → Encryption says so.
Signing in still works. Open plMail through its `https://` address (or `localhost`).

**Changing your password in Settings carries your keys over.** Changing it any other way — an
administrator, `app:user:password` — locks them, and the recovery phrase or a known device opens
them again.
