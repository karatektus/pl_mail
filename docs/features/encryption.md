# Encryption

plMail is building end-to-end encryption with OpenPGP: mail that only you and the people you write
to can read — not the plMail server, not your mail provider. This page covers what is there today:
your keys, how they are kept and how you get them back, reading encrypted and signed mail, the
keys of the people you write to, and writing encrypted mail.

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

## Reading encrypted and signed mail

**Encrypted mail** shows a green lock and *End-to-end encrypted* above it. plMail keeps no readable
copy of it on the server: your browser fetches the message as it arrived, decrypts it with your
keys, and shows it like any other mail — attachments included, which download straight from the
browser. In the list it reads *Encrypted message*. This works for OpenPGP, whether the message is
PGP/MIME or an armored block in plain text.

**Signed mail** shows what checking the signature found:

- *Signed, and the signature checks out* — it is intact, and the key or certificate is one plMail
  can vouch for: a certificate from a known authority, your own key, or a contact's key you marked
  as verified.
- *The message is intact, but nothing confirms who signed it* — a self-signed certificate, or a
  contact's key nobody has confirmed yet. The banner offers **Trust this key**: compare the
  fingerprint it shows with the sender first.
- *Signed with a key plMail does not know yet* — the fingerprint is shown, and the banner offers
  **Look up the key** in the sender's domain (see below).
- *Signed with a different key than the one plMail knows for this sender* — your contact may have a
  new key, or somebody may be posing as them. The banner offers **Use the new key**; do that only
  once the sender has told you, another way, that the fingerprint is theirs.
- *The signature does not match* — the message was changed after it was signed, or it was signed by
  somebody other than the sender. Treat it as you would any message that might be forged.

The same holds for a signature inside an encrypted message, which your browser checks after
decrypting it.

The signature file itself (`signature.asc`, `smime.p7s`) is no longer shown as an attachment.

## Contacts' keys

**Settings → Encryption → Contacts' keys** lists the public keys plMail holds for the people you
write to: the address, the fingerprint in groups of four, where the key came from, and a checkmark
for the ones you verified. plMail uses them to check those people's signatures, and will encrypt to
them once it sends encrypted mail.

They come from three places, and you do not have to do anything for the first two:

- **Mail you receive.** A key in a message's `Autocrypt:` header, or attached to it as a key file,
  is kept for the sender — only for the sender, and only a key that names the sender's address.
- **The sender's domain**, through its Web Key Directory (WKD). **Look up a key** asks for one by
  address; the banner of a signature from an unknown key asks for the sender's. The request goes
  out from the plMail server, not from your browser.
- **An import** — paste a public key block or choose a file. The key is kept for every address it
  names.

plMail trusts the **first** key it finds for an address. If a **different** key turns up later — in
a message, from WKD, or in an import — it is listed under the known one with a warning, and plMail
keeps using the known key until you decide. A new key from a contact who lost the old one and a key
from somebody posing as them look exactly alike; only the contact can tell you which it is. Ask them
for the fingerprint, by phone or in person, and then **Use this key instead** — or remove it.

**Mark as verified** says you compared the fingerprint with the contact. It is what turns their
signatures from *intact, but nothing confirms who signed it* into *the signature checks out*, and
it shows a checkmark. Nobody else's word counts: there is no web of trust.

Keys that are revoked or expired, or that do not name the address, are not kept. Each key also has
**Preferences for this recipient** — encrypt, sign, PGP/MIME or inline — which the compose window
follows.

## Writing encrypted mail

You do not switch anything on. As you type the recipients, each one plMail has a key for gets a
small lock, looked up on their domain if plMail did not know it yet. When **every** recipient has
one, the message is encrypted: the header says *Encrypted* and the lock in the toolbar is green.
When only some have one, a note under the recipients says who cannot be reached encrypted, and the
message goes to everybody unencrypted — unless you turned on **Refuse to send when not everybody
can be reached encrypted** under *Sending*. A reply to an encrypted message is encrypted from the
start, with the original quoted as you read it.

The lock in the toolbar, and **Encrypt** and **Sign** under *More options*, decide for this one
message instead.

An encrypted message never reaches the server readable, not even as a draft: your browser encrypts
the draft to your own key before every save, encrypts attachments before they are uploaded, and
builds and encrypts the whole message — to every recipient and to you, so the copy in *Sent* stays
readable — when you press Send. Undo, *Send later* and *Sent* work as they always have.

**Signing.** Encrypted mail is always signed. Unencrypted mail is signed when it goes to somebody
whose key plMail knows, or always if you turn on **Sign unencrypted mail**.

**Sending** (Settings → Encryption) also has **Share your key with every message**, on by default:
an invisible `Autocrypt` header carries your public key, so Thunderbird, Delta Chat and other
programs can answer encrypted. **Attach your public key** adds it as a file as well, for programs
that do not read Autocrypt.

## Keeping decrypted mail on this server

Encrypted mail is not searched, and parcels, dates and rules do not see it — the server never holds
its text. If what you want to keep out is your **mail provider** rather than your own server — a
Gmail or Outlook address, say, on a plMail you host yourself — you can choose otherwise, per
account, under **Settings → Encryption → Decrypted mail on this server**.

With it on, any browser of yours with your keys open decrypts that account's encrypted mail in the
background and hands it to the server, which then treats it like any other mail: search, parcels,
invoices, dates, `.ics` invitations, and your rules while the message is still unread. Messages you
open are handed over straight away. The banner says *Encrypted on arrival · stored readable on this
server*. Your provider still sees only ciphertext.

**Letting the models read it** — sorting into categories, search by meaning, summaries, the writing
assistant — is a second switch under it. If the model host is on the installation's own network
(an Ollama beside it), that is all; if it is somewhere else, you are asked to agree to that host by
name, and a different host later needs your agreement again.

**Switching it off deletes the decrypted copies**: the text, the attachments that came out of it,
the search index, what the models made of it, parcels and dates found in it. The encrypted
originals stay, and so do events already in your calendar. Switching only the models off deletes
what they made of it.

An administrator can forbid the whole option for the installation (`APP_E2E_SERVER_PLAINTEXT`).

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

**Encrypted mail is not searched.** plMail never keeps its text, so search, filters on the message
text, summaries and the assistant all see only the sender and subject. That is the point.

**Remote images in encrypted mail stay blocked, with no button to show them.** Showing them would
mean telling the server the addresses the sender encrypted. Images sent inside the message are
shown.

**S/MIME-encrypted mail cannot be read yet.** It is recognised and marked, but reading it needs an
S/MIME certificate of your own in plMail, which comes later. Signed S/MIME mail is checked as above.

**Mail synced before this update is not looked at again.** An encrypted or signed message that
arrived earlier keeps showing what it showed then until it is synced anew.

**Printing an encrypted message prints no body.** The print view is drawn by the server, which does
not have one.

**A changed key is never used until you say so — even one you imported yourself.** The warning is
the point: it is the moment to ask the contact, not to click it away.

**Accepting a changed key removes the old one.** Signatures made with the old key then read as made
by *a different key*. Their earlier mail keeps the verdict it had, apart from the most recent two
hundred signed messages from that sender, which are checked again.

**Mail that arrived before this update brought no keys.** Keys are collected from mail as it
arrives. For a contact whose key you want now, look it up or import it.

**A lookup tells the contact's domain that someone asked.** It does not tell them who: the request
comes from the plMail server. Answers are remembered for a day (an hour when there was none), and
each person may make thirty lookups in ten minutes.

**keys.openpgp.org is not asked.** Only the contact's own domain is, through WKD.

**A draft is sealed from the moment it is going to be encrypted, not before.** Whatever was saved
before that — text typed before the last recipient with a key was added — was kept as typed until
the next save replaced it. Add the recipients first, or press the lock before you write.

**While your keys are locked in this browser, a message that is to be encrypted is neither saved nor
sent.** Saving it would leave it readable on the server. The compose window says so; open your keys
in Settings → Encryption, or switch encryption off for that message.

**The subject is not encrypted.** Neither are the sender, the recipients or the date: mail needs
them on the outside to be delivered.

**An encrypted draft opens only in a browser with your keys.** The Android app and other mail
programs see it without a body.

**Forwarding an encrypted message carries its text, not its attachments.** Attach them again if
they should go along.

**The writing assistant is not offered for encrypted drafts.** It would read the draft on the
server.

**A scheduled encrypted message is checked again when it leaves.** If a recipient's key was
replaced or revoked in the meantime, or the recipients changed, it is not sent and you are told;
open it and send it again.

**Microsoft accounts send at most 4 MB, encryption included.** Encryption makes a message about a
third larger.

**With Bcc recipients, nobody's key is named on the outside.** Otherwise everybody could see who
else was sent the message. Some older mail programs cannot decrypt such a message.

**The names and sizes of attachments in an encrypted draft are visible to the server** until the
message is sent; their contents are not.

**Decrypted mail kept on the server is in its backups.** Switching the option off deletes it from the
server, not from backups taken while it was on.

**Rules only see a decrypted message while it is unread.** One you have read by the time a browser
decrypts it is not moved by a rule any more: that would be moving it out from under you.

**With *Decrypted mail on this server* on, drafts are not encrypted on the server either,** and
encrypted mail you send keeps its text there. That is what the option is for.

**Nothing is decrypted while no browser with your keys is open.** The background decrypting starts
again the next time one is.

**Changing your password in Settings carries your keys over.** Changing it any other way — an
administrator, `app:user:password` — locks them, and the recovery phrase or a known device opens
them again.
