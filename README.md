# plMail

A self-hosted mail client for your own server. Connect your IMAP, Gmail and Outlook accounts, read them
side by side in one inbox, and keep every message in a database you control.

![PHP](https://img.shields.io/badge/PHP-8.4+-777BB4?logo=php&logoColor=white)
![Symfony](https://img.shields.io/badge/Symfony-8-000000?logo=symfony&logoColor=white)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-18-4169E1?logo=postgresql&logoColor=white)
![License](https://img.shields.io/badge/license-AGPL--3.0-green)

![The plMail inbox](docs/screenshots/inbox.png)

## What it is

plMail runs on a machine you own — a home server, a NAS, a small VPS — and syncs your mail into a
local PostgreSQL database. You reach it through the browser, from any device on your network or
over the internet if you expose it.

It is not a mail *server*. It doesn't receive mail from the outside world or host your domain.
It's the client: it connects to the mailboxes you already have and gives them one interface, one
search box, and one set of labels.

Because everything is synced locally, mail stays readable when a provider is slow or briefly
unreachable, and search runs against your own database rather than someone else's API.

## What it can do

**Your accounts, together**

- Standard IMAP mailboxes, Gmail (via Google sign-in), and Outlook / Microsoft 365 (via Microsoft sign-in)
- Any number of accounts side by side, each syncing independently
- Send from any of them, including multiple sending aliases per account
- Mailbox passwords and OAuth tokens are encrypted before they reach the database

**Reading and writing**

- Conversations grouped into threads, with unread, starred and attachment state at a glance
- Compose, reply, reply-all and forward, with a rich-text editor and contact autocomplete
- Undo send — a short grace period after hitting send, before it actually goes out
- Snooze a conversation to bring it back later
- Labels you create yourself, optionally mirrored back to Gmail
- Attachments and inline images, with the original message available when you need it

**Finding things**

- Full-text search across everything synced, ranked by relevance
- Filters that combine with it: `from:`, `to:`, `subject:`, `in:`, `has:`, `is:`, `after:`, `before:`

**Staying current**

- New mail arrives on its own — IMAP IDLE for standard mailboxes, push notifications for Gmail and Outlook
- Optional browser notifications, so you hear about mail without the tab open
- Scheduled syncing as a fallback whenever push isn't available

**Making it yours**

- Light and dark themes, following your system or set explicitly
- Custom colours and background, exportable and importable as a file
- English and German interface
- Connect other mail apps over JMAP, using per-app passwords you can revoke individually

## A look around

|  |  |
|---|---|
| ![Reading a thread](docs/screenshots/thread.png) | ![Writing a message](docs/screenshots/compose.png) |
| **Threaded conversations** — replies collapse into one conversation, newest expanded. | **Compose** — rich text, contact autocomplete, send from any account or alias. |
| ![Dark mode](docs/screenshots/inbox-dark.png) | ![Settings](docs/screenshots/settings.png) |
| **Dark mode** — follows your system preference, or pick one and stick with it. | **Settings** — add accounts, reorder them, choose how much history to sync. |

<sub>Screenshots use demo data — none of the accounts, senders or messages shown are real.</sub>

## Setting it up

### What you need

- A machine running Docker and Docker Compose
- A few minutes

That's enough for IMAP accounts. Gmail and Outlook additionally need OAuth credentials from Google or
Microsoft — see [Connecting Gmail](#connecting-gmail) below.

### Get it running

```bash
git clone https://github.com/karatektus/pl_mail.git
```

```bash
cd pl_mail && cp .env .env.local
```

Open `.env.local` and set at least these three before starting:

| Setting | What to put there |
|---|---|
| `APP_PUBLIC_URL` | The address you'll reach plMail at, e.g. `https://mail.example.com` |
| `APP_SECRET` | Any long random string |
| `APP_ENCRYPTION_KEY` | The key your mailbox credentials are encrypted with — generate one below |

Generate the encryption key with:

```bash
openssl rand -base64 32
```

plMail encrypts every mailbox password and OAuth token with this key before writing it to the
database, so a stolen database dump or backup discloses no credentials on its own. That cuts both
ways: **if you lose the key, the stored credentials cannot be recovered** and you'll have to re-enter
them. Back it up separately from the database — storing both in the same place defeats the purpose.
The app refuses to start without a valid key rather than quietly falling back to plain text.

Then start everything:

```bash
docker compose up -d
```

The database is prepared automatically on first boot. Open [https://localhost](https://localhost),
create your account, and add your first mailbox.

### Adding an IMAP mailbox

**Settings → Mail accounts → Add account.** Enter the address and password; plMail recognises common
providers and fills in the server settings for you. If yours isn't recognised, enter the IMAP and SMTP
details from your provider's documentation. Test the connection, save, and the first sync starts
immediately.

Mail then arrives on its own: plMail holds an IDLE connection to each mailbox and syncs the moment
something changes.

### Connecting Gmail

Gmail needs OAuth credentials, because Google no longer allows plain password logins.

1. In the [Google Cloud Console](https://console.cloud.google.com), create a project and enable the **Gmail API**.
2. Create an **OAuth client ID** of type *Web application*, with the redirect URI:
   `https://your-domain/oauth/google/callback`
3. Put the client ID and secret into `.env.local` as `GOOGLE_OAUTH_CLIENT_ID` and `GOOGLE_OAUTH_CLIENT_SECRET`.
4. Restart plMail, then **Settings → Mail accounts → Add account → Sign in with Google**.

That's enough to read and send. Gmail will be checked on a schedule.

#### Instant Gmail delivery (optional)

For mail that arrives the moment it's sent, Gmail needs somewhere to publish notifications, which
means a Cloud Pub/Sub topic. This is a one-time setup for the whole instance — not per account.

In the Google Cloud Console, or via `gcloud`:

```bash
gcloud pubsub topics create gmail-push --project=YOUR_PROJECT_ID
```

Grant Google's mail service permission to publish to it:

```bash
gcloud pubsub topics add-iam-policy-binding gmail-push --project=YOUR_PROJECT_ID --member=serviceAccount:gmail-api-push@system.gserviceaccount.com --role=roles/pubsub.publisher
```

Pick a secret of your own, then tell Pub/Sub where to deliver:

```bash
gcloud pubsub subscriptions create gmail-push-sub --project=YOUR_PROJECT_ID --topic=gmail-push --push-endpoint="https://your-domain/gmail/push?token=YOUR_SECRET"
```

Finally, set both values in `.env.local` and restart:

```ini
GMAIL_PUBSUB_TOPIC=projects/YOUR_PROJECT_ID/topics/gmail-push
GMAIL_PUBSUB_VERIFICATION_TOKEN=YOUR_SECRET
```

Now turn on **Instant delivery** for the account in Settings. Two things to know: your project ID is
lowercase and may differ from the display name you chose, and the token in the subscription URL must
match `GMAIL_PUBSUB_VERIFICATION_TOKEN` exactly — plMail rejects notifications that don't carry it.

If mail still doesn't arrive instantly, the admin area's **Gmail webhooks** panel shows the topic,
the exact endpoint Pub/Sub must call, and why any notification was refused.

### Connecting Outlook / Microsoft 365

1. In the [Azure portal](https://portal.azure.com), register an application.
2. Add the redirect URI `https://your-domain/oauth/microsoft/callback`.
3. Grant these delegated Microsoft Graph permissions: `User.Read`, `Mail.ReadWrite`, `Mail.Send`,
   `MailboxSettings.ReadWrite` and `offline_access`.
4. Put the client ID and secret into `.env.local` as `MICROSOFT_OAUTH_CLIENT_ID` and
   `MICROSOFT_OAUTH_CLIENT_SECRET`, then restart and add the account.

`MICROSOFT_OAUTH_TENANT` decides who may sign in: `common` for both work and personal accounts,
`organizations` for work or school only, `consumers` for personal only, or a tenant ID for a single
organisation. It must match the supported account types you chose when registering the app —
a mismatch fails at consent time with `AADSTS50194` rather than at setup.

Microsoft accounts go through Graph rather than IMAP. That's deliberate: Exchange Online treats
IMAP as legacy authentication and blocks it in any tenant running Security Defaults.

Instant delivery works out of the box here, as long as `APP_PUBLIC_URL` is a public HTTPS address
Microsoft can reach — no extra cloud resources needed.

### Reaching it from outside

Put plMail behind a reverse proxy with a real certificate and set `APP_PUBLIC_URL` to that address.
Push notifications from Google and Microsoft only work over public HTTPS, so this is what enables
instant delivery.

## Settings worth knowing

Everything lives in `.env.local`.

| Setting | What it does |
|---|---|
| `APP_PUBLIC_URL` | The public address of your instance. Required for push notifications. |
| `APP_ENCRYPTION_KEY` | Encrypts stored mailbox passwords and OAuth tokens. Back it up separately. |
| `APP_SECRET` | Symfony's application secret. |
| `GOOGLE_OAUTH_CLIENT_ID` / `_SECRET` | Needed to connect Gmail accounts. |
| `GMAIL_PUBSUB_TOPIC` | Pub/Sub topic for instant Gmail delivery. |
| `GMAIL_PUBSUB_VERIFICATION_TOKEN` | Shared secret protecting the Gmail notification endpoint. |
| `MICROSOFT_OAUTH_CLIENT_ID` / `_SECRET` | Needed to connect Outlook accounts. |
| `MICROSOFT_OAUTH_TENANT` | Which Microsoft accounts may sign in. Defaults to `common`. |
| `MAILER_DSN` | Where plMail sends its own system mail, such as password resets. |
| `APP_DB_LOG_LEVEL` | How much detail is kept in the admin log browser. Defaults to `warning`. |

## Keeping an eye on it

Signed in as an administrator, **Admin** shows what the instance is doing: which background workers
are alive, whether push delivery is working for each account, OAuth tokens nearing expiry, queued and
failed jobs, database size, and a searchable log.

![The admin dashboard](docs/screenshots/admin.png)

It's the first place to look when mail stops arriving.

## Good to know

- **Search covers synced mail.** Choose how far back to sync per account in its settings; older mail
  stays on the server until you widen that window.
- **Gmail labels sync one way by default.** Labels you make in plMail stay local unless you switch on
  mirroring for that account.
- **Deleting an account deletes its synced mail** from plMail. Nothing is removed from the provider.
- **Back up two things, separately**: the PostgreSQL volume and `APP_ENCRYPTION_KEY`. The database
  holds your mail; the key unlocks the credentials inside it. Keeping them apart is the point.

## Contributing

Bug reports and pull requests are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) for the development
setup, the test suites and the console commands.

## License

AGPL-3.0.
