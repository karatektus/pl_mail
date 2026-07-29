<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Domain\Enum\Integration\AuthKind;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Repository\IntegrationProviderConfigRepository;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Provider\Google;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use TheNetworg\OAuth2\Client\Provider\Azure;

/**
 * Builds a configured league OAuth2 provider for an integration.
 *
 * The only place that knows concrete league provider classes for integrations,
 * mirroring OAuthProviderFactory's role for mail accounts. Kept separate from
 * it because the two read their credentials from different places — mail OAuth
 * from env vars, integrations from the admin-editable provider config — and
 * because they generate different redirect URIs.
 *
 * Credentials come from the database, so "not configured" is a normal runtime
 * state rather than a deployment error, and it surfaces as an
 * IntegrationException the user can act on.
 */
final readonly class IntegrationOAuthProviderFactory
{
    public function __construct(
        private IntegrationProviderConfigRepository $configRepository,
        private UrlGeneratorInterface              $urlGenerator,
    ) {
    }

    /**
     * @throws IntegrationException if the provider is not an OAuth one, or an
     *                              admin has not filled in its credentials
     */
    public function create(Provider $provider): AbstractProvider
    {
        if (AuthKind::OAuth2 !== $provider->authKind()) {
            throw new IntegrationException(sprintf(
                '%s does not use OAuth.',
                $provider->label(),
            ));
        }

        $config = $this->configRepository->findOneByProvider($provider);

        if (null === $config || null === $config->clientId || null === $config->clientSecret) {
            throw new IntegrationException(sprintf(
                '%s is not set up yet — an administrator needs to add its credentials.',
                $provider->label(),
            ));
        }

        $options = [
            'clientId'     => $config->clientId,
            'clientSecret' => $config->clientSecret,
            'redirectUri'  => $this->redirectUri($provider),
        ];

        return match ($provider) {
            Provider::GoogleDrive, Provider::GooglePhotos => new Google($options + ['accessType' => 'offline']),
            Provider::OneDrive => $this->azure($options, $config->settings['tenant'] ?? 'common'),
            default            => new GenericProvider($options + $this->endpoints($provider)),
        };
    }

    /**
     * The registered return address. Generated from the route so it cannot
     * drift from where the callback actually lives — and it is the one value
     * that cannot be changed after registration without breaking every
     * existing connection, which is why the admin form renders this same URL
     * for copying rather than asking anyone to type it.
     */
    public function redirectUri(Provider $provider): string
    {
        return $this->urlGenerator->generate(
            'app_integration_oauth_callback',
            ['provider' => $provider->value],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $options
     */
    private function azure(array $options, mixed $tenant): Azure
    {
        $azure = new Azure($options + [
            'tenant'                 => is_string($tenant) && '' !== $tenant ? $tenant : 'common',
            // v1.0 does not understand offline_access and issues no refresh
            // token for Graph scopes — the same trap OAuthProviderFactory
            // documents for mail.
            'defaultEndPointVersion' => Azure::ENDPOINT_VERSION_2_0,
        ]);

        $azure->urlAPI = 'https://graph.microsoft.com/v1.0/';
        $azure->resource = 'https://graph.microsoft.com/';
        $azure->authWithResource = false;

        return $azure;
    }

    /**
     * @return array{urlAuthorize:string,urlAccessToken:string,urlResourceOwnerDetails:string}
     *
     * @throws IntegrationException
     */
    private function endpoints(Provider $provider): array
    {
        $endpoints = $provider->oauthEndpoints();

        if (null === $endpoints) {
            throw new IntegrationException(sprintf(
                'No OAuth endpoints are known for %s.',
                $provider->label(),
            ));
        }

        return $endpoints;
    }
}
