# Accounts and aliases

A plMail account is one mailbox you have somewhere else — an IMAP server, Gmail, or Outlook — that
plMail signs into and syncs. You can connect as many as you like; they sync independently and read
as one inbox.

Everything on this page lives under **Settings → Mail accounts**, except where it says otherwise.

![Settings](../screenshots/settings.png)

## Adding an IMAP mailbox

**Settings → Mail accounts → Add account**, on the **IMAP / SMTP** tab. Enter the address and
password. The **Provider** dropdown carries the settings for a long list of common providers and is
matched against the domain you typed, so for most mailboxes the host, port and encryption fill
themselves in; several entries also carry a note about something the provider requires, such as
IMAP needing to be switched on in their web interface first. If yours is not listed, take the IMAP
and SMTP details from your provider's documentation.

A blank form starts on port 993 with SSL for incoming and port 587 with STARTTLS for outgoing,
which is what nearly every provider wants.

**Test connection** probes IMAP and SMTP separately and reports each. Saving does not depend on it,
but an account that stores cleanly and cannot log in is the failure worth catching now rather than
at the first sync, so the probe runs again on save and its outcome is remembered on the account.

The first sync starts immediately. After that, plMail holds an IMAP IDLE connection to each
mailbox and syncs the moment something changes, with a scheduled sweep every fifteen minutes
behind it.

See [IMAP and SMTP](../providers/imap-smtp.md) for the settings servers disagree about.

## Adding Gmail or Outlook

Both are OAuth, so an administrator has to register an application with the provider before the
button does anything. Once that is done, **Add account** offers **Continue with Google** and
**Continue with Microsoft** on the **OAuth** tab, and the whole flow is the provider's own consent
screen — no password is stored here at all.

**Grant everything the consent screen asks for.** Google's screen lists the permissions as separate
tick boxes and lets you decline any of them — and declining one does not fail the sign-in. The
account connects, plMail says so, and the part you withheld quietly does not work: calendars that
never sync, or mail where marking read and archiving appear to work on screen, are refused by
Google, and are undone by the next sync. A Microsoft tenant can withhold the same permissions by
policy.

plMail now compares what it was given against what it asked for and puts a card on **Account
health** when they differ, with a button to connect again and grant the rest. If you connected an
account before this existed, the card appears the first time the account refreshes its sign-in, or
the first time a change is refused.

Microsoft accounts go through Graph rather than IMAP. That is deliberate: Exchange Online treats
IMAP as legacy authentication and blocks it in any tenant running Security Defaults.

The registration itself — which console, which redirect URI, which scopes — is on
[Google](../providers/google.md) and [Microsoft](../providers/microsoft.md). Where the credentials
go is a choice: an administrator can enter them in **Admin → Integrations** under **Mail sign-in**,
or leave them in the environment as `GOOGLE_OAUTH_*` and `MICROSOFT_OAUTH_*`, which is what an
older installation will already be doing. See [Administration](admin.md).

An OAuth account has no editable form here. There is nothing to edit — no password, no host — so it
is managed by connecting and disconnecting rather than by a settings page.

## The account list

Accounts are listed in your own order and can be dragged to reorder. **The order is cosmetic.** It
decides where a row sits and nothing else.

Which account a new message is composed from is a separate, stored choice: the primary account,
marked **Primary** on its row and set by **Make primary** on any other. This used to be the same
thing — the top row was the primary — which meant tidying the list silently changed the address you
send from, with nothing on screen saying so. Two decisions, two controls.

The coloured dot beside each account is its **identity colour**: the same tone that account's
messages wear in the unified lists, so two accounts can be told apart at a glance in a list that
mixes them. It is handed out when the account is added and never moves again — reordering the list
does not repaint it, and it is **not** a status light. Whether an account is actually working is
answered on [Account health](health.md), which is the one place that claims to answer it.

Each row offers:

| Control | Effect |
|---|---|
| **Make primary** | Sends new messages from this account by default — on any row but the primary's |
| **Disable account** / **Enable account** | Stops or resumes syncing without deleting anything |
| **Edit account** | Server settings and password — password accounts only |
| **Remove account** | Deletes the account and every message synced from it |

The primary flag is never lost. Removing the account that held it hands it to another, and the very
first account you add is primary because there is nothing for it to inherit from.

Removing an account deletes its synced mail from plMail's database and nothing at the provider. It
also tries to tear down any push registration it had, so nothing is left pointing at an account
that no longer exists.

Anything else that takes a registration away now hands it back to the provider the same way —
deleting a calendar, unticking one, disconnecting a connection, and an administrator's data reset.
The attempt is best-effort: a provider that refuses, that has already expired the channel, or that
cannot be reached is stepped over rather than blocking the removal, because a reset that will not run
because Google is unreachable is a worse outcome than a channel that lapses on its own within the
week.

On the edit form, leaving the password field blank keeps the stored one.

## Per-account settings

Three controls sit under each account row. Each re-renders on its own rather than reloading the
whole list.

### Instant delivery

**Instant delivery** switches an account between push and scheduled polling. It appears only for
Gmail and Microsoft — a plain IMAP mailbox has no push manager, and IDLE already gives it the same
effect.

Turning it on registers with the provider there and then. If registration fails the switch goes
back off, so the interface never claims push is working while nothing is being delivered, and the
reason is spelled out:

- **Gmail** — Google refused the watch. Check that `GMAIL_PUBSUB_TOPIC` names a topic that exists
  and that the topic grants `gmail-api-push@system.gserviceaccount.com` the Pub/Sub Publisher role.
  Note the `-push`: `gmail-api@system.gserviceaccount.com` is not an address Google publishes from,
  so a topic granted exactly that will never deliver a single notification.
- **Microsoft** — Microsoft could not reach this server over HTTPS. Check the reverse proxy and
  `APP_PUBLIC_URL`.

Either way the account keeps syncing on a schedule. Push is an optimisation, never the only path.

The badge next to it reads **Push** when registrations are healthy, **Scheduled** when it is off, and
one of two things when it is broken:

- **Push (expired)** — the registration's own expiry passed and nothing renewed it. Renewal runs
  daily on its own, so this usually means the scheduled-task worker has stopped.
- **Push (not delivering)** — the registration is alive and unexpired, and mail has arrived that it
  never announced. For Gmail that points at the Pub/Sub push subscription being missing or pointing
  somewhere else; for Microsoft, at a subscription that has lapsed.

**Re-register push** is the button for both, and [Account health](health.md) explains which of the
two you are looking at and shows the dates it decided from.

Where push is not available on this server at all, the control says so rather than offering a
switch that cannot work.

### Sync labels to provider

Off by default. With it on, labels you create, rename or delete are mirrored to that provider,
whether the change came from the web UI or from a JMAP client.

Only Gmail and Microsoft offer it. On plain IMAP a label is a physical folder, so creating and
deleting labels would move real mail on the server — a different and riskier operation than the
switch promises.

It only ever affects changes made from now on. Labels that already exist are not pushed
retroactively, because that would mean bulk-creating your entire local tree at the provider from
one click.

**On Microsoft, most labels are Outlook categories rather than folders**, and a category there has
no identity but its name — the name is what is written onto each message. So renaming one does two
things: the master category is renamed, and every message carrying the old name is pushed again so
it carries the new one. That second half is the same question Outlook's own rename dialog asks; here
it is answered yes, because the alternative is a mailbox where the label exists twice — once as a
category and once as loose text on the mail that used to have it. A label on thousands of messages
therefore means thousands of updates, sent in the background.

Labels that ARE backed by a real Exchange folder — the ones that came from one — are renamed as
folders instead, and nothing has to be re-tagged.

## Sending aliases

**Settings → Aliases** lists the addresses each account sends and receives as. **Refresh from
provider** asks the account what it knows about; **Add** takes one you type yourself.

Each address is **Primary**, active, or disabled. The primary is the default sender for that
account and cannot be disabled or removed — make another one primary first, which demotes it.
Disabled addresses stay on the list and stop being offered in the compose window.

The **From** selector in the compose window lists accounts and aliases together, so sending as an
alias is one choice rather than two.

One caveat plMail states on the page itself: **Outlook sends as the account's primary alias
regardless of what you choose here.** That is Microsoft's behaviour, changed in your Microsoft
account settings, not here.

### What each address does by default

Two settings hang off the alias list rather than off the account, because they are properties of the
address people see rather than of the mailbox it lives in.

**Compose defaults**, under the alias list on the same page, currently holds one control per
address: what that address does when a sender asks to be told their mail was read. The default is
**Never send**, and it is worth understanding before changing it — see
[Read receipts](mail.md#read-receipts).

**Settings → Signatures** is its own section. The account has a signature, and any of its addresses
can either inherit it, replace it, or deliberately sign with nothing. The three states and what the
compose window does with them are in [Composing](mail.md#signature).

## Where to read further

- [IMAP and SMTP](../providers/imap-smtp.md), [Google](../providers/google.md),
  [Microsoft](../providers/microsoft.md) — the exact provider-side setup.
- [Account health](health.md) — what is broken, and the repairs that keep your mail.
- [Mail ingest](../internals/mail-ingest.md) — what a sync run actually does.
- [Configuration reference](../install/configuration.md) — `APP_PUBLIC_URL`, the OAuth variables
  and the Pub/Sub ones.
- [Troubleshooting](../install/troubleshooting.md) — when mail stops arriving.

## Things that bite

**Reordering the list no longer changes which account you send from.** It used to, and an install
upgraded from an older version keeps whichever account was first at the moment it upgraded. If
Compose starts from the wrong address, the fix is **Make primary** on the right one — dragging it to
the top will not do it.

**A dead sign-in does not have to mean re-adding the account.** Deleting a broken account and adding
it again is the reflex, and it costs you every message synced from it. **Settings → Account health**
offers a reconnect that keeps the mailbox; see [Account health](health.md).

**A large mailbox takes a while to arrive in full.** plMail syncs everything the account holds;
there is no setting that bounds it. The newest mail lands first, and the rest follows over the
following runs, so an account added minutes ago is usable long before it is complete.

**Testing the connection on an existing account needs a password in the field.** A blank password
means "keep the stored one" on the edit form, and the tester can only resolve that once the account
id has reached it — otherwise it says so rather than testing with nothing.

**Removing an account deletes its synced mail from plMail.** Nothing is removed at the provider,
and re-adding the account syncs it again from scratch.

**A Google consent screen lets the user untick calendar access while allowing mail.** The account
connects, mail works, and no calendars appear. Reconnecting with the box left ticked is the fix.

**Push failing is not an error state.** A self-hosted install may have no publicly reachable HTTPS
address at all. Registration failing means "stay on polling", and the fifteen-minute sweep is
unaffected.
