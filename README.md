# pl_mail

A self-hosted, Gmail-style mail client built with Symfony. Clone the repo, run `docker compose up`, and you get a working mail client with real-time push updates, threaded conversations, compose-and-send, and multi-account support across standard IMAP and Gmail (OAuth2) accounts.

![PHP](https://img.shields.io/badge/PHP-8.4+-777BB4?logo=php&logoColor=white)
![Symfony](https://img.shields.io/badge/Symfony-8-000000?logo=symfony&logoColor=white)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-18-4169E1?logo=postgresql&logoColor=white)
![License](https://img.shields.io/badge/license-AGPL--3.0-green)

## Features

- **Multi-account, multi-provider** — standard IMAP (password) and Gmail (OAuth2 + Gmail API) accounts side by side, each with independent sync
- **Compose & send** — new messages, reply, reply-all and forward; Gmail is sent through the Gmail API (Sent copy auto-filed, no SMTP needed)
- **Threaded conversations** — messages grouped by References/In-Reply-To headers, with normalized-subject fallback
- **Real-time updates** — new mail appears instantly via IMAP IDLE + Mercure push and Gmail push watches (Cloud Pub/Sub), no refresh needed
- **Contacts** — addresses harvested implicitly from synced mail, with autocomplete in the compose address fields
- **Gmail-style UI** — checkbox select, star, sender, subject + snippet, date, hover actions per row
- **Single-pane navigation** — clicking a thread loads it in-place via fetch + History API, back button works correctly
- **Turbo modal system** — add-account and other forms open in a modal without a full page load
- **Dark mode** — respects system preference, toggleable, persisted
- **Async sync** — Symfony Messenger with Doctrine transport; Gmail fetched via the Batch API to stay within quota; memory-safe batching
- **Supervised IDLE workers** — one IMAP IDLE process per enabled mailbox, watched and auto-restarted by a built-in supervisor command
- **Encrypted credentials at rest** — account passwords and OAuth tokens encrypted with a libsodium secretbox key
- **Attachments & inline images** — lazy Gmail attachment materialization; `cid:` inline images rewritten for display

## Stack

| Layer | Technology |
|---|---|
| Backend | Symfony 8, Doctrine ORM, PHP 8.4+ |
| Database | PostgreSQL 18 |
| IMAP | webklex/php-imap |
| Gmail | Gmail REST + Batch API, OAuth2 (league/oauth2-client) |
| Async | Symfony Messenger, Doctrine transport |
| Push | Mercure hub, Gmail Cloud Pub/Sub watch |
| Frontend | AssetMapper, Tailwind v4, Hotwire Turbo, Stimulus |
| Runtime | FrankenPHP |
| Dev tooling | Docker Compose, Adminer, Mailpit |

## Requirements

- Docker and Docker Compose
- That's it (Google OAuth credentials additionally required to connect Gmail accounts — see [Environment Variables](#environment-variables))

## Getting Started

```bash
git clone https://github.com/karatektus/pl_mail.git
cd pl_mail
cp .env .env.local          # adjust DATABASE_URL, MERCURE_JWT_SECRET, APP_ENCRYPTION_KEY, etc.
docker compose up --build
```

Then open [https://localhost](https://localhost).

On first run, Doctrine migrations run automatically via the entrypoint. Create your first user (`php bin/console app:setup`, or register in the UI), then add an IMAP account or connect a Gmail account via OAuth — the first sync kicks off immediately.

To connect Gmail accounts, set `GOOGLE_OAUTH_CLIENT_ID`, `GOOGLE_OAUTH_CLIENT_SECRET` and `GMAIL_PUBSUB_TOPIC` in `.env.local` first.

## Console Commands

| Command | Description |
|---|---|
| `app:setup` | Create the first admin user (interactive) |
| `app:mail:sync [account-id]` | Dispatch an account-level sync (IMAP or Gmail) for one or all active accounts |
| `app:mail:send-draft [message-id]` | Send a draft message (picker if no ID given) |
| `app:contacts:harvest [account-id]` | Harvest contact addresses from synced messages |
| `app:label:backfill [--account=ID]` | Create labels from existing mailboxes and backfill message/thread label assignments |
| `app:imap:idle <mailbox-id>` | Hold an IMAP IDLE connection for a single mailbox, dispatch sync on change |
| `app:imap:supervise` | Spawn and watch one `app:imap:idle` process per IDLE-enabled mailbox |
| `app:imap:test [--account=ID]` | Test an IMAP connection and folder listing |
| `app:gmail:renew-watches` | Renew Gmail push-notification watches expiring within 24 hours |
| `app:user:promote <email> [--revoke]` | Grant (or revoke) ROLE_ADMIN for a user |
| `app:monitoring:prune [--days=N] [--heartbeat-days=N]` | Prune old log entries and dead process heartbeats |
| `app:reset` | Truncate synced data (messages, threads, optionally mailboxes/labels) — useful during development |

The `imap-supervisor` and `messenger-worker` services start automatically with `docker compose up` and restart on failure. Schedule `app:mail:sync`, `app:gmail:renew-watches` and `app:monitoring:prune` via cron or the Symfony Scheduler as needed.

## Environment Variables

| Variable | Description | Default |
|---|---|---|
| `DATABASE_URL` | PostgreSQL connection string | see `.env` |
| `MESSENGER_TRANSPORT_DSN` | Messenger transport | `doctrine://default?auto_setup=0` |
| `MERCURE_URL` | Internal Mercure hub URL | `http://mercure/.well-known/mercure` |
| `MERCURE_PUBLIC_URL` | Browser-facing Mercure URL | `https://localhost/.well-known/mercure` |
| `MERCURE_JWT_SECRET` | Shared secret for Mercure JWT signing | — |
| `APP_SECRET` | Symfony app secret | — |
| `APP_ENCRYPTION_KEY` | Base64 libsodium key encrypting account credentials at rest (separate from `APP_SECRET`) | dev key in `.env` |
| `GOOGLE_OAUTH_CLIENT_ID` | Google OAuth client ID (required for Gmail accounts) | — |
| `GOOGLE_OAUTH_CLIENT_SECRET` | Google OAuth client secret (required for Gmail accounts) | — |
| `GMAIL_PUBSUB_TOPIC` | Cloud Pub/Sub topic used for Gmail push watches | — |
| `MAILER_DSN` | Symfony Mailer transport (system mail) | `null://null` |

Generate a fresh `APP_ENCRYPTION_KEY` with:

```bash
php -r 'echo base64_encode(sodium_crypto_secretbox_keygen());'
```

## Roadmap

- [ ] Complete the label-based architecture refactor (Label as the user-facing concept; Mailbox demoted to IMAP sync infrastructure)
- [x] Sanitize rendered HTML bodies (currently rendered raw)
- [ ] Gmail-native `threadId` threading (currently RFC Message-ID based)
- [ ] Incoming IMAP flag sync over the IDLE stream
- [ ] Microsoft OAuth2 / Graph send support
- [ ] Avatar fetching (once OAuth avatar scopes are wired)
- [ ] Full-text search
- [ ] Nested label UI

## License

AGPL-3.0 — see [LICENSE](LICENSE) for details.
