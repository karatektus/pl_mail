# Single sign-on (OpenID Connect)

plMail can let people sign in through an identity provider you already run — Authentik, Keycloak,
Authelia, Zitadel, or anything else that speaks OpenID Connect. The login page then shows a
**Sign in with …** button above the password form; the password form stays.

This is about signing in to plMail itself. Connecting a Gmail or Outlook *mailbox* is a different
OAuth flow, on [Google](google.md) and [Microsoft](microsoft.md).

## What it does

- The authorisation code flow, with PKCE, a `state` and a `nonce`. The provider's endpoints are read
  from its discovery document, so the only address you configure is the issuer.
- An account is matched to the provider's identity by its **issuer and subject** (`sub`), which is
  stored on the account the first time that person signs in. Changing the address at the provider
  afterwards changes nothing here.
- The first sign-in finds the account by **email address** — and only if the provider says it has
  verified that address (`email_verified: true`). See **Only link an address the provider has
  verified** below.
- With **Create an account for people plMail has not seen** switched on, somebody the provider lets
  in and plMail has never seen gets a new, ordinary (non-admin) account, named from the provider's
  `given_name`/`family_name`. Without it, an administrator creates the account first under **Admin →
  Users**, with the same address.
- Everything that applies to a password sign-in applies here: a suspended account is refused, the
  login throttle counts attempts, and an account with two-factor switched on is asked for its code.

## Setting it up in Authentik

1. **Applications → Providers → Create → OAuth2/OpenID Provider.**
   - *Client type*: **Confidential**.
   - *Redirect URIs*: **Strict**, `https://<your plMail address>/login/oidc/callback` — the address
     people type to reach plMail, the one in `APP_PUBLIC_URL`.
   - *Scopes*: leave the default `openid`, `email` and `profile` mappings selected.
   - Anything else can stay at its default.
2. **Applications → Applications → Create**, pick the provider you just made, and give it a slug —
   `plmail`, say. Bind a group or policy to it if not everybody in Authentik should get in; Authentik
   then refuses the others before they ever reach plMail.
3. From the provider's page, copy the **Client ID**, the **Client Secret** and the **OpenID
   Configuration Issuer** — something like `https://auth.example.com/application/o/plmail/`.
4. In plMail, open **Admin → SSO**, paste the three in, give the button the provider's name, and
   **Save**. It takes effect at once — no restart — and the page's chip says **On**.

Keycloak and the rest are the same four facts under different names: a confidential client, the
redirect URI above, and the realm's issuer URL.

## The settings

Everything is on **Admin → SSO**. The same page shows the redirect URI to register at the provider,
with a button to copy it.

| Setting | What it does | Default |
|---|---|---|
| **Issuer** | The provider's issuer URL, exactly as its discovery document states it. Must be `https://`. | empty |
| **Client ID** | The client id the provider issued. | empty |
| **Client secret** | The client secret. Write-only: the page never shows a stored one, and leaving the field empty keeps it. | empty |
| **Name on the button** | The provider's name on the login button — "Sign in with Authentik". Empty says "single sign-on". | empty |
| **Scopes** | Space-separated scopes. `openid` is always added. | `openid email profile` |
| **Create an account for people plMail has not seen** | Whether somebody the provider lets in, and plMail has never seen, gets a new ordinary account. | off |
| **Only link an address the provider has verified** | Link an existing account by address only when the provider vouches for the address. | on |
| **Single sign-on only** | Hide the password form and refuse password sign-ins, so everybody comes in through the provider. See [Making single sign-on the only way in](#making-single-sign-on-the-only-way-in). | off |

Issuer, client ID and secret are saved together or not at all: the form refuses two of the three,
because half a provider would look set up here while nothing signed anybody in.

### From the environment instead

An install that manages its configuration in a compose file can set the same things there, and they
are used whenever Admin → SSO holds no complete provider. `compose.yaml` passes them through from
your environment.

| Variable | Setting | Default |
|---|---|---|
| `OIDC_ISSUER` | Issuer | blank (off) |
| `OIDC_CLIENT_ID` | Client ID | blank (off) |
| `OIDC_CLIENT_SECRET` | Client secret | blank (off) |
| `OIDC_BUTTON_LABEL` | Name on the button | blank |
| `OIDC_SCOPES` | Scopes | `openid email profile` |
| `OIDC_AUTO_CREATE` | Create an account for people plMail has not seen | `false` |
| `OIDC_REQUIRE_VERIFIED_EMAIL` | Only link an address the provider has verified. Only `false`, `0`, `no` or `off` switch it off; blank or anything else leaves it on. | `true` |
| `OIDC_DISABLE_PASSWORD_LOGIN` | Single sign-on only. Only `true`, `1`, `yes` or `on` switch it on; blank or anything else leaves the password form. | `false` |

**What is saved under Admin → SSO wins**, and it wins whole: a saved provider uses its own options
too and reads nothing from these variables. The chip on the page says which is in force — **On**,
**On, from the environment**, or **Off**. To hand the decision back to the environment, empty the
issuer and the client ID and tick **Forget the stored client secret**.

## Making single sign-on the only way in

By default the password form stays beside the provider's button. If everybody should come in through
the provider — a family or a team where the provider already holds the accounts and the second
factor — tick **Single sign-on only** on **Admin → SSO**. The login page then shows only the
button, and the server refuses a password sign-in outright: posting the right password to the login
URL gets the same "turned off" answer as a wrong one, for an address that exists and for one that
does not.

- **It needs a whole provider saved on that page**, and **you must have signed in through the
  provider yourself at least once** before it lets you tick the box. Otherwise the first
  administrator to try it would shut themselves out of a provider they had never used.
- **Accounts that have never signed in through the provider can still do so.** Their first sign-in
  links the account by email address. That works only if the provider has a user with exactly that
  address and reports it as verified; an account whose address the provider does not know has no way
  in once the box is ticked, and neither does somebody with no account here when auto-creation is off.
- **It is in force only while single sign-on is on.** Take the provider away, or leave it half
  filled, and the password form comes back by itself — an install can never be left with no way in
  because of a provider that is not there.
- **If the provider is down**, nothing in a browser can bring the form back, on purpose. From the
  console:

  ```bash
  docker compose exec php php bin/console app:sso:allow-password-login
  ```

  That clears what Admin → SSO saved. If you set `OIDC_DISABLE_PASSWORD_LOGIN` in the environment,
  change it there and restart.
- **What it does not touch:** app passwords for mail clients and the Android and iOS apps (JMAP and
  CalDAV) keep working, and so do browser sessions that are already open. Passwords stay in the
  database, so switching the setting off again needs no reset. Remove a person's access at the
  provider *and* deactivate their account under Admin → Users to end sessions that are already open.

## Things that bite

**"Your sign-in provider has not confirmed your email address."** Authentik's default `email` scope
mapping has not always said `email_verified: true`, depending on the version. Either give the
provider a scope mapping of your own that returns `"email_verified": True` for addresses your
administrators set, or — if people cannot edit their own address in Authentik — switch **Only link
an address the provider has verified** off. Do not turn it off on a provider where anybody can
change their own address: they could then claim any plMail account that has not signed in through
the provider yet, administrators included.

**The provider says the redirect URI does not match.** plMail builds the callback address from the
request it is serving. Behind a reverse proxy that does not pass `X-Forwarded-Proto`, or with
`TRUSTED_PROXIES` too narrow to believe it, that address starts with `http://` and the provider
refuses it. See [Behind a reverse proxy](../install/reverse-proxy.md).

**The issuer has to be `https://`.** The ID token is trusted because it arrives over TLS directly from
the provider's token endpoint, so a provider on plain HTTP could be impersonated by anybody on the
path. plMail refuses it and the button reports single sign-on as unavailable; the log says why. For
the same reason plMail follows no redirects from the provider: a discovery document or a token
endpoint that answers with one is treated as unavailable, so give the issuer as the address the
provider actually serves from.

**"The account with your email address is already linked to someone else."** The account is linked
to a subject, not an address, and somebody deleted at the provider and added again comes back with a
new subject — Authentik derives it from its own user id. plMail then sees a second person claiming a
linked account and refuses, which is the right answer when it really is a second person. When it is
not, an operator forgets the old link from the console, and the next sign-in links the account again:

```bash
docker compose exec php php bin/console app:user:oidc-unlink you@example.com
```

**Whoever can open Admin → SSO decides who can sign in.** An administrator can point plMail at a
provider of their own, and a provider says who somebody is: with the issuer changed, every account
is linked again by address on its next sign-in, to whatever that provider claims. So an
administrator — or anybody holding an administrator's session — can sign in as any account that has
no second factor of its own. Two-factor authentication on an account still stops it, because the
code is asked for after the provider has answered. This is the price of setting single sign-on up
from the browser; give administrator accounts a second factor, and see the
[security model](../internals/security-model.md).

**An administrator can reach an account that has not been linked yet.** Admin → Users can change an
account's address, and the first sign-in through the provider finds an account by its address. So an
administrator who also holds a second login at the provider can point somebody's unlinked account at
that login's address and sign in to it — the one place single sign-on gives an administrator's
browser session a way into another person's mail. It needs a provider that lets them have that second
login, and it changes the address the victim signs in with, so it does not go unnoticed. Once an
account has signed in through the provider it is linked and the address no longer matters. Where
administrators are not the people who run the provider, let the provider create its users rather
than letting them register themselves.

**Signing out of plMail does not sign you out of the provider.** Pressing the button again signs
you straight back in without a prompt, because the provider still remembers you.

**Two-factor is asked twice** if an account has it switched on here as well as at the provider.
Switch it off under **Settings → Security** if the provider's is enough.

**An account created by the provider has no password.** It signs in through the provider only, and
[app passwords](../features/security.md#app-passwords) for JMAP and CalDAV clients work as for
anybody. It cannot set a password under **Settings → Security** either, because that form asks for
the current one; an operator gives it one with `app:user:password` on the console if one is wanted.

**Switching to a different provider relinks by address.** The link remembers which issuer it was
made with, so a new issuer never matches an old subject; each person's next sign-in links their
account again by its (verified) address. A [configuration backup](../install/config-backup.md) does
not carry the links either, and they come back the same way.

**Discovery is cached for an hour.** A provider whose endpoints changed is picked up within the hour,
or at once after `bin/console cache:pool:clear cache.app`.
