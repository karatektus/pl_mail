<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Domain\Enum\MailProvider;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Google;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use TheNetworg\OAuth2\Client\Provider\Azure;

/**
 * Builds a configured league OAuth2 provider for a given MailProvider.
 *
 * This is the only place that knows about concrete league provider classes.
 */
class OAuthProviderFactory
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $googleClientId,
        private readonly string $googleClientSecret,
        private readonly string $microsoftClientId,
        private readonly string $microsoftClientSecret,
        private readonly string $microsoftTenant,
    ) {
    }

    public function create(MailProvider $provider): AbstractProvider
    {
        $redirectUri = $this->urlGenerator->generate(
            'app_oauth_callback',
            ['provider' => $provider->value],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        if (MailProvider::Google === $provider) {
            return new Google([
                'clientId'     => $this->googleClientId,
                'clientSecret' => $this->googleClientSecret,
                'redirectUri'  => $redirectUri,
                'accessType'   => 'offline',
            ]);
        }

        if (MailProvider::Microsoft === $provider) {
            return $this->createAzure($redirectUri);
        }

        throw new \RuntimeException(sprintf(
            'OAuth provider "%s" is not yet implemented.',
            $provider->value,
        ));
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * `defaultEndPointVersion = 2.0` is mandatory: the v1.0 endpoint does not
     * understand `offline_access` and will not issue refresh tokens for the
     * Graph scopes we request.
     *
     * `tenant` is configurable because it decides which accounts can connect:
     *   - `common`        → work/school AND personal Microsoft accounts
     *   - `organizations` → work/school only (avoids the whole consumer-account
     *                       edge-case surface: no immutable IDs, reduced $filter)
     *   - `consumers`     → personal only
     *   - a tenant GUID   → single-tenant
     *
     * Using `common` against a single-tenant app registration produces
     * AADSTS50194 at consent time — the app registration's supported-account
     * type must match this value.
     */
    private function createAzure(string $redirectUri): Azure
    {
        $azure = new Azure([
            'clientId'               => $this->microsoftClientId,
            'clientSecret'           => $this->microsoftClientSecret,
            'redirectUri'            => $redirectUri,
            'tenant'                 => $this->microsoftTenant,
            'defaultEndPointVersion' => Azure::ENDPOINT_VERSION_2_0,
        ]);

        // The provider defaults to the Azure AD Graph resource; point it at
        // Microsoft Graph so `getResourceOwner()` hits /me on the right API.
        $azure->urlAPI       = 'https://graph.microsoft.com/v1.0/';
        $azure->resource     = 'https://graph.microsoft.com/';
        $azure->authWithResource = false;

        return $azure;
    }
}
