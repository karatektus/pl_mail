# pl_mail

A self-hosted, Gmail-style mail client built with Symfony. Clone the repo, run `docker compose up`, and you have a fully working IMAP mail client with real-time push updates, threaded conversations, and multi-account support.

![PHP](https://img.shields.io/badge/PHP-8.4+-777BB4?logo=php&logoColor=white)
![Symfony](https://img.shields.io/badge/Symfony-8-000000?logo=symfony&logoColor=white)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-18-4169E1?logo=postgresql&logoColor=white)
![License](https://img.shields.io/badge/license-AGPL--3.0-green)

## Features

- **Multi-account IMAP** — add as many accounts as you like, each with independent folder sync
- **Threaded conversations** — messages grouped by References/In-Reply-To headers, with normalized subject fallback
- **Real-time updates** — new mail appears instantly via IMAP IDLE + Mercure push, no refresh needed
- **Gmail-style UI** — checkbox select, star, sender, subject + snippet, date, hover actions per row
- **Single-pane navigation** — clicking a thread loads it in-place via fetch + History API, back button works correctly
- **Turbo modal system** — add account and other forms open in a modal without a full page load
- **Dark mode** — respects system preference, toggleable, persisted in localStorage
- **Async message sync** — Symfony Messenger with Doctrine transport, batched and memory-safe (2 GB limit, `em->clear()` between batches)
- **Supervised IDLE workers** — one IMAP IDLE process per enabled mailbox, watched and auto-restarted by a built-in supervisor command

## Stack

| Layer | Technology |
|---|---|
| Backend | Symfony 8, Doctrine ORM, PHP 8.4+ |
| Database | PostgreSQL (SQLite optional) |
| IMAP | webklex/php-imap |
| Async | Symfony Messenger, Doctrine transport |
| Push | Mercure (built into FrankenPHP) |
| Frontend | AssetMapper, Tailwind v4, Hotwire Turbo, Stimulus |
| Runtime | FrankenPHP |
| Dev tooling | Docker Compose, Adminer, Mailpit |

## Requirements

- Docker and Docker Compose
- That's it

## Getting Started

```bash
git clone https://github.com/karatektus/pl_mail.git
cd pl_mail
cp .env .env.local          # adjust DATABASE_URL, MERCURE_JWT_SECRET, etc.
docker compose up --build
```

Then open [https://localhost](https://localhost).

On first run, Doctrine migrations run automatically via the entrypoint. Register a user, add an IMAP account, and the first sync kicks off immediately.

## Console Commands

| Command | Description |
|---|---|
| `app:imap:sync-mailboxes` | Discover and sync folder list for all active accounts |
| `app:imap:sync-messages` | Fetch new messages for all sync-enabled mailboxes |
| `app:imap:idle <mailbox-id>` | Hold an IMAP IDLE connection for a single mailbox, dispatch sync on change |
| `app:imap:supervise` | Spawn and watch one `app:imap:idle` process per IDLE-enabled mailbox |
| `app:imap:reset` | Truncate message, thread, and mailbox tables (useful during development) |

The `imap-supervisor` and `messenger-worker` services start automatically with `docker compose up` and restart on failure.

## Architecture

```
Browser
  │  Turbo / fetch + History API
  ▼
FrankenPHP (Symfony)
  │  dispatches
  ▼
Symfony Messenger ──► SyncMailboxMessageHandler
                            │  syncs via
                            ▼
                       MessageSyncer (webklex/php-imap)
                            │  publishes via
                            ▼
                       Mercure Hub ──► Browser (Turbo Stream)

IMAP IDLE
  app:imap:supervise
    └─ app:imap:idle [mailbox 1]  ─┐
    └─ app:imap:idle [mailbox 2]  ─┼──► dispatches SyncMailboxMessage
    └─ app:imap:idle [mailbox N]  ─┘
```

## Project Structure

```
src/
├── Command/          # Console commands (sync, idle, supervise, reset)
├── Controller/       # MailController, AccountController
├── Domain/
│   ├── Enum/         # MessageTab, ThreadingMethod
│   └── Helper/       # ImapConnectionFactory, AttachmentStorageHelper
├── Entity/           # Account, Mailbox, Message, MessageThread, MessagePart
├── Form/             # Account add form
├── Message/          # SyncMailboxMessage
├── MessageHandler/   # SyncMailboxMessageHandler
├── Repository/       # Doctrine repositories
├── Service/Imap/     # MailboxSyncer, MessageSyncer, MessageThreader
└── Twig/             # AccountsGlobal (global sidebar accounts)

templates/
├── _layout/          # Base layout, mail shell
├── _partials/        # Topbar, sidebar, modal, message row
└── mail/             # Inbox, mailbox, thread, account form views

assets/controllers/   # Stimulus controllers
```

## Environment Variables

| Variable | Description | Default |
|---|---|---|
| `DATABASE_URL` | PostgreSQL connection string | see `.env` |
| `MESSENGER_TRANSPORT_DSN` | Messenger transport | `doctrine://default?auto_setup=0` |
| `MERCURE_URL` | Internal Mercure hub URL | `https://mercure/.well-known/mercure` |
| `MERCURE_PUBLIC_URL` | Browser-facing Mercure URL | `https://localhost/.well-known/mercure` |
| `MERCURE_JWT_SECRET` | Shared secret for Mercure JWT signing | — |
| `APP_SECRET` | Symfony app secret | — |

## Roadmap

- [ ] Unified inbox view (repository ready, template pending)
- [ ] Toolbar: select-all, refresh, pagination
- [ ] Star / archive / delete / mark-read backend endpoints
- [ ] Sanitize body HTML (XSS — currently srcdoc iframe)
- [ ] Flag sync pass (flags only captured on initial sync)
- [ ] OAuth2 (Gmail, Outlook)
- [ ] Compose and send
- [ ] Search
- [ ] Threading smoke test against real mail

## License

AGPL-3.0 — see [LICENSE](LICENSE) for details.
