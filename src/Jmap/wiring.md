# Wiring — applied 2026-07-26

Record of what was changed outside `src/Jmap/` to bring this online. All six
steps are done; kept as a map of the touch points.

Two steps beyond the original four were needed:

- **Doctrine mapping (step 5)** — the `App` mapping only covers `src/Entity`,
  so `App\Jmap\State\ChangeLog` was invisible to the ORM until a second mapping
  was registered.
- **Lexik was not installed (step 3)** — the bundle, keypair, and passphrase
  handling had to be set up, not just the firewall.

## 1. Routing — register the attribute routes

`config/routes.yaml` (append):

```yaml
jmap:
    resource:
        path: ../src/Jmap/Controller/
        namespace: App\Jmap\Controller
    type: attribute
```

## 2. Service wiring — tag the method handlers

`config/services.yaml`, under `services:` (the `_instanceof` block can be merged
with any you already have):

```yaml
    _instanceof:
        App\Jmap\Method\JmapMethod:
            tags: ['app.jmap_method']

    App\Jmap\Method\MethodRegistry:
        arguments:
            $methods: !tagged_iterator app.jmap_method
```

Everything else in `src/Jmap/` is autowired by the default `App\` resource,
provided your `services.yaml` autoconfigures `../src/` (the Symfony default). If
your `App\` resource excludes non-`Entity`/`Controller` dirs, make sure
`src/Jmap/` is not excluded.

## 3. Security — bearer-token firewall over /jmap

LexikJWTAuthenticationBundle was installed for this (`composer require
lexik/jwt-authentication-bundle`, then `lexik:jwt:generate-keypair`). The recipe
writes a generated `JWT_PASSPHRASE` into the tracked `.env`; it was moved to
`.env.local` and left blank in `.env`. `config/jwt/*.pem` is gitignored by the
recipe. **Any other environment needs its own keypair + matching passphrase.**

`config/packages/security.yaml` (firewalls, ordered before your web firewall):

```yaml
    firewalls:
        jmap:
            pattern: ^/(jmap|\.well-known/jmap)
            stateless: true
            provider: app_user_provider
            jwt: ~

        # ... your existing web firewall below ...

    access_control:
        - { path: ^/\.well-known/jmap, roles: IS_AUTHENTICATED_FULLY }
        - { path: ^/jmap, roles: IS_AUTHENTICATED_FULLY }
```

The QR-login endpoints (`/auth/qr/*`, to be built) sit on their own rules:
`create` and `confirm` require the authenticated web session; `claim` and the
token-issuing exchange are anonymous but nonce-gated.

## 4. Schema — create the change-log table

`Version20260726155733` creates `jmap_change_log`; already migrated.

## 5. Doctrine — map the App\Jmap\State entities

`ChangeLog` lives outside `src/Entity`, which is the only directory the `App`
mapping covers. `config/packages/doctrine.yaml`, under `orm.mappings`:

```yaml
            Jmap:
                type: attribute
                is_bundle: false
                dir: '%kernel.project_dir%/src/Jmap/State'
                prefix: 'App\Jmap\State'
                alias: Jmap
```

## 6. Verified

`Core/echo` round-trips, `/jmap/session` lists all three connected accounts,
and the negative paths return the right envelopes: 401 without a bearer token,
`unknownCapability` for an unadvertised `using`, `notJSON` for a malformed body,
and an inline `unknownMethod` error that does not abort later calls in the list.
