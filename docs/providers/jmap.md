# JMAP

JMAP is the mail protocol IMAP would be if it were designed today: JSON over HTTPS, with mail,
calendars and contacts behind one login. plMail reads a JMAP account's mail, calendars and contacts
over that one connection, writes your changes back to the server, sends through the server's own
submission, and is told about changes as they happen. Stalwart, Fastmail and Cyrus all speak it.

What plMail does with the account once it is connected — aliases, per-account options — is in
[Accounts and aliases](../features/accounts.md). This page is about connecting it and what to expect.

## Adding a JMAP account

**Settings → Mail accounts → Add account**, on the **IMAP / SMTP** tab, and choose **JMAP** under
**Incoming protocol**.

1. **Email address** and **Username** as for IMAP. On Stalwart the username is usually the address.
2. **Password** is an app password. On Fastmail it is an API token instead: tick **The password is
   an API token**, and the username is then not sent.
3. **Incoming — JMAP** takes the server's host name. The port starts at 443; plMail looks for the
   session at `https://host/.well-known/jmap`, which is where servers publish it. Fill in **Session
   URL** only if yours is somewhere else — behind a reverse proxy that forwards only `/jmap`, for
   example.
4. **Outgoing — SMTP** can stay empty. A server that offers JMAP submission sends your mail itself;
   the connection test says **Sending goes through the JMAP server** when it does. Fill in SMTP only
   for a server that does not, and that server keeps its own copy of what you send.
5. **Test connection** fetches the session, counts the mailboxes and checks for submission.

Save, and the first sync starts. The Inbox comes in first, newest mail first, then everything else;
mail that arrives meanwhile is not held up by it.

## What is synced

**Mail.** A JMAP mailbox is a label in plMail, and a message can be in several at once, as in
Gmail. Inbox, Sent, Drafts, Trash, Junk and Archive are the system labels; every other mailbox is a
label at its path. Reading, starring, filing, archiving and deleting here are written back to the
server, and the same done in another client reaches plMail at the next change. Labels you create,
rename or delete in plMail are mailboxes created, renamed or deleted on the server. Each message is
stored from the server's own copy of the original, so it looks the same as it would over IMAP.

**Calendars and contacts.** A server that offers JMAP calendars or contacts offers them to the same
login, so there is nothing more to connect: under **Settings → Calendars** and **Settings →
Contacts**, the account offers its calendars and address books like a Google or Microsoft account
does. Events and cards are read and written back in the server's own format, so attendees, alerts
and photos survive the round trip.

**Push.** **Instant delivery** is on for a new JMAP account. plMail holds a connection open to the
server and the server says when anything changes — mail, calendars or contacts. When plMail has a
public HTTPS address (`APP_PUBLIC_URL`, see [Configuration](../install/configuration.md)), it also asks the server to
call it back instead, and once the server has proved it can, the open connection is dropped for that
account. Either way changes arrive within seconds, with the fifteen-minute sweep behind them.

## Switching from IMAP

A server that speaks both — Stalwart does — can be switched from IMAP to JMAP on the account's edit
form. Its mail stays: the first JMAP sync recognises every message plMail already holds by its
Message-ID and does not store it again. Switching back works the same way.

## Another plMail

plMail is a JMAP server too, so one plMail can hold another's mail as an account. On the other
instance, make an app password under **Settings → App passwords**; on this one, add a JMAP account
with your address there as the username, that app password as the password, and the other
instance's host name. Mail, labels, sending, calendars, contacts and push work as with any JMAP
server. The other plMail's own contacts are offered as the address book **plMail (your address
there)**, beside any it mirrors from elsewhere.

## Things that bite

**A JMAP server on your own network is refused until an administrator allows it.** JMAP is HTTP,
and plMail does not let an address typed into a form reach the private network unless the host is
named in `INTEGRATIONS_ALLOWED_HOSTS` — the same rule as for a CalDAV server on the LAN, see
[the SSRF guard](../install/configuration.md#the-ssrf-guard). The
connection test says so in as many words. IMAP has no such rule, because an IMAP connection cannot
be used to fetch anything else.

**Without the IMAP supervisor, nothing holds the push connection.** It runs `app:jmap:listen` as
well as IMAP IDLE. Mail still arrives on the schedule, so the symptom is "changes are always a few
minutes late" rather than anything that looks broken.

**A shared mailbox another account lets you see is not synced.** plMail syncs the login's own
account on the server, not other accounts it has access to.

**Archive is created if the server has none.** Archiving takes a message out of the Inbox, and a
JMAP server will not leave a message in no mailbox at all, so the first archive on such a server
creates a mailbox called Archive. A top-level folder of that name that already exists is used
instead.

**Some servers send a message's headers again as the start of its body.** Stalwart has been seen
doing this over IMAP; plMail drops an exact repeat of the header block whichever protocol the
message came over.
