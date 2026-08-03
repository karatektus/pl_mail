<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration;

use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration\Integration;
use App\Entity\Integration\IntegrationProviderConfig;
use App\Entity\User\User;
use App\Repository\Integration\IntegrationProviderConfigRepository;
use App\Repository\Integration\IntegrationRepository;
use App\Service\Integration\IntegrationOAuthProviderFactory;
use App\Service\Integration\IntegrationTokenManager;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Client\Token\AccessTokenInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Token lifecycle for OAuth integrations.
 *
 * The two behaviours that break things silently if got wrong: a provider that
 * does not rotate its refresh token must not have the stored one wiped, and a
 * refresh that fails has to leave a reason on the connection rather than only
 * throwing — otherwise the settings list keeps claiming the connection is fine.
 */
final class IntegrationTokenManagerTest extends TestCase
{
    public function testAValidStoredTokenIsUsedWithoutRefreshing(): void
    {
        $integration = $this->integration();
        $integration->oauthAccessToken = 'still-good';
        $integration->oauthTokenExpiry = new DateTimeImmutable('+1 hour');

        // No provider config exists, so any refresh attempt would throw — which
        // is what makes this a real assertion that none happened.
        self::assertSame('still-good', $this->manager(null)->getValidAccessToken($integration));
    }

    public function testATokenInsideTheExpiryBufferIsTreatedAsExpired(): void
    {
        $integration = $this->integration();
        $integration->oauthAccessToken = 'about-to-die';
        // Inside the 120s buffer: valid now, probably not by the time a request
        // lands, so it must refresh rather than be used.
        $integration->oauthTokenExpiry = new DateTimeImmutable('+30 seconds');
        $integration->oauthRefreshToken = 'refresh-me';

        $this->expectException(IntegrationException::class);

        // Refresh is attempted and fails because the provider is unconfigured.
        $this->manager(null)->getValidAccessToken($integration);
    }

    public function testAConnectionWithNoRefreshTokenAsksToBeReconnected(): void
    {
        $integration = $this->integration();
        $integration->oauthAccessToken = 'expired';
        $integration->oauthTokenExpiry = new DateTimeImmutable('-1 hour');

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessageMatches('/reconnected/');

        $this->manager(null)->getValidAccessToken($integration);
    }

    public function testAnUnconfiguredProviderSaysAnAdminMustActRatherThanFailingObscurely(): void
    {
        $integration = $this->integration();
        $integration->oauthRefreshToken = 'refresh-me';

        try {
            $this->manager(null)->getValidAccessToken($integration);
            self::fail('expected an IntegrationException');
        } catch (IntegrationException $e) {
            self::assertMatchesRegularExpression('/reconnected|administrator/', $e->getMessage());
        }

        // The failure is recorded where the settings list will show it.
        self::assertFalse($integration->isHealthy());
    }

    public function testAMissingExpiryIsTreatedAsExpiring(): void
    {
        // Dropbox long-lived tokens report no expiry. Refreshing on first use
        // is cheap; sending a token that may already be dead is not.
        $integration = $this->integration();
        $integration->oauthAccessToken = 'unknown-expiry';
        $integration->oauthRefreshToken = 'refresh-me';
        $integration->oauthTokenExpiry = null;

        $this->expectException(IntegrationException::class);

        $this->manager(null)->getValidAccessToken($integration);
    }

    // ── storing a fresh authorisation ─────────────────────────────────────────

    /**
     * Re-authorising an existing connection has to land on the same row. A
     * second one would leave every filter and every saved folder pointing at
     * the stale connection, which still looks healthy in the list.
     */
    public function testReconnectingUpdatesTheExistingConnection(): void
    {
        $existing = $this->integration();
        $existing->oauthAccessToken = 'old';

        $manager = $this->manager(null, $existing);

        $stored = $manager->storeAuthorization(
            new User(),
            Provider::Dropbox,
            $this->token('fresh', 'rotated', time() + 3600),
        );

        self::assertSame($existing, $stored);
        self::assertSame('fresh', $stored->oauthAccessToken);
        self::assertSame('rotated', $stored->oauthRefreshToken);
    }

    /**
     * Google does not re-issue a refresh token on a re-consent it considers
     * unnecessary. The stored one is still good, and clearing it would break
     * every later refresh — the connection would work until its access token
     * expired and then need reconnecting by hand.
     */
    public function testARefreshTokenTheProviderDidNotResendIsKept(): void
    {
        $existing = $this->integration();
        $existing->oauthRefreshToken = 'still-valid';

        $manager = $this->manager(null, $existing);

        $stored = $manager->storeAuthorization(new User(), Provider::Dropbox, $this->token('fresh', null, null));

        self::assertSame('still-valid', $stored->oauthRefreshToken);
    }

    /**
     * A connection paused or failed before being re-authorised comes back
     * usable: the whole point of reconnecting is that it works again.
     */
    public function testAFreshAuthorisationReactivatesAndClearsTheError(): void
    {
        $existing = $this->integration();
        $existing->isActive = false;
        $existing->recordFailure('needs reconnecting');

        $stored = $this->manager(null, $existing)
            ->storeAuthorization(new User(), Provider::Dropbox, $this->token('fresh', 'r', null));

        self::assertTrue($stored->isActive);
        self::assertTrue($stored->isHealthy());
    }

    /** A first authorisation has nothing to find, so it creates the row. */
    public function testAFirstAuthorisationCreatesTheConnection(): void
    {
        $user = new User();

        $stored = $this->manager(null, null)
            ->storeAuthorization($user, Provider::Dropbox, $this->token('fresh', 'r', null));

        self::assertSame($user, $stored->usr);
        self::assertSame(Provider::Dropbox, $stored->provider);
        self::assertSame('fresh', $stored->oauthAccessToken);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function token(string $access, ?string $refresh, ?int $expires): AccessTokenInterface
    {
        $token = $this->createStub(AccessTokenInterface::class);
        $token->method('getToken')->willReturn($access);
        $token->method('getRefreshToken')->willReturn($refresh);
        $token->method('getExpires')->willReturn($expires);

        return $token;
    }

    private function manager(
        ?IntegrationProviderConfig $config,
        ?Integration $existing = null,
    ): IntegrationTokenManager {
        $configRepository = $this->createStub(IntegrationProviderConfigRepository::class);
        $configRepository->method('findOneByProvider')->willReturn($config);

        $integrations = $this->createStub(IntegrationRepository::class);
        $integrations->method('findOneByProviderForUser')->willReturn($existing);

        return new IntegrationTokenManager(
            new IntegrationOAuthProviderFactory(
                $configRepository,
                $this->createStub(UrlGeneratorInterface::class),
            ),
            $this->createStub(EntityManagerInterface::class),
            $integrations,
        );
    }

    private function integration(): Integration
    {
        return new Integration(new User(), Provider::Dropbox, 'Dropbox');
    }
}
