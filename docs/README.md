# plMail documentation

The handbook. [README](../README.md) says what plMail is and gets you to a running
instance; this covers everything after that — every feature and how to use it, how to
install it on whatever you have, how to register the Google and Microsoft applications
it talks to, and how the parts work underneath.

Mirrored to the [GitHub wiki](https://github.com/karatektus/pl_mail/wiki), which is
generated from these files. Edit them here; browser edits to the wiki are overwritten by
the next mirror.

| If you want to | Start at |
|---|---|
| Use a feature | [Using plMail](#using-plmail) |
| Install or run it | [Installing and running](#installing-and-running) |
| Connect Gmail, Outlook or a CalDAV server | [Providers](#providers) |
| Understand or extend the internals | [How it works](#how-it-works) |

---

## Using plMail

One page per area. Each ends with links into [How it works](#how-it-works) for the
mechanism behind it.

| Page | What it covers |
|---|---|
| [Mail](features/mail.md) | Reading, threads, labels, search and its operators, snooze, attachments, composing, signatures, emoji and inline images, scheduled send, read receipts, drafts, undo send |
| [Accounts and aliases](features/accounts.md) | Adding Gmail, Outlook and IMAP accounts, sending aliases, per-account settings |
| [Account health](features/health.md) | What is broken and what fixes it, reconnecting an account without losing its mail, the two ways push breaks |
| [Filters](features/filters.md) | Condition trees, actions, the plain-English restatement, applying a rule to mail that already arrived |
| [Calendar](features/calendar.md) | The four views and the time grid, creating and editing events, recurrence, editing one occurrence, the docked pane |
| [Invitations and events from mail](features/calendar-invitations.md) | RSVP, events extracted from invitations and from ordinary prose, proposals, Happening Soon |
| [Reminders](features/calendar-alerts.md) | Setting alerts, how they are delivered, what a fresh install needs before they arrive |
| [Connected calendars](features/calendar-sync.md) | Subscribing to Google, Microsoft and CalDAV calendars, two-way sync, ICS import, export and feed subscriptions, duplicate meetings |
| [Sharing and booking](features/calendar-sharing.md) | Share links and what each reveals, appointment pages, how a booking arrives |
| [Files and integrations](features/integrations.md) | Attaching from and saving to Drive, Photos, OneDrive, Dropbox, Nextcloud and Immich |
| [Security](features/security.md) | Changing your password, two-factor authentication, recovery codes, remembered devices, app passwords, sessions |
| [Other clients](features/clients.md) | Connecting a JMAP client, per-app passwords, the PWA and browser notifications |
| [Appearance](features/appearance.md) | Themes, custom colours and background, the live preview, what a list row shows, typeface and text size, per-pane density, import and export, language |
| [Administration](features/admin.md) | Users and roles, switching an account off, enabling integrations, monitoring, queues and the version chip |

## Installing and running

| Page | What it covers |
|---|---|
| [Docker Compose](install/docker.md) | The supported path, start to finish |
| [Platform notes](install/platforms.md) | Linux, Windows via WSL2, macOS, and NAS boxes |
| [Behind a reverse proxy](install/reverse-proxy.md) | TLS, `APP_PUBLIC_URL`, `TRUSTED_PROXIES`, and what breaks without them |
| [Configuration reference](install/configuration.md) | Every environment variable, what it does and what happens if it is wrong |
| [Backup and restore](install/backup-restore.md) | What to back up, the encryption key, and restoring onto a new host |
| [Configuration backup](install/config-backup.md) | Carrying an install's settings and credentials to another one, as one encrypted file |
| [Upgrading](install/upgrading.md) | Migrations run on boot, what that means, and how to tell which build is running |
| [Demo mode](install/demo-mode.md) | Hosting a public demo: throwaway mailboxes, scripted mail, and what it switches off |
| [Troubleshooting](install/troubleshooting.md) | Health checks, the queue, logs, and the failures that have actually happened |

## Providers

Each page names the exact console, the exact checkboxes and the exact redirect URIs.

| Page | What it covers |
|---|---|
| [Google](providers/google.md) | Cloud project, OAuth client, scopes for mail and calendar, Pub/Sub for Gmail push, calendar watch channels and domain verification |
| [Microsoft](providers/microsoft.md) | Azure app registration, redirect URIs, delegated permissions, tenant choice, Graph subscriptions |
| [IMAP and SMTP](providers/imap-smtp.md) | Plain mailboxes, IDLE, and the settings servers disagree about |
| [CalDAV](providers/caldav.md) | Discovery, app passwords, and the servers that have been tested |
| [ICS feeds](providers/ics-feeds.md) | Subscribing to a published calendar by URL, and why some addresses are refused |

## How it works

Deeper than [CONTRIBUTING](../CONTRIBUTING.md)'s notes, and aimed at somebody auditing
or extending plMail rather than running it.

| Page | What it covers |
|---|---|
| [Architecture](internals/architecture.md) | The layers, what lives where, and the rules that keep it that way |
| [Mail ingest](internals/mail-ingest.md) | The pipeline from provider to database, threading, categorisation |
| [The calendar model](internals/calendar-model.md) | JSCalendar in jsonb, projected columns, occurrences, recurrence and overrides |
| [The sync engine](internals/calendar-sync-engine.md) | The driver contract every provider implements, push channels, deduplication |
| [AI assistance](internals/ai-assist.md) | The optional model features: what is off by default, and why the vector design avoids pgvector |
| [Event extraction](internals/event-extraction.md) | How an invitation and how a sentence become a calendar entry |
| [JMAP](internals/jmap.md) | What is implemented, what is deliberately not, and the id spaces |
| [Security model](internals/security-model.md) | Encryption at rest, the secrets file, tokens, and what a public link can reach |

Client authors should also read
[Client development](CLIENT_DEVELOPMENT.md), which is the protocol-level reference.

---

## Conventions in these pages

- **Commands** are given as you would run them against a Compose deployment:
  `docker compose exec php php bin/console <command>`. Drop the prefix if you run plMail
  directly.
- **Settings paths** are written as `Settings → Calendar → Connected calendars`.
- **A "things that bite" section** at the end of a page collects the traps — the failure
  whose cause is not obvious from the symptom. They are there because they happened.
