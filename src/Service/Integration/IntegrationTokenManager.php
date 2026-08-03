<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Repository\Integration\IntegrationRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Throwable;

/**
 * The stored OAuth credential for an integration, from first authorisation to
 * every renewal after it.
 *
 * Every OAuth driver calls getValidAccessToken() and none of them touches the
 * refresh flow, exactly as OAuthTokenManager does for mail accounts. Keeping
 * the two apart rather than generalising one: they read different entities,
 * different credential sources and different provider enums, and the shared
 * part is a dozen lines of expiry arithmetic.
 *
 * A failed refresh is recorded on the Integration before being rethrown, so
 * the settings list can say "reconnect this" instead of the user discovering
 * it mid-compose.
 */
final readonly class IntegrationTokenManager
{
    /**
     * Refresh this far ahead of the stated expiry, so a token that passes the
     * check is still valid by the time the request lands.
     */
    private const int EXPIRY_BUFFER_SECONDS = 120;

    public function __construct(
        private IntegrationOAuthProviderFactory $providerFactory,
        private EntityManagerInterface          $em,
        private IntegrationRepository           $integrations,
    ) {
    }

    /**
     * Find-or-create the connection and write a just-authorised token onto it.
     *
     * Reconnecting updates the existing row rather than adding a second one:
     * a SaaS provider has exactly one account per user from our side, so a
     * repeat authorisation is a token refresh by another route — and creating a
     * duplicate would leave filters pointing at the stale one.
     */
    public function storeAuthorization(
        User                 $user,
        Provider             $provider,
        AccessTokenInterface $token,
    ): Integration {
        $integration = $this->integrations->findOneByProviderForUser($user, $provider);

        if (null === $integration) {
            $integration = new Integration($user, $provider, $provider->label());
            $this->em->persist($integration);
        }

        $integration->oauthAccessToken = $token->getToken();

        $expires = $token->getExpires();

        if (null !== $expires) {
            $integration->oauthTokenExpiry = new DateTimeImmutable()->setTimestamp($expires);
        }

        // Absent on a re-consent that Google decides not to re-issue for. The
        // stored one is still good, so it must not be cleared.
        $refresh = $token->getRefreshToken();

        if (null !== $refresh && '' !== $refresh) {
            $integration->oauthRefreshToken = $refresh;
        }

        $integration->isActive = true;
        $integration->recordSuccess();

        $this->em->flush();

        return $integration;
    }

    /**
     * @throws IntegrationException
     */
    public function getValidAccessToken(Integration $integration): string
    {
        $token = $integration->oauthAccessToken;

        if (null !== $token && '' !== $token && false === $this->isExpiring($integration)) {
            return $token;
        }

        return $this->refresh($integration);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function isExpiring(Integration $integration): bool
    {
        $expiry = $integration->oauthTokenExpiry;

        if (null === $expiry) {
            // No stated expiry. Treated as expiring so the first use refreshes
            // rather than sending a token that may already be dead — Dropbox
            // long-lived tokens are the only case this costs, and they refresh
            // cheaply.
            return true;
        }

        return $expiry <= new DateTimeImmutable(sprintf('+%d seconds', self::EXPIRY_BUFFER_SECONDS));
    }

    /**
     * @throws IntegrationException
     */
    private function refresh(Integration $integration): string
    {
        $refreshToken = $integration->oauthRefreshToken;

        if (null === $refreshToken || '' === $refreshToken) {
            throw new IntegrationException(sprintf(
                '%s needs to be reconnected — there is no refresh token on file.',
                $integration->provider->label(),
            ));
        }

        try {
            // Building the provider is inside the try on purpose: an admin who
            // clears the client credentials makes this throw, and that failure
            // has to reach the connection's lastError like any other. Otherwise
            // the settings list goes on claiming the connection is healthy
            // while nothing using it works.
            $provider = $this->providerFactory->create($integration->provider);

            $new = $provider->getAccessToken('refresh_token', ['refresh_token' => $refreshToken]);
        } catch (Throwable $e) {
            $integration->recordFailure(sprintf(
                'Could not renew access to %s: %s',
                $integration->provider->label(),
                $e->getMessage(),
            ));
            $this->em->flush();

            throw new IntegrationException(
                sprintf('%s needs to be reconnected.', $integration->provider->label()),
                0,
                $e,
            );
        }

        $integration->oauthAccessToken = $new->getToken();

        $expires = $new->getExpires();

        if (null !== $expires) {
            $integration->oauthTokenExpiry = new DateTimeImmutable()->setTimestamp($expires);
        }

        // Providers that rotate refresh tokens return a new one; those that do
        // not omit it, and the stored one stays valid. Overwriting with null
        // would break every later refresh.
        $rotated = $new->getRefreshToken();

        if (null !== $rotated && '' !== $rotated) {
            $integration->oauthRefreshToken = $rotated;
        }

        $integration->recordSuccess();
        $this->em->flush();

        return $new->getToken();
    }
}
