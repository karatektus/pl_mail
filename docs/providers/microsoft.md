# Microsoft

Outlook.com, Hotmail and Microsoft 365 mailboxes reach plMail through **Microsoft Graph** and an
application you register in the Azure portal. There is no IMAP path for them, and that is
deliberate: Exchange Online classifies IMAP, POP and SMTP as legacy authentication in Conditional
Access, so IMAP with OAuth is blocked outright in any tenant running Security Defaults — which is
the default for new tenants. Graph is the only path that works everywhere, and the only one
Microsoft is still investing in.

The registration is one-time, for the whole installation, and it covers mail *and* calendars. Once
it exists, users add accounts under **Settings → Mail accounts** with no further administration —
see [Accounts and aliases](../features/accounts.md) and
[Connected calendars](../features/calendar-sync.md).

## Registering the application

1. Open [portal.azure.com](https://portal.azure.com) and go to **Microsoft Entra ID → App
   registrations → New registration**. Give it a name; that name is what users see on the consent
   screen and in their Microsoft account's list of connected applications.
2. **Choose who can sign in.** This is the decision that has to agree with plMail's tenant setting,
   and the section below spells out which value pairs with which. *"Accounts in any organizational
   directory and personal Microsoft accounts"* matches the default of `common`.
3. Set the redirect URI platform to **Web** and paste in the address plMail shows you:

   ```
   https://your-domain/oauth/microsoft/callback
   ```

   That is the `/oauth/{provider}/callback` route with `microsoft` as the provider.
   **Admin → Integrations → Mail sign-in → Microsoft mail sign-in** renders the exact URI for your
   installation with a copy button, and the setup wizard shows the same one. Paste it rather than
   typing it: Microsoft matches it exactly, and it cannot be changed later without breaking every
   connection already authorised against it.
4. After registering, copy the **Application (client) ID** from the overview page.
5. Under **Certificates & secrets**, create a client secret and copy its **Value** — not its ID —
   immediately. Azure shows the value once and never again.
6. Under **API permissions**, add the delegated Microsoft Graph permissions listed below.

The client ID and secret go either in **Admin → Integrations → Mail sign-in → Microsoft mail
sign-in**, which stores them in the database, or in `MICROSOFT_OAUTH_CLIENT_ID` and
`MICROSOFT_OAUTH_CLIENT_SECRET` in the environment. A stored value wins over the environment, and
only when it is actually set, so an installation already configured through the environment keeps
working untouched. See the [configuration reference](../install/configuration.md).

## The delegated permissions, and what each one is for

All of these are **delegated** Microsoft Graph permissions — plMail acts as the signed-in user and
never as the application. plMail requests them as a set at consent time, and Microsoft consents to
the set as a whole; unlike Google there is no partial grant to handle.

| Permission | What it is for |
|---|---|
| `offline_access` | Returns a refresh token. Without it the connection works until the first access token expires and then stops. |
| `openid`, `email`, `profile` | Identify the signed-in account, which is what plMail names the account after. |
| `User.Read` | Reads `/me`. It is the first thing `app:graph:diagnose` probes, because identity working while everything else fails localises the problem to the mailbox rather than the credentials. |
| `Mail.ReadWrite` | Folders, messages, attachments, flags, moves and deletes — the mail sync itself. |
| `Mail.Send` | Sending. Separate from the above in Graph's model, so it has to be added separately. |
| `MailboxSettings.ReadWrite` | Master categories, at `/me/outlook/masterCategories`. These are **not** covered by `Mail.ReadWrite`: they live under the Outlook user-settings resource and return `ErrorAccessDenied` without this. ReadWrite rather than Read because plMail creates category definitions when a label is mirrored. |
| `Calendars.ReadWrite` | Calendars on the same account, read and write. |

Two of them are worth understanding before you decide to skip one.

**Without `MailboxSettings.ReadWrite`, mail is entirely healthy and categories are not.** Folders,
messages, attachments and send all work; categories stop syncing in both directions, so labels that
are not folder-backed never appear in Outlook and Outlook's categories never appear in plMail.
`app:graph:diagnose` reports exactly this as a warning rather than a failure, because it is a real
and survivable state.

**Without `Calendars.ReadWrite`, mail keeps working and calendars simply do not appear.** That is
the whole cost, and it is what the admin screen says: *"Nothing to set up here. Add the
Calendars.ReadWrite delegated permission to this app registration — users then get their calendars
with the same sign-in they already use for mail."*

### Calendar is on the same sign-in as mail

There is no separate calendar connection to enable, no second application and no second consent
screen. `Calendars.ReadWrite` is requested on the same grant as the mail permissions, so as an
administrator you tick one more box in this app registration and there is nothing else to
configure anywhere. Read-write rather than read-only because an event edited in plMail is written
back to Outlook, which means creating, updating and deleting on Microsoft's side.

If calendars do not appear for a user, plMail says which of the possible causes it is in
**Settings → Calendars**: the permission may not be on the app registration, or the account may
genuinely have no calendars.

## The tenant value

`MICROSOFT_OAUTH_TENANT` — or the **Tenant** field in **Admin → Integrations → Mail sign-in** —
decides which accounts may sign in. It has to agree with the supported account types you chose when
registering the application.

| Value | Who may sign in | Pairs with |
|---|---|---|
| `common` | Work or school accounts **and** personal Microsoft accounts | *Accounts in any organizational directory and personal Microsoft accounts* |
| `organizations` | Work or school accounts only | *Accounts in any organizational directory* |
| `consumers` | Personal Microsoft accounts only | *Personal Microsoft accounts only* |
| a tenant GUID | One organisation | *Accounts in this organizational directory only* |

The default is `common`. `organizations` is worth choosing if you do not need outlook.com addresses,
because it avoids the consumer-account edge cases entirely — personal accounts have no immutable
ids and reduced `$filter` support, and both surface as odd behaviour rather than as errors.

**A mismatch fails at consent time, not at setup.** Using `common` against an app registration
restricted to a single tenant produces `AADSTS50194`, which arrives as a failed sign-in for the user
long after the administrator has finished and moved on. plMail translates the common Microsoft
error codes into sentences rather than showing the raw code — the one for this case says the account
type cannot sign in with the current configuration and names the setting to check.

## Push, and what it needs

Instant delivery works out of the box here, with no extra cloud resources — the only requirement is
that your public address is HTTPS and actually reachable from the internet, so Microsoft can call
back. See [Behind a reverse proxy](../install/reverse-proxy.md).

Graph proves a subscription is real by **POSTing a `validationToken` to your notification URL and
expecting the raw token echoed back as `text/plain` within ten seconds**, synchronously, inside the
create call. That has a pleasant consequence: a deployment that is not actually reachable fails at
registration, loudly and harmlessly, rather than registering a subscription that then silently never
delivers. The account or calendar stays on polling.

Notification URLs are built from your configured public address, never from the incoming request.
Reverse proxies are the normal deployment, and a URL derived from a request carries an internal
hostname or `http://` after TLS termination — which Graph rejects with a validation error that is
genuinely unpleasant to diagnose.

| | Mail | Calendar |
|---|---|---|
| Resource | `/me/messages` | `me/calendars/{id}/events` |
| Change types | `created,updated,deleted` | `created,updated,deleted` |
| Endpoint | `POST /webhook/graph/notify` | `POST /webhook/graph/calendar` |
| Lifecycle endpoint | `POST /webhook/graph/lifecycle` | — |
| Proof it is genuine | `clientState` in the body | `clientState` in the body |
| Lifetime | just under three days | just under three days |
| Renewal | `PATCH` the expiry | `PATCH` the expiry |
| Registered by | per account, when instant delivery is switched on | per calendar, hourly sweep |

Mail push is opt-in per account: **Settings → Mail accounts → Instant delivery**. If registration
fails the flag is rolled back, so the interface never claims push is on while nothing is being
delivered. Renewal runs nightly with a twelve-hour threshold against a three-day lifetime.

Calendar push is per calendar, because Graph subscribes to a resource and six mirrored calendars are
six resources with six secrets and six expiries. Nothing registers a calendar channel at the moment
you tick a calendar to mirror — a registration is attempted off the request and then, if it did not
take, retried by the hourly `app:calendar:push` sweep. That is deliberate: registration fails for
deployment reasons that have nothing to do with the click, and tied to the subscribe flow alone
those calendars would never get push until somebody unsubscribed and re-subscribed them.

Both notifications are content-free — they say "something changed here" and nothing about what — so
the webhook does one thing, queue a sync for the resource it names, and every decision stays in the
sync engine.

**Push is never load-bearing.** A calendar that could not register is polled every fifteen minutes,
and an account without push is synced on the same schedule. To ask for registration immediately:

```bash
docker compose exec php php bin/console app:calendar:push
```

## OneDrive is a different registration

Attaching files from and saving attachments to OneDrive goes through the same API but needs
permissions the mail registration does not carry — `Files.ReadWrite` and `offline_access` — and its
own redirect URI at `/integrations/oauth/oneDrive/callback`. The admin screen offers to reuse the
mail credentials for it, which copies the client ID and secret across server-side; the copy does
**not** grant the extra permission, so those still have to be added in the app registration. Its
setup steps are in **Admin → Integrations**, on the OneDrive entry.

## Things that bite

**The tenant value and the supported account types must agree**, or consent fails with
`AADSTS50194` — at the user's first sign-in, not at setup. This is the single most common Microsoft
misconfiguration here.

**Copy the secret's Value, not its ID.** Azure shows both in the same table and only the Value is
usable; it is displayed once and cannot be retrieved afterwards, only replaced.

**Adding a permission does not upgrade tokens that already exist.** A user who connected before you
added `Calendars.ReadWrite` or `MailboxSettings.ReadWrite` holds a token that does not carry it, and
no amount of waiting changes that. The account has to be disconnected and reconnected so a fresh
consent issues a new token — `app:graph:diagnose` says so explicitly when it finds the gap.

**Identity working while every mail endpoint fails is not a credentials problem.** It usually means
the Microsoft account has no Outlook mailbox provisioned at all, which happens with accounts created
from an external address. Confirm at outlook.live.com: landing in a setup prompt rather than an
inbox is the tell, and no configuration on this side fixes it.

**Master categories are not covered by `Mail.ReadWrite`.** They are a separate resource and need
`MailboxSettings.ReadWrite`. The symptom is a perfectly healthy account whose labels never reach
Outlook.

**A lapsed Graph subscription cannot be revived.** Microsoft will not extend one that has already
expired; only a fresh subscription works, which is what the renewal sweep creates. This is why the
renewal threshold is twelve hours against a three-day lifetime rather than something tighter.

**`http://` and `localhost` notification URLs are refused**, and plMail checks that locally before
calling Graph so you get one log line naming the missing setting instead of a repeated remote
validation failure.

**The redirect URI cannot be changed after people have connected.** Getting the public address right
before anyone signs in is cheaper than migrating everyone afterwards.
