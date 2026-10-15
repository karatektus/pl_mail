<?php

declare(strict_types=1);

namespace App\Service\Oidc;

/**
 * How this install signs people in through an OpenID Connect provider, if it
 * does — Authentik, Keycloak, Authelia, Zitadel and the like.
 *
 * A value, not a service: OidcSettings builds one per call from whichever of
 * Admin → SSO and the environment is in force, and everything that signs
 * somebody in asks it rather than holding one of these.
 *
 * Off unless all three of issuer, client id and secret are set. A half-filled
 * configuration shows no button rather than one that fails at the provider.
 */
final readonly class OidcConfiguration
{
    /** @var list<string> */
    public array $scopes;

    public function __construct(
        public string $issuer,
        public string $clientId,
        #[\SensitiveParameter]
        public string $clientSecret,
        public string $buttonLabel,
        public bool   $autoCreate,
        public bool   $requireVerifiedEmail,
        string        $scopes,
    ) {
        $requested = preg_split('/[\s,]+/', $scopes, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // `openid` is what makes the request an OpenID Connect one at all —
        // without it the provider answers with a plain OAuth token and no ID
        // token, and the failure reads as the provider misbehaving. So it is
        // added rather than required of whoever writes the setting.
        $this->scopes = array_values(array_unique(['openid', ...$requested]));
    }

    public function isEnabled(): bool
    {
        return '' !== $this->issuer && '' !== $this->clientId && '' !== $this->clientSecret;
    }
}
