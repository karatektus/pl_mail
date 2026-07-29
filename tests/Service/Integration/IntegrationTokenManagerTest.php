<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration;

use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration;
use App\Entity\IntegrationProviderConfig;
use App\Entity\User;
use App\Repository\IntegrationProviderConfigRepository;
use App\Service\Integration\IntegrationOAuthProviderFactory;
use App\Service\Integration\IntegrationTokenManager;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
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

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function manager(?IntegrationProviderConfig $config): IntegrationTokenManager
    {
        $configRepository = $this->createStub(IntegrationProviderConfigRepository::class);
        $configRepository->method('findOneByProvider')->willReturn($config);

        return new IntegrationTokenManager(
            new IntegrationOAuthProviderFactory(
                $configRepository,
                $this->createStub(UrlGeneratorInterface::class),
            ),
            $this->createStub(EntityManagerInterface::class),
        );
    }

    private function integration(): Integration
    {
        return new Integration(new User(), Provider::Dropbox, 'Dropbox');
    }
}
