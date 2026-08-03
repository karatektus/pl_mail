<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Domain\Enum\Account\MailProvider;
use App\Entity\Integration\MailProviderConfig;
use App\Repository\Integration\MailProviderConfigRepository;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Google;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use TheNetworg\OAuth2\Client\Provider\Azure;

/**
 * Builds a configured league OAuth2 provider for a given MailProvider.
 *
 * This is the only place that knows about concrete league provider classes.
 *
 * Credentials come from the database if an admin has entered them, and from the
 * environment otherwise. That order, and not the reverse: an admin editing the
 * value in the UI expects it to take effect, and an installation that has only
 * ever used .env keeps working with no migration and no restart. The env values
 * stay the documented way to seed a fresh deployment.
 */
class OAuthProviderFactory
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly MailProviderConfigRepository $configRepository,
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

        $config = $this->configRepository->findOneByProvider($provider);

        if (MailProvider::Google === $provider) {
            return new Google([
                'clientId'     => $this->clientId($provider, $config),
                'clientSecret' => $this->clientSecret($provider, $config),
                'redirectUri'  => $redirectUri,
                'accessType'   => 'offline',
            ]);
        }

        if (MailProvider::Microsoft === $provider) {
            return $this->createAzure($redirectUri, $config);
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
    private function createAzure(string $redirectUri, ?MailProviderConfig $config): Azure
    {
        $tenant = $config?->tenant;

        $azure = new Azure([
            'clientId'               => $this->clientId(MailProvider::Microsoft, $config),
            'clientSecret'           => $this->clientSecret(MailProvider::Microsoft, $config),
            'redirectUri'            => $redirectUri,
            'tenant'                 => $tenant ?? $this->microsoftTenant,
            'defaultEndPointVersion' => Azure::ENDPOINT_VERSION_2_0,
        ]);

        // The provider defaults to the Azure AD Graph resource; point it at
        // Microsoft Graph so `getResourceOwner()` hits /me on the right API.
        $azure->urlAPI       = 'https://graph.microsoft.com/v1.0/';
        $azure->resource     = 'https://graph.microsoft.com/';
        $azure->authWithResource = false;

        return $azure;
    }

    /**
     * A stored value wins over the environment, but only when it is actually
     * set — a half-filled row must not shadow a working .env.
     */
    private function clientId(MailProvider $provider, ?MailProviderConfig $config): string
    {
        $stored = $config?->clientId;

        if (null !== $stored && '' !== $stored) {
            return $stored;
        }

        return MailProvider::Google === $provider
            ? $this->googleClientId
            : $this->microsoftClientId;
    }

    private function clientSecret(MailProvider $provider, ?MailProviderConfig $config): string
    {
        $stored = $config?->clientSecret;

        if (null !== $stored && '' !== $stored) {
            return $stored;
        }

        return MailProvider::Google === $provider
            ? $this->googleClientSecret
            : $this->microsoftClientSecret;
    }
}
