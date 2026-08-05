# IMAP and SMTP

A plain mailbox needs no registration anywhere and no administrator: you give plMail the server
addresses and a password, and it connects. This is the path for everything that is not Gmail or a
Microsoft account — your own mail server, a hosting provider's mailbox, GMX, Fastmail, a box at the
end of a domain you own.

What plMail does with the mailbox once it is connected — the sync window, aliases, per-account
options — is in [Accounts and aliases](../features/accounts.md). This page is about getting the
connection to come up, and about the handful of settings providers genuinely disagree about.

## Adding a mailbox

**Settings → Mail accounts → Add account**, on the **IMAP / SMTP** tab.

1. Pick your provider from the **Provider** dropdown if it is there. plMail ships a list of common
   providers and fills in host, port and encryption for both directions from it; several entries also
   carry a short note about something that provider requires. The list is matched against the domain
   of the address you type, so for most mailboxes the settings arrive before you have touched the
   dropdown at all.
2. **Email address** is what the account is called in plMail's own interface. **Username** is
   what the server knows you as, and is also what the provider detection reads. On most servers
   these are the same string; on some they are not, which is what the two fields are for.
3. **Password** is the mailbox password, or — at most large providers — an application-specific
   password. See below.
4. **Incoming — IMAP** takes a host, a port and an encryption mode. Host and port are required; a
   blank form starts at 993 with SSL/TLS, which is what nearly every provider wants.
5. **Outgoing — SMTP** takes the same three, and all of it is optional. A mailbox with no SMTP
   settings is a mailbox you can read and not send from, which is a legitimate thing to want for an
   archive.
6. **Test connection** probes IMAP and SMTP separately and reports each one, so a working mailbox
   with a wrong SMTP port says so instead of failing at the first send.

Save, and the first sync starts immediately.

The encryption choices are **SSL / TLS**, **STARTTLS** and **None**, in both directions. They mean
what they say: SSL/TLS opens an encrypted connection from the first byte, STARTTLS opens a plain one
and upgrades it, and None is plaintext. Ports and modes go together — see the table further down.

From a terminal, the same probe is:

```bash
docker compose exec php php bin/console app:mail:test-connection
docker compose exec php php bin/console app:imap:test --account=ID
```

## How mail arrives afterwards

plMail holds an **IMAP IDLE** connection to each mailbox, which is the protocol's own way of saying
"tell me when something changes" rather than asking every few minutes. A message arriving on the
server reaches plMail in seconds.

There is nothing to switch on. When plMail discovers an account's folders it marks the ones the
server flags as the inbox and the junk folder as IDLE-enabled, and the `imap-supervisor` service
runs one connection per such folder, restarting any that drop with a short backoff. Other folders
are synced but not watched, which is a deliberate economy: an IDLE connection is a held TCP socket,
and holding thirty of them per account to learn about mail moving into an archive folder costs more
than it is worth.

Behind that, a scheduled sweep syncs every account every fifteen minutes regardless. IDLE makes mail
arrive immediately; the sweep is what makes it arrive at all when a connection has been dropped by a
firewall or a provider has decided to stop answering IDLE for a while.

If mail is not arriving on its own, the thing to check first is that the `imap-supervisor` container
is running. Without it, nothing holds an IDLE connection and mail arrives on the fifteen-minute
schedule only.

## App passwords at the big providers

Several providers no longer accept your account password from a mail client, and several more accept
it only until you turn on two-factor authentication. The answer at all of them is an
application-specific password: a generated string that identifies this one client, works alongside a
second factor, and can be revoked on its own without changing your real password.

Where plMail ships a preset it also carries the provider's own caveat:

| Provider | What it wants |
|---|---|
| Gmail | An app password — and the OAuth tab is the better path. See [Google](google.md). |
| Outlook.com / Hotmail | Microsoft has disabled basic authentication for most tenants. Use the OAuth tab; see [Microsoft](microsoft.md). |
| iCloud Mail | An app-specific password. Nothing else is accepted. |
| Yahoo Mail | An app password. |
| Fastmail | An app password. |
| Telekom / T-Online | A separate email password, set in the Telekom account — not the Telekom login itself. |
| GMX | IMAP has to be switched on first, in the GMX web interface under Settings. |
| WEB.DE | The same: IMAP has to be enabled in the web interface before any client can connect. |
| Proton Mail | Only works through Proton Mail Bridge, running on the same host. |

For Gmail and Outlook, prefer OAuth over an app password. It is not only a nicer sign-in: an app
password against Gmail is full mailbox access with no scope limits and no revocation trail beyond
the password itself, and Microsoft blocks the IMAP path outright in any tenant running Security
Defaults, which is the default for new tenants.

## The settings servers disagree about

**Submission port.** Both 587 with STARTTLS and 465 with implicit SSL/TLS are in wide use, and
plMail's presets are split roughly evenly between them. Neither is more secure than the other in
practice; what matters is that the port and the mode agree. 587 with SSL/TLS and 465 with STARTTLS
both fail, usually with a timeout rather than an error, because each side is waiting for the other
to speak first.

**Username.** Some servers want the full email address, some want the local part, and some want
something else entirely — a customer number, or a mailbox name assigned by the host. Shared-hosting
setups are where this bites most often: plMail's preset for Hetzner's mail hosting notes that the
username is the full address, and its preset for domainFACTORY notes that the host is a shared
mail pool where the same applies.

**One host or two.** Plenty of providers serve IMAP and SMTP from the same hostname, and plMail's
presets show several — a single `mail.` host doing both is normal and not a sign that something has
been filled in wrong.

**Whether IMAP is on at all.** GMX and WEB.DE ship with IMAP disabled and require you to enable it
in their web interface first. The failure is an authentication error, which reads as a wrong
password and is not one.

**Folder names.** IMAP servers disagree about what the sent, drafts, junk and trash folders are
called and about how they advertise themselves. plMail reads the special-use flags the server sends
rather than matching on names, which is why folders come out right on a German-language server as
well as an English one — but a server that advertises nothing leaves plMail to treat every folder as
an ordinary one.

## Things that bite

**The port and the encryption mode have to agree.** This is the single most common failure on this
page, and it presents as a hang rather than as a refusal. 993/SSL and 143/STARTTLS for IMAP; 465/SSL
and 587/STARTTLS for SMTP.

**`127.0.0.1` means the container, not your machine.** plMail runs in Docker, so a mail server on
the host's loopback address — Proton Mail Bridge is the usual case — is not reachable at
`127.0.0.1` from inside it. Point the account at an address the container can actually resolve, or
put the bridge where the container can see it.

**An app password is not your account password**, and providers that require one usually reject the
real one with the same generic error they use for a typo. If a password you are certain of is being
refused at iCloud, Yahoo or Fastmail, this is why.

**Changing the password at the provider does not tell plMail.** The account keeps failing to
connect until you update it here; the account row records the failure, so the accounts list is where
to look when one mailbox has quietly stopped.

**Only the inbox and the junk folder are watched with IDLE.** Everything else arrives on the
fifteen-minute sweep. A message that appears in plMail four minutes after a rule filed it into a
subfolder on the server is this, working as designed.

**Without the `imap-supervisor` service, nothing holds an IDLE connection.** Mail still arrives, on
the schedule, which makes this a very quiet failure — the symptom is "mail is always a few minutes
late" rather than anything that looks broken.

**Flags travel outward only.** Marking a message read or starred in plMail is pushed to the server,
but the reverse is not implemented yet — reading a message in another client does not currently
mark it read here.
